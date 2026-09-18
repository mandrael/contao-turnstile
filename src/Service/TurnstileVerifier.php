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

    // Ein gültiges Turnstile-Token ist wenige hundert Byte; ein überlanger Wert ist kein Token.
    private const MAX_TOKEN_LENGTH = 2048;

    // Öffentlich dokumentierte Cloudflare-Test-Secrets (immer-passierend). Die siteverify-Antwort
    // liefert dafür fest "hostname":"example.com" – die Hostname-Prüfung würde jede echte
    // DDEV-Testinstanz sonst blocken.
    private const TEST_SECRETS = [
        '1x0000000000000000000000000000000AA',
        '2x0000000000000000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    ];

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
        // Zugriff über den Framework-Adapter statt statischem Config::get -> testbar/mockbar.
        $this->framework->initialize();

        return trim((string) $this->framework->getAdapter(Config::class)->get($key));
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getSiteKey() && '' !== $this->getSecretKey();
    }

    /**
     * Default an (rückwärtskompatibel): nur ein explizit leerer Wert (Checkbox abgewählt) schaltet
     * remoteip ab; ungesetzt (frische Installation) sendet weiterhin. Hinter NAT/VPN/iCloud Private
     * Relay kann Abschalten sinnvoll sein – CF validiert remoteip nicht strikt (kein IP-Mismatch-Code).
     */
    private function sendRemoteIp(): bool
    {
        $this->framework->initialize();

        return '' !== (string) ($this->framework->getAdapter(Config::class)->get('turnstileSendRemoteIp') ?? '1');
    }

    /**
     * Fallback 'filter' (Brücke): protokolliert eine durchgelassene, aber fehlgeschlagene Submission auf
     * Level info, damit die Site die Menge (Privacy-Browser-Fehlalarme vs. Bots) per Log auswerten
     * kann. Liegt hier, weil der Logger via DI injiziert ist (FormTurnstile kann monolog.logger.contao
     * nicht über den Container holen – nicht public). $category ist 'missing-token' oder
     * 'verification-failed'; nie Token/Secret/PII loggen.
     */
    public function logSoftPass(string $category): void
    {
        $this->logger->info(
            'Cloudflare Turnstile soft-pass: Verifikation fehlgeschlagen, Absenden trotzdem erlaubt ('.$category.').',
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]
        );
    }

    /**
     * ALTCHA-Fallback: bestandener Proof-of-Work-Zweitbeweis (kein „fehlgeschlagen"-Wortlaut, weil der
     * Client den PoW nachweislich gelöst hat).
     */
    public function logAltchaPass(): void
    {
        $this->logger->info(
            'Cloudflare Turnstile ALTCHA-Fallback: Proof-of-Work gelöst, Absenden erlaubt.',
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]
        );
    }

    /**
     * ALTCHA-Fallback blockiert. $category trennt „altcha-empty" (leeres Feld -> JS/Endpoint kaputt),
     * „altcha-invalid" (gefülltes, aber ungültiges/abgelaufenes/Replay-Payload -> Angriff) und
     * „altcha-timing-invalid" (fehlendes oder falsch signiertes Zeitstempel-Feld -> Betriebsstörung:
     * Template-Override ohne das Feld, oder Seiten-Cache über eine kernel.secret-Rotation hinweg) –
     * im Prod-Log der Unterschied zwischen Betriebsstörung und Angriff. Nie Payload/PII loggen.
     */
    public function logAltchaBlock(string $category): void
    {
        $this->logger->info(
            'Cloudflare Turnstile ALTCHA-Fallback blockiert ('.$category.').',
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]
        );
    }

    /**
     * ALTCHA-Fallback nicht verfügbar: der Client konnte keinen Zweitbeweis erzeugen (kein HTTPS
     * erkannt, trusted_proxies prüfen) oder die Route/Assets waren nicht auflösbar (Nicht-Managed-
     * Setup ohne Manager-Plugin). Level error statt info: Betriebsstörung, kein Angriff, muss im
     * Prod-Log auffallen. Der Aufrufer blockiert das Formular (fail-closed).
     */
    public function logAltchaUnavailable(): void
    {
        $this->logger->error(
            'Cloudflare Turnstile ALTCHA-Fallback nicht verfügbar, Absenden wird blockiert: '
            .'kein HTTPS erkannt (trusted_proxies prüfen) oder Route/Assets nicht auflösbar (altcha-unavailable).',
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
        );
    }

    public function validate(?string $token): bool
    {
        if (null === $token || '' === $token) {
            // Hier wird ein stiller Totalausfall sichtbar: kommt gar kein Token an (kaputter
            // Template-Override/Feldname, JS aus), genau EINE Warnung – bewusst warning (nicht info),
            // damit ein flächiger Ausfall im Prod-Log auffällt. Abgelehnte Tokens (Bot-Replays)
            // bleiben weiter still, um keine Log-Flut zu erzeugen. Nie das Secret loggen.
            $this->logger->warning(
                'Cloudflare Turnstile: kein Token im Request – Template/Feldname prüfen.',
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]
            );

            return false;
        }

        if (\strlen($token) > self::MAX_TOKEN_LENGTH) {
            // Kein plausibles Turnstile-Token -> gar nicht erst gegen Cloudflare validieren.
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
            // Cloudflare nicht erreichbar oder unverwertbare Antwort. Fail-closed: die konfigurierte
            // Fallback-Stufe (block/filter/altcha) entscheidet über das weitere Vorgehen, nicht mehr
            // dieser Verifier. Andere Fehler (Code-Bugs) NICHT schlucken. Niemals Secret/$GLOBALS loggen.
            $this->logger->error(
                'Cloudflare Turnstile nicht erreichbar, Verifikation gilt als fehlgeschlagen; die konfigurierte '
                .'Fallback-Stufe entscheidet über das weitere Vorgehen: '.$e->getMessage(),
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
            );

            return false;
        }

        if (true === ($data['success'] ?? false)) {
            return $this->hostnameMatches($data);
        }

        // Falscher/abgelaufener Key blockiert sonst alle Formulare ohne Hinweis. error-codes
        // enthalten kein Secret; Bot-/Replay-Codes bleiben absichtlich still.
        if ([] !== array_intersect(['invalid-input-secret', 'invalid-input-sitekey'], (array) ($data['error-codes'] ?? []))) {
            $this->logger->warning(
                'Cloudflare Turnstile lehnt die Konfiguration ab – Site Key/Secret Key prüfen.',
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
            );
        }

        // Ungültiges/gefälschtes Token: hart blockieren (fail-closed).
        return false;
    }

    /**
     * Hostname-Bindung: ein anderswo gelöstes Token darf hier nicht gelten. Übersprungen (gilt als
     * Treffer), wenn kein Request vorliegt, die Antwort kein hostname-Feld liefert, oder das
     * konfigurierte Secret eines der drei Cloudflare-Test-Secrets ist (liefert fest "example.com").
     *
     * @param array<string, mixed> $data
     */
    private function hostnameMatches(array $data): bool
    {
        if (\in_array($this->getSecretKey(), self::TEST_SECRETS, true)) {
            return true;
        }

        $responseHost = $data['hostname'] ?? '';

        if (!\is_string($responseHost) || '' === $responseHost) {
            return true;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return true;
        }

        // getHost() entfernt den Port bereits selbst.
        $requestHost = $this->normalizeHost($request->getHost());
        $responseHostNormalized = $this->normalizeHost($responseHost);

        if ($requestHost === $responseHostNormalized) {
            return true;
        }

        $this->logger->warning(
            \sprintf(
                'Cloudflare Turnstile: Hostname der siteverify-Antwort ("%s") weicht vom Request-Host ("%s") ab.',
                $responseHostNormalized,
                $requestHost
            ),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
        );

        return false;
    }

    /**
     * Vergleichbar machen: Groß-/Kleinschreibung, abschließender Punkt (FQDN-Notation) und – falls
     * die intl-Extension verfügbar ist – Punycode-Normalisierung für IDN-Domains. Liefert
     * idn_to_ascii() false (kein gültiger Hostname), bleibt der bereits normalisierte Wert stehen.
     */
    private function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower($host), '.');

        // idn_to_ascii('') wirft seit PHP 8 ein ValueError statt false zu liefern. Ein leerer Host
        // (Request::getHost() ohne Host-Header/SERVER_NAME, oder ein Hostname ".") bleibt daher
        // unverändert, statt in die Funktion zu laufen.
        if ('' === $host) {
            return $host;
        }

        if (\function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host);

            if (false !== $ascii) {
                return $ascii;
            }
        }

        return $host;
    }
}
