<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Cache\CacheItemPoolInterface;
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

    // Drosselung fuer logTemplateOutdated(): ein dauerhaft veralteter Override soll nicht bei jedem
    // Submit erneut loggen.
    private const TEMPLATE_OUTDATED_THROTTLE = 3600;

    // Ausfallprobe (isCloudflareOutageConfirmed()): Platzhalter-Token, das der Angreifer nicht wählen kann.
    // „Erreichbar" gilt 60 s; ein Ausfall muss mindestens 30 s ohne erfolgreiche Probe anhalten, bevor
    // die Ersatzstufe öffnet. Ohne neuen Fehlschlag verfällt der Ausfallbeginn nach 120 s.
    private const PROBE_TOKEN = 'mandrael-turnstile-outage-probe';
    private const PROBE_REACHABLE_KEY = 'mandrael_turnstile.probe_reachable';
    private const PROBE_OUTAGE_KEY = 'mandrael_turnstile.probe_outage_since';
    private const PROBE_REACHABLE_TTL = 60;
    private const PROBE_OUTAGE_MIN_SECONDS = 30;
    private const PROBE_OUTAGE_GAP = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
        private readonly CacheItemPoolInterface $cache,
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

    /**
     * Meldet einen veralteten Template-Override: FormTurnstile::missingTemplateMarkers() fand im
     * gerenderten HTML nicht alle erwarteten Marker. Level error (nicht info), weil genau das zweimal
     * einen mehrstündigen stillen Formular-Ausfall verursacht hat, ohne dass irgendetwas es meldete.
     * Höchstens einmal je Stunde je Kombination aus Template und fehlenden Markern (PSR-6-Cache-Schlüssel
     * aus beidem), damit ein dauerhaft veralteter Override das Log nicht bei jedem Submit flutet; der
     * Cache wird von FormTurnstile aus nur erreicht, wenn wirklich etwas fehlt. Wirft der Cache
     * (getItem/save) oder der Logger selbst, wird das jeweils einzeln abgefangen statt abzubrechen: eine
     * Meldung zu viel (Cache) bzw. eine ausbleibende Meldung (Logger) ist hier harmloser als ein Formular,
     * das wegen reiner Diagnose mit HTTP 500 endet – der Logger-Aufruf ist reine Diagnose und darf das
     * Rendern der Seite nie verhindern (nicht beschreibbares Logverzeichnis, volle Platte).
     *
     * @param list<string> $missing
     */
    public function logTemplateOutdated(string $template, array $missing): void
    {
        $key = 'mandrael_turnstile.template_outdated.'.md5($template.'|'.implode(',', $missing));

        try {
            $item = $this->cache->getItem($key);

            if ($item->isHit()) {
                return;
            }

            $this->cache->save($item->set(true)->expiresAfter(self::TEMPLATE_OUTDATED_THROTTLE));
        } catch (\Throwable) {
            // Bewusst kein Abbruch, siehe Docblock: lieber eine Meldung zu viel als ein verlorener
            // Hinweis auf einen stillen Formular-Ausfall.
        }

        $message = \sprintf(
            'Cloudflare Turnstile: Template "%s" ist veraltet, es fehlen die Marker %s '
            .'(template-outdated) – Override gegen das Bundle-Template abgleichen.',
            $template,
            implode(', ', $missing)
        );
        $context = ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)];

        try {
            $this->logger->error($message, $context);
        } catch (\Throwable) {
            // Reine Diagnose: darf die Formularseite nie abschießen, siehe Docblock. Nur der
            // Logger-Aufruf selbst ist hier abgesichert, nicht der Bau von Nachricht/Context –
            // ein Fehler dabei wäre ein Programmierfehler, den das catch nicht verschlucken soll.
        }
    }

    public function validate(?string $token): bool
    {
        if (null === $token || '' === $token) {
            // Hier wird ein stiller Totalausfall sichtbar: kommt gar kein Token an (kaputter
            // Template-Override/Feldname, JS aus), genau EINE Warnung – bewusst warning (nicht info),
            // damit ein flächiger Ausfall im Prod-Log auffällt. Abgelehnte Tokens (Bot-Replays)
            // bleiben weiter still, um keine Log-Flut zu erzeugen. Nie das Secret loggen.
            $this->safeLog('warning', 'Cloudflare Turnstile: kein Token im Request – Template/Feldname prüfen.', ContaoContext::FORMS, __METHOD__);

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

        $result = $this->postSiteverify($payload);

        // Nicht erreichbar oder unverwertbare Antwort: fail-closed. Ob die Ersatzstufe greift, entscheidet
        // allein isCloudflareOutageConfirmed() – nie dieses Ergebnis, denn das Token wählt der Angreifer.
        if (null === $result || null === $result[1]) {
            return false;
        }

        $data = $result[1];

        if (true === ($data['success'] ?? false)) {
            return $this->hostnameMatches($data);
        }

        $this->warnOnConfigError($data);

        // Ungültiges/gefälschtes Token: hart blockieren (fail-closed).
        return false;
    }

    /**
     * Bestätigter Cloudflare-Ausfall – nur dann darf die Ersatzstufe (filter/altcha) Turnstile vertreten.
     * Ein fehlendes oder abgelehntes Token allein ist Turnstiles Urteil über den Absender und öffnet sie nie
     * (am 22.09.2026 löste ein Browser-Bot über Tor so den Proof-of-Work statt Turnstile).
     *
     * Die Probe fragt siteverify mit festem Platzhalter-Token an, also mit einer Eingabe, die der Angreifer
     * nicht bestimmt. Gecacht wird nur „erreichbar"; bei einem Fehlschlag nur der Beginn des Ausfalls, der
     * ohne neuen Fehlschlag nach PROBE_OUTAGE_GAP verfällt und von jeder erfolgreichen Probe gelöscht wird.
     * Bestätigt ist der Ausfall erst, wenn die eigene Probe scheitert und der Beginn mindestens
     * PROBE_OUTAGE_MIN_SECONDS zurückliegt. So öffnet ein einzelner, etwa lastbedingter Timeout nichts. Cache-Fehler: false, ohne Cloudflare anzufragen – ohne Cache lässt
     * sich die Ausfalldauer nicht festhalten.
     */
    public function isCloudflareOutageConfirmed(): bool
    {
        try {
            if ($this->cache->getItem(self::PROBE_REACHABLE_KEY)->isHit()) {
                return false;
            }

            $outage = $this->cache->getItem(self::PROBE_OUTAGE_KEY);
        } catch (\Throwable) {
            return false;
        }

        if ($this->probeReachable()) {
            // Erst den Ausfallbeginn löschen, dann „erreichbar" merken: bliebe ein alter Beginn hinter einem
            // gecachten „erreichbar" stehen, öffnete nach dessen Ablauf schon ein einzelner Fehlschlag.
            try {
                if ($this->cache->deleteItem(self::PROBE_OUTAGE_KEY)) {
                    $this->cache->save($this->cache->getItem(self::PROBE_REACHABLE_KEY)->set(true)->expiresAfter(self::PROBE_REACHABLE_TTL));
                }
            } catch (\Throwable) {
                // Nur Optimierung: die nächste Anfrage probt erneut.
            }

            return false;
        }

        $since = $outage->isHit() && \is_int($outage->get()) ? $outage->get() : time();

        try {
            $saved = $this->cache->save($outage->set($since)->expiresAfter(self::PROBE_OUTAGE_GAP));
        } catch (\Throwable) {
            $saved = false;
        }

        return $saved && time() - $since >= self::PROBE_OUTAGE_MIN_SECONDS;
    }

    /**
     * Unerreichbar nur bei Transportfehler, HTTP ≥ 500 oder HTTP 2xx mit internal-error als einzigem Code. Jede
     * andere Antwort (auch 4xx, Rate-Limit, Nicht-JSON unter 500, Fehlkonfiguration) beweist, dass Cloudflare
     * antwortet – ein 429 etwa könnte eine Bot-Welle selbst auslösen.
     */
    private function probeReachable(): bool
    {
        $result = $this->postSiteverify(['secret' => $this->getSecretKey(), 'response' => self::PROBE_TOKEN]);

        if (null === $result) {
            return false;
        }

        [$status, $data] = $result;

        // internal-error zählt nur mit HTTP 2xx: ein 4xx/429 beweist eine Antwort, auch mit diesem Code.
        if ($status >= 500 || ($status < 300 && ['internal-error'] === array_values((array) ($data['error-codes'] ?? [])))) {
            $this->safeLog('error', \sprintf('Cloudflare Turnstile: siteverify meldet eine Störung (HTTP %d).', $status), ContaoContext::ERROR, __METHOD__);

            return false;
        }

        if (null !== $data) {
            $this->warnOnConfigError($data);
        }

        return true;
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array{0: int, 1: array<mixed>|null}|null Status und dekodierte Antwort (null = kein JSON);
     *                                                   null = Cloudflare nicht erreichbar
     */
    private function postSiteverify(array $payload): ?array
    {
        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => $payload,
                'timeout' => self::TIMEOUT,
                // timeout ist nur der Idle-Timeout; max_duration deckelt die Gesamtdauer.
                'max_duration' => self::TIMEOUT,
            ]);

            $status = $response->getStatusCode();

            try {
                $data = $response->toArray(false);
            } catch (DecodingExceptionInterface) {
                $data = null;
                $this->safeLog('error', \sprintf('Cloudflare Turnstile: unverwertbare siteverify-Antwort (HTTP %d), Verifikation gilt als fehlgeschlagen.', $status), ContaoContext::ERROR, __METHOD__);
            }
        } catch (TransportExceptionInterface $e) {
            // Andere Fehler (Code-Bugs) NICHT schlucken. Niemals Secret/$GLOBALS loggen.
            $this->safeLog('error', 'Cloudflare Turnstile nicht erreichbar, Verifikation gilt als fehlgeschlagen: '.$e->getMessage(), ContaoContext::ERROR, __METHOD__);

            return null;
        }

        return [$status, $data];
    }

    /**
     * Falscher/abgelaufener Key blockiert sonst alle Formulare ohne Hinweis. error-codes enthalten kein
     * Secret; Bot-/Replay-Codes bleiben absichtlich still.
     *
     * @param array<mixed> $data
     */
    private function warnOnConfigError(array $data): void
    {
        if ([] !== array_intersect(['invalid-input-secret', 'invalid-input-sitekey'], (array) ($data['error-codes'] ?? []))) {
            $this->safeLog('warning', 'Cloudflare Turnstile lehnt die Konfiguration ab – Site Key/Secret Key prüfen.', ContaoContext::ERROR, __METHOD__);
        }
    }

    /**
     * Ersatzstufe konfiguriert, aber nicht freigegeben, weil kein Cloudflare-Ausfall bestätigt ist. Info
     * statt error: der Normalfall bei Bots. Zeigt im Prod-Log, dass die Sperre greift.
     */
    public function logFallbackWithheld(): void
    {
        $this->safeLog('info', 'Cloudflare Turnstile: Ersatzstufe nicht freigegeben, kein Cloudflare-Ausfall bestätigt – Absenden blockiert (fallback-withheld).', ContaoContext::FORMS, __METHOD__);
    }

    /**
     * Diagnose auf dem Prüfpfad darf die Formularseite nie mit HTTP 500 beenden (nicht beschreibbares
     * Logverzeichnis, volle Platte; Monolog reicht Handler-Ausnahmen weiter). Abgesichert ist nur der
     * Logger-Aufruf selbst, nicht der Bau der Nachricht.
     */
    private function safeLog(string $level, string $message, string $action, string $method): void
    {
        $context = ['contao' => new ContaoContext($method, $action)];

        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
        }
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

        $this->safeLog('warning', \sprintf(
                'Cloudflare Turnstile: Hostname der siteverify-Antwort ("%s") weicht vom Request-Host ("%s") ab.',
                $responseHostNormalized,
                $requestHost
            ), ContaoContext::ERROR, __METHOD__);

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
