<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optionale KI-Einordnung für den Graubereich der Einstufung. Standard aus: aktiv nur, wenn die
 * Umgebungsvariable TURNSTILE_AI_KEY gesetzt ist. Jeder Fehler, jede Zeitüberschreitung und ein
 * aufgebrauchtes Tagesbudget ergeben kein Urteil (null) – der Aufrufer behandelt die Einsendung dann als
 * sauber. Die KI darf nur dann zur Ablage führen, wenn sie sich ausdrücklich sicher ist.
 *
 * Übermittelt werden nur Textfelder, Mailadressen und die Namen der Verdachtssignale, keine IP. Der
 * Anbieter ist Auftragsverarbeiter und gehört in die Datenschutzerklärung.
 */
class AiSpamJudge
{
    private const TIMEOUT = 5;
    private const TEXT_MAX = 2000;
    private const DAILY_BUDGET = 150;

    // Gepinnte Modelle, überschreibbar per TURNSTILE_AI_MODEL. Stand 23.09.2026 laut Anbieter-Doku:
    // Mistral Small 4 (docs.mistral.ai, Models Overview), Claude Sonnet 5 (platform.claude.com, Models
    // Overview; Haiku 4.5 wird frühestens am 15.10.2026 abgeschaltet und scheidet deshalb aus).
    private const PROVIDERS = [
        'mistral' => ['https://api.mistral.ai/v1/chat/completions', 'mistral-small-2603'],
        'anthropic' => ['https://api.anthropic.com/v1/messages', 'claude-sonnet-5'],
    ];

    private const INSTRUCTION = 'Du prüfst Einsendungen aus Kontakt-, Anmelde- und Buchungsformularen einer Website. '
        .'Einzige Frage: Ist das Werbeversand, Betrug oder maschinell erzeugter Unsinn – oder eine echte Nachricht '
        .'eines Menschen? Antworte AUSSCHLIESSLICH mit JSON: {"spam": true|false, "sicher": true|false, '
        .'"grund": "<höchstens zehn Wörter>"}. "sicher": true nur, wenn es zweifelsfrei ist; im Zweifel '
        .'"spam": false. Fremde Sprache, Tippfehler, Umgangssprache, kurze Texte, ungewöhnliche Namen oder '
        .'Adressen sind KEIN Spam. Anmeldungen, Terminwünsche und Fragen sind echt. Spam sind Werbeangebote '
        .'(SEO, Links, Webdesign, Kredite, Krypto), Phishing und sinnlose Zeichenfolgen.';

    // '' = unbekannter Anbieter eingetragen: dann keine KI, statt den Schlüssel an einen anderen Anbieter zu senden.
    private readonly string $provider;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        ?string $provider = null,
        private readonly ?string $key = null,
        private readonly ?string $model = null,
    ) {
        $provider = strtolower(trim((string) $provider));
        $this->provider = '' === $provider ? 'mistral' : (isset(self::PROVIDERS[$provider]) ? $provider : '');
    }

    /**
     * @param list<string> $texts
     * @param list<string> $emails
     * @param list<string> $reasons
     *
     * @return array{spam: bool, sure: bool}|null
     */
    public function judge(array $texts, array $emails, array $reasons): ?array
    {
        if ('' === trim((string) $this->key) || '' === $this->provider || !$this->budgetAvailable()) {
            return null;
        }

        $text = mb_substr(implode("\n---\n", $texts), 0, self::TEXT_MAX);
        $prompt = 'E-Mail: '.implode(', ', $emails)."\nVerdachtssignale: ".implode(', ', $reasons)
            ."\n\nFormularinhalt:\n\"\"\"\n".$text."\n\"\"\"";

        try {
            $answer = 'anthropic' === $this->provider ? $this->askAnthropic($prompt) : $this->askMistral($prompt);
        } catch (\Throwable $e) {
            $this->warn('KI-Einordnung nicht erreichbar ('.$this->provider.'): '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }

        if (!preg_match('/\{.*\}/s', $answer, $match)) {
            $this->warn('KI-Einordnung lieferte keine lesbare Antwort ('.$this->provider.').');

            return null;
        }

        $data = json_decode($match[0], true);

        if (!\is_array($data) || !\is_bool($data['spam'] ?? null)) {
            $this->warn('KI-Einordnung lieferte keine lesbare Antwort ('.$this->provider.').');

            return null;
        }

        return ['spam' => $data['spam'], 'sure' => true === ($data['sicher'] ?? false)];
    }

    /**
     * Für die Statusanzeige im Backend, ohne den Schlüssel.
     *
     * @return array{active: bool, provider: string, model: string, used: int, budget: int}
     */
    public function status(): array
    {
        $active = '' !== trim((string) $this->key) && '' !== $this->provider;
        $used = 0;

        try {
            $item = $this->cache->getItem('mandrael_turnstile.ai_budget.'.date('Ymd'));
            $used = $item->isHit() ? (int) $item->get() : 0;
        } catch (\Throwable) {
        }

        return [
            'active' => $active,
            'provider' => $this->provider,
            'model' => '' !== $this->provider ? $this->modelName() : '',
            'used' => $used,
            'budget' => self::DAILY_BUDGET,
        ];
    }

    private function askMistral(string $prompt): string
    {
        $data = $this->post([
            'Authorization' => 'Bearer '.$this->key,
        ], [
            'model' => $this->modelName(),
            'temperature' => 0,
            'max_tokens' => 120,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => self::INSTRUCTION],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    private function askAnthropic(string $prompt): string
    {
        $data = $this->post([
            'x-api-key' => (string) $this->key,
            'anthropic-version' => '2023-06-01',
        ], [
            'model' => $this->modelName(),
            'max_tokens' => 120,
            'temperature' => 0,
            'system' => self::INSTRUCTION,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        return (string) ($data['content'][0]['text'] ?? '');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return array<mixed>
     */
    private function post(array $headers, array $body): array
    {
        $response = $this->httpClient->request('POST', self::PROVIDERS[$this->provider][0], [
            'headers' => $headers,
            'json' => $body,
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
        ]);

        // toArray() wirft bei HTTP ≥ 300 und bei ungültigem JSON; beides fängt judge() ab.
        return $response->toArray();
    }

    private function modelName(): string
    {
        $model = trim((string) $this->model);

        return '' !== $model ? $model : self::PROVIDERS[$this->provider][1];
    }

    /**
     * Tagesbudget, damit eine Bot-Welle weder die Rechnung noch die PHP-Prozesse treibt. Cache-Fehler:
     * kein Budget, also kein Urteil – die Einsendung gilt dann als sauber.
     */
    private function budgetAvailable(): bool
    {
        try {
            $item = $this->cache->getItem('mandrael_turnstile.ai_budget.'.date('Ymd'));
            $used = $item->isHit() ? (int) $item->get() : 0;

            if ($used >= self::DAILY_BUDGET) {
                $this->warn('KI-Einordnung: Tagesbudget aufgebraucht, Einsendungen gelten als sauber.');

                return false;
            }

            $this->cache->save($item->set($used + 1)->expiresAfter(86400));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function warn(string $message): void
    {
        try {
            $this->logger->warning('Cloudflare Turnstile: '.$message, ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]);
        } catch (\Throwable) {
        }
    }
}
