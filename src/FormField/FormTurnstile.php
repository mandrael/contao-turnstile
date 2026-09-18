<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\FormField;

use Contao\Config;
use Contao\FormCaptcha;
use Contao\System;
use Mandrael\ContaoTurnstileBundle\Csp\CloudflareCspSourceRegistrar;
use Mandrael\ContaoTurnstileBundle\Service\AltchaVerifier;
use Mandrael\ContaoTurnstileBundle\Service\TurnstileVerifier;

/**
 * Ersetzt das Standard-CAPTCHA. Wird vom Formular-Compiler über $GLOBALS['TL_FFL']['captcha']
 * per "new" erzeugt (nicht über den Container), daher Service-Zugriff via System::getContainer().
 *
 * @property string $siteKey
 * @property string $turnstileTheme
 * @property string $turnstileSize
 * @property string $turnstileAppearance
 * @property string $turnstileTiming
 * @property string $turnstileAltchaUrl
 * @property string $turnstileWorkerUrl
 * @property string $turnstileSolverUrl
 */
class FormTurnstile extends FormCaptcha
{
    // Mindest-Ausfüllzeit in Sekunden: schneller = mit hoher Sicherheit ein Skript, kein Mensch.
    // ponytail: bewusst konservativ (kaum Fehlalarme), feste Schwelle; bei Bedarf später konfigurierbar.
    private const MIN_FILL_SECONDS = 3;

    protected $strTemplate = 'form_mandrael_turnstile';

    private bool $fallbackToCaptcha = false;

    // 'altcha'-Modus aktiv UND Secure Context (Web Crypto verfügbar). Steuert Template-Render + Validate.
    private bool $altchaActive = false;

    public function __construct($arrAttributes = null)
    {
        parent::__construct($arrAttributes);

        $verifier = $this->getVerifier();

        // Ohne Keys, global deaktiviert oder pro Feld abgewählt: verlustfrei auf das
        // Standard-CAPTCHA zurückfallen.
        if (!$verifier->isConfigured() || !$this->turnstileApplies($arrAttributes)) {
            $this->fallbackToCaptcha = true;
            $this->strTemplate = 'form_captcha';

            return;
        }

        // Werte landen via Widget::__set in arrConfiguration und sind im Template als
        // $this->siteKey usw. lesbar. Bewusst nicht-reservierte Namen (kein theme/size/class).
        $this->siteKey = $verifier->getSiteKey();
        $this->turnstileTheme = $this->configValue('turnstileTheme', 'light');
        $this->turnstileSize = $this->configValue('turnstileSize', 'normal');
        $this->turnstileAppearance = $this->configValue('turnstileAppearance', 'always');
        // Signierter Render-Zeitstempel für den Timing-Check (Sekundärfilter im filter-Modus).
        $this->turnstileTiming = $this->signTime(time());

        // ALTCHA-Fallback nur im Modus 'altcha' UND im Secure Context (Web Crypto). Sonst kann der
        // Client kein Token erzeugen -> altchaActive bleibt false, applyAltchaFallback() blockiert
        // dann fail-closed (Betreiber-Entscheidung: wo altcha gewählt ist, gilt fail-closed).
        $this->turnstileAltchaUrl = '';

        if ('altcha' === $this->configValue('turnstileFailureMode', 'block') && $this->isSecureContext()) {
            // Route + Asset-URLs hier in PHP auflösen (NICHT via $this->asset() im Template: dort ist
            // $this auf Contao 4.13 die Widget-Instanz ohne asset()-Methode). Löst eine der drei URLs
            // nicht auf (fehlende Route/Asset-Package ohne Manager-Plugin), bleibt altcha inaktiv und
            // applyAltchaFallback() blockiert dann fail-closed – statt das Formular zu crashen oder
            // eine wirkungslose Prüfung stillschweigend durchzulassen.
            $challengeUrl = $this->altchaChallengeUrl();
            $workerUrl = $this->assetUrl('altcha/worker.js');
            $solverUrl = $this->assetUrl('altcha/mandrael-altcha.js');

            if ('' !== $challengeUrl && '' !== $workerUrl && '' !== $solverUrl) {
                $this->turnstileAltchaUrl = $challengeUrl;
                $this->turnstileWorkerUrl = $workerUrl;
                $this->turnstileSolverUrl = $solverUrl;
                $this->altchaActive = true;
            }
        }
    }

    /**
     * Erzeugt die Challenge-Endpoint-URL. In einem Nicht-Managed-Setup (Bundle in eigener Symfony-App
     * ohne Manager-Plugin) ist die Route nicht registriert und generate() wirft – dann '' zurückgeben,
     * damit der altcha-Modus kontrolliert fail-closed blockiert (applyAltchaFallback()) statt das
     * Formular zu crashen.
     */
    private function altchaChallengeUrl(): string
    {
        try {
            return (string) System::getContainer()->get('router')->generate('mandrael_turnstile_altcha');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Löst ein Bundle-Asset über den Symfony-Assets-Service auf (identisch zu Template::asset(), aber
     * in PHP statt im Widget-Template – siehe Konstruktor-Kommentar). Package = 'mandrael_contao_turnstile'
     * -> URL bundles/mandraelcontaoturnstile/<path>. Bei fehlendem Package '' zurück (altcha bleibt
     * inaktiv, applyAltchaFallback() blockiert dann fail-closed).
     */
    private function assetUrl(string $path): string
    {
        try {
            return (string) System::getContainer()->get('assets.packages')->getUrl($path, 'mandrael_contao_turnstile');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Globaler Modus + Per-Feld-Override (Vorgabe/an/aus). 'off' ist die globale Notbremse.
     */
    private function turnstileApplies($arrAttributes): bool
    {
        $mode = $this->configValue('turnstileMode', 'optout');

        if ('off' === $mode) {
            return false;
        }

        $field = \is_array($arrAttributes) ? (string) ($arrAttributes['turnstileField'] ?? '') : '';

        return match ($field) {
            'on' => true,
            'off' => false,
            default => 'optout' === $mode,
        };
    }

    public function validate()
    {
        if ($this->fallbackToCaptcha) {
            parent::validate();

            return;
        }

        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        // Roh aus dem ParameterBag (nicht über Contao\Input): das opake CF-Token darf nicht durch
        // die XSS-/Encoding-Schicht. all() ohne Schlüssel wirft bei Array-Input kein BadRequest.
        $post = null !== $request ? $request->request->all() : [];
        // Feldname pro Widget-Instanz eindeutig (analog Core-Captcha: captcha_<id>), sonst teilen sich
        // mehrere Turnstile-Felder eines Formulars denselben POST-Schlüssel und PHP behält nur den
        // letzten Wert. Fällt auf den Cloudflare-Default cf-turnstile-response zurück, falls ein
        // Template-Override das -<id>-Suffix verliert – sonst bräche das Feld still.
        $value = $post['cf-turnstile-response-'.$this->id] ?? $post['cf-turnstile-response'] ?? null;
        $token = \is_string($value) ? $value : '';

        if ($this->getVerifier()->validate($token)) {
            return;
        }

        $this->applyFallback($post, $token);
    }

    /**
     * Verhalten, wenn die Turnstile-Prüfung fehlschlägt (Einstellung turnstileFailureMode):
     * 'block' (Default und unbekannte Werte) weist ab; 'filter' lässt nach dem Honeypot/Timing-
     * Sekundärfilter durch. Die Stufe 'altcha' wird in 0.7.0 hier eingehängt.
     *
     * @param array<string, mixed> $post
     */
    private function applyFallback(array $post, string $token): void
    {
        $mode = $this->configValue('turnstileFailureMode', 'block');

        if ('filter' === $mode) {
            $this->applyFilterFallback($post, $token);

            return;
        }

        if ('altcha' === $mode) {
            $this->applyAltchaFallback($post);

            return;
        }

        $this->blockWithError();
    }

    /**
     * Fallback 'filter': offensichtliche Bots (Honeypot befüllt oder unmenschlich schnell
     * abgeschickt) trotzdem blocken; nur den mehrdeutigen Rest (z. B. Turnstile-Fehlalarme bei
     * Privacy-Browsern) durchlassen + protokollieren (Kategorie ohne Token/PII). Logging läuft
     * über den Verifier (dort ist der Contao-Logger per DI injiziert – monolog.logger.contao ist
     * nicht public, also nicht über den Container abrufbar); der Missing-Token-Warn feuert davon
     * unabhängig im Verifier.
     *
     * @param array<string, mixed> $post
     */
    private function applyFilterFallback(array $post, string $token): void
    {
        if ($this->honeypotTripped($post) || $this->submittedTooFast($post)) {
            $this->blockWithError();

            return;
        }

        $this->getVerifier()->logSoftPass('' === $token ? 'missing-token' : 'verification-failed');
    }

    /**
     * Fallback 'altcha': ALTCHA nicht verfügbar (unsicherer Kontext ohne Web Crypto ODER fehlende
     * Route/Assets ohne Manager-Plugin) blockiert fail-closed und wird deshalb ZUERST geprüft – lief
     * dieser Zweig später, würde der Zeitstempel-Zweig (parseSignedTime()) denselben Fall bereits
     * stumm abfangen, und genau die Betriebsstörung, für die logAltchaUnavailable() gedacht ist,
     * bliebe unsichtbar. Danach der billige Filter (Honeypot/Timing, im altcha-Modus fail-closed),
     * dann der ALTCHA-Proof-of-Work als Zweitbeweis. Ohne gültige Lösung wird blockiert.
     *
     * @param array<string, mixed> $post
     */
    private function applyAltchaFallback(array $post): void
    {
        if (!$this->altchaActive) {
            $this->getVerifier()->logAltchaUnavailable();
            $this->blockWithError();

            return;
        }

        if ($this->honeypotTripped($post)) {
            $this->blockWithError();

            return;
        }

        $time = $this->parseSignedTime($post);

        if (null === $time) {
            // Fehlendes oder falsch signiertes Feld: im altcha-Modus fail-closed (Betriebsstörung,
            // nicht Angriff – Ursachen: Template-Override ohne das Feld, oder Seiten-Cache über eine
            // kernel.secret-Rotation hinweg).
            $this->getVerifier()->logAltchaBlock('altcha-timing-invalid');
            $this->blockWithError();

            return;
        }

        if (time() - $time < self::MIN_FILL_SECONDS) {
            // „Zu schnell" blockt wie bisher ohne eigenes Log.
            $this->blockWithError();

            return;
        }

        $payload = \is_string($post['altcha-'.$this->id] ?? null) ? $post['altcha-'.$this->id] : '';

        if ('' !== $payload && $this->getAltchaVerifier()->validate($payload)) {
            $this->getVerifier()->logAltchaPass();

            return;
        }

        // Diagnose: leeres Feld = JS/Endpoint kaputt; gefüllt-aber-ungültig = Angriff/Replay.
        $this->getVerifier()->logAltchaBlock('' === $payload ? 'altcha-empty' : 'altcha-invalid');
        $this->blockWithError();
    }

    private function blockWithError(): void
    {
        $this->class = 'error';
        $this->addError($GLOBALS['TL_LANG']['ERR']['turnstile'] ?? 'Captcha validation failed.');
    }

    /**
     * Honeypot: ein per CSS verstecktes Feld, das ein Mensch nie sieht. Ist es befüllt (oder kommt
     * es als unerwarteter Typ an), war ein Skript am Werk. Kein Fehlalarm-Risiko für echte Nutzer.
     *
     * @param array<string, mixed> $post
     */
    private function honeypotTripped(array $post): bool
    {
        $value = $post['cf-turnstile-hp-'.$this->id] ?? '';

        if (!\is_string($value)) {
            return true;
        }

        return '' !== trim($value);
    }

    /**
     * Timing für den Modus 'filter': signierter Render-Zeitstempel, schneller als MIN_FILL_SECONDS =
     * Bot. Fehlt das Feld oder ist die Signatur ungültig (Template-Override, Cache, Fälschung), wird
     * NICHT geblockt (fail-open) – der Honeypot bleibt als Schranke. So entstehen keine Fehlalarme
     * durch Edge-Cases. Der Modus 'altcha' prüft denselben Zeitstempel stattdessen fail-closed über
     * parseSignedTime() direkt in applyAltchaFallback().
     *
     * @param array<string, mixed> $post
     */
    private function submittedTooFast(array $post): bool
    {
        $time = $this->parseSignedTime($post);

        if (null === $time) {
            return false;
        }

        return time() - $time < self::MIN_FILL_SECONDS;
    }

    /**
     * Liest und prüft das signierte Zeitstempel-Feld cf-turnstile-ts-<id>. Liefert die Unixzeit bei
     * gültiger Signatur, sonst null (Feld fehlt, kein Punkt-Trenner, kein numerischer Zeitanteil,
     * oder die HMAC-Signatur passt nicht). Gemeinsame Grundlage für submittedTooFast() (fail-open,
     * Modus 'filter') und applyAltchaFallback() (fail-closed, Modus 'altcha') – keine doppelte
     * Signaturprüfung.
     *
     * @param array<string, mixed> $post
     */
    private function parseSignedTime(array $post): ?int
    {
        $raw = $post['cf-turnstile-ts-'.$this->id] ?? null;

        if (!\is_string($raw) || !str_contains($raw, '.')) {
            return null;
        }

        [$time, $sig] = explode('.', $raw, 2);

        if (!ctype_digit($time)) {
            return null;
        }

        if (!hash_equals($this->signTime((int) $time), $raw)) {
            return null;
        }

        return (int) $time;
    }

    private function signTime(int $time): string
    {
        $secret = (string) System::getContainer()->getParameter('kernel.secret');

        return $time.'.'.substr(hash_hmac('sha256', (string) $time, $secret), 0, 16);
    }

    public function generate()
    {
        if ($this->fallbackToCaptcha) {
            return parent::generate();
        }

        // Markup kommt vollständig aus dem Template form_mandrael_turnstile.
        return '';
    }

    public function parse($arrAttributes = null)
    {
        // Cloudflare-Host in die Seiten-CSP eintragen (nur Contao 5.x, Service nur dort registriert).
        if (!$this->fallbackToCaptcha) {
            $container = System::getContainer();

            if ($container->has(CloudflareCspSourceRegistrar::class)) {
                $registrar = $container->get(CloudflareCspSourceRegistrar::class);
                $registrar->register();

                if ($this->altchaActive) {
                    $registrar->registerAltcha();
                }
            }
        }

        return parent::parse($arrAttributes);
    }

    private function getVerifier(): TurnstileVerifier
    {
        return System::getContainer()->get(TurnstileVerifier::class);
    }

    private function getAltchaVerifier(): AltchaVerifier
    {
        return System::getContainer()->get(AltchaVerifier::class);
    }

    /**
     * Web Crypto (ALTCHA-PoW) braucht einen Secure Context: HTTPS oder localhost. Ohne Request
     * (CLI/ESI) als unsicher behandeln – degradieren statt werfen.
     */
    private function isSecureContext(): bool
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            return false;
        }

        if ($request->isSecure()) {
            return true;
        }

        $host = $request->getHost();

        // Symfony Request::getHost() liefert IPv6-Loopback in RFC-Klammern ('[::1]'), nie nacktes '::1'.
        return \in_array($host, ['127.0.0.1', '[::1]', 'localhost'], true) || str_ends_with($host, '.localhost');
    }

    private function configValue(string $key, string $default): string
    {
        $value = trim((string) Config::get($key));

        return '' !== $value ? $value : $default;
    }
}
