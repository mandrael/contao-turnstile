<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function getSiteKey(): string
    {
        return $this->configValue('turnstileSiteKey');
    }

    public function getSecretKey(): string
    {
        return $this->configValue('turnstileSecretKey');
    }

    private function configValue(string $key): string
    {
        // Zugriff ueber den Framework-Adapter statt statischem Config::get -> testbar/mockbar.
        $this->framework->initialize();

        return trim((string) $this->framework->getAdapter(Config::class)->get($key));
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getSiteKey() && '' !== $this->getSecretKey();
    }

    /**
     * Default an (rueckwaertskompatibel): nur ein explizit leerer Wert (Checkbox abgewaehlt) schaltet
     * remoteip ab; ungesetzt (frische Installation) sendet weiterhin. Hinter NAT/VPN/iCloud Private
     * Relay kann Abschalten sinnvoll sein – CF validiert remoteip nicht strikt (kein IP-Mismatch-Code).
     */
    private function sendRemoteIp(): bool
    {
        $this->framework->initialize();

        return '' !== (string) ($this->framework->getAdapter(Config::class)->get('turnstileSendRemoteIp') ?? '1');
    }

    /**
     * soft-Modus (Bruecke): protokolliert eine durchgelassene, aber fehlgeschlagene Submission auf
     * Level info, damit die Site die Menge (Privacy-Browser-Fehlalarme vs. Bots) per Log auswerten
     * kann. Liegt hier, weil der Logger via DI injiziert ist (FormTurnstile kann monolog.logger.contao
     * nicht ueber den Container holen – nicht public). $category ist 'missing-token' oder
     * 'verification-failed'; nie Token/Secret/PII loggen.
     */
    public function logSoftPass(string $category): void
    {
        $this->logger->info(
            'Cloudflare Turnstile soft-pass: Verifikation fehlgeschlagen, Absenden trotzdem erlaubt ('.$category.').',
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]
        );
    }

    public function validate(?string $token): bool
    {
        if (null === $token || '' === $token) {
            // Hier wird ein stiller Totalausfall sichtbar: kommt gar kein Token an (kaputter
            // Template-Override/Feldname, JS aus), genau EINE Warnung – bewusst warning (nicht info),
            // damit ein flaechiger Ausfall im Prod-Log auffaellt. Abgelehnte Tokens (Bot-Replays)
            // bleiben weiter still, um keine Log-Flut zu erzeugen. Nie das Secret loggen.
            $this->logger->warning(
                'Cloudflare Turnstile: kein Token im Request – Template/Feldname prüfen.',
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]
            );

            return false;
        }

        $payload = [
            'secret' => $this->getSecretKey(),
            'response' => $token,
        ];

        if ($this->sendRemoteIp()) {
            $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp();

            if (null !== $clientIp) {
                $payload['remoteip'] = $clientIp;
            }
        }

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => $payload,
                'timeout' => self::TIMEOUT,
                // timeout ist nur der Idle-Timeout; max_duration deckelt die Gesamtdauer.
                'max_duration' => self::TIMEOUT,
            ]);

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface | DecodingExceptionInterface $e) {
            // Cloudflare nicht erreichbar oder unverwertbare Antwort. Bewusst fail-open, damit ein
            // CF-Ausfall nicht alle Formulare blockiert. Andere Fehler (Code-Bugs) NICHT schlucken.
            // Niemals Secret/$GLOBALS loggen.
            $this->logger->error(
                'Cloudflare Turnstile nicht erreichbar, Absenden wird durchgelassen: '.$e->getMessage(),
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
            );

            return true;
        }

        if (true === ($data['success'] ?? false)) {
            return true;
        }

        // Falscher/abgelaufener Key blockiert sonst alle Formulare ohne Hinweis. error-codes
        // enthalten kein Secret; Bot-/Replay-Codes bleiben absichtlich still.
        if ([] !== array_intersect(['invalid-input-secret', 'invalid-input-sitekey'], (array) ($data['error-codes'] ?? []))) {
            $this->logger->warning(
                'Cloudflare Turnstile lehnt die Konfiguration ab – Site Key/Secret Key pruefen.',
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
            );
        }

        // Ungueltiges/gefaelschtes Token: hart blockieren (fail-closed).
        return false;
    }
}
