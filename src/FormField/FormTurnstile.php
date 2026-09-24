<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\FormField;

use Contao\Config;
use Contao\FormCaptcha;
use Contao\System;
use Mandrael\ContaoTurnstileBundle\Csp\CloudflareCspSourceRegistrar;
use Mandrael\ContaoTurnstileBundle\EventListener\SubmissionListener;
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

    // Ersatzstufe aktiv ('altcha', gespeichertes 'filter' gilt seit 0.8.0 ebenso) UND Secure Context (Web
    // Crypto verfügbar). Steuert Template-Render + Validate.
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
        // Signierter Render-Zeitstempel für die Mindestzeit der Ersatzstufe.
        $this->turnstileTiming = $this->signTime(time());

        // ALTCHA-Fallback nur in der Ersatzstufe UND im Secure Context (Web Crypto). Sonst kann der
        // Client kein Token erzeugen -> altchaActive bleibt false, applyAltchaFallback() blockiert
        // dann fail-closed (Betreiber-Entscheidung: wo altcha gewählt ist, gilt fail-closed).
        $this->turnstileAltchaUrl = '';

        if ($this->fallbackEnabled() && $this->isSecureContext()) {
            // Route + Bundle-URLs hier in PHP auflösen (NICHT via $this->asset() im Template: dort ist
            // $this auf Contao 4.13 die Widget-Instanz ohne asset()-Methode). Löst eine der drei URLs
            // nicht auf (keine Route ohne Manager-Plugin, kein Request), bleibt altcha inaktiv und
            // applyAltchaFallback() blockiert dann fail-closed – statt das Formular zu crashen oder
            // eine wirkungslose Prüfung stillschweigend durchzulassen.
            $challengeUrl = $this->altchaChallengeUrl();
            $workerUrl = $this->bundleUrl('worker.js');
            $solverUrl = $this->bundleUrl('mandrael-altcha.js');

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
     * Same-origin-Adresse eines ALTCHA-Bundle-Assets: <Basis-Pfad des Requests>/bundles/
     * mandraelcontaoturnstile/altcha/<datei>. Bewusst NICHT mehr über assets.packages (siehe
     * Konstruktor-Kommentar) – sowohl der Worker (new Worker() verweigert fremde Origin, SecurityError)
     * als auch der Solver (scheitert an jeder CSP mit script-src 'self', siehe registerAltcha()) müssen
     * same-origin liegen, eine Assets-URL fremder Origin würde beide brechen. Das Asset-Paket des
     * Bundles nutzt heute EmptyVersionStrategy (Contao-Core, kein manifest.json im Bundle), die feste
     * Adresse entspricht deshalb exakt dem bisherigen getUrl() ohne Assets-URL; ein künftiges
     * manifest.json oder eine eigene Versionsstrategie würde diese feste Pfadbildung brechen. Kein
     * Request (CLI/ESI) -> '' (altcha bleibt inaktiv, applyAltchaFallback() blockiert dann fail-closed).
     */
    private function bundleUrl(string $file): string
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            return '';
        }

        return $request->getBasePath().'/bundles/mandraelcontaoturnstile/altcha/'.$file;
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

        $this->applyFallback($post);
    }

    /**
     * Verhalten ohne gültiges Token (Einstellung turnstileFailureMode): 'block' (Default und unbekannte Werte)
     * weist ab. Die Ersatzstufe ('altcha'; gespeichertes 'filter' seit 0.8.0 ebenso) greift bei jedem
     * Fehlschlag: mechanische Prüfung hier, danach die Einstufung (SubmissionListener, SpamClassifier), die
     * nie abweist, sondern nur bei sicherem Spam die Rückmeldung an den Absender verhindert.
     *
     * @param array<string, mixed> $post
     */
    private function applyFallback(array $post): void
    {
        if (!$this->fallbackEnabled()) {
            $this->blockWithError();

            return;
        }

        $this->applyAltchaFallback($post);
    }

    private function fallbackEnabled(): bool
    {
        return \in_array($this->configValue('turnstileFailureMode', 'block'), ['altcha', 'filter'], true);
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
            $this->getSubmissionListener()->onTokenlessPass($post);

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
     * Liest und prüft das signierte Zeitstempel-Feld cf-turnstile-ts-<id>. Liefert die Unixzeit bei
     * gültiger Signatur, sonst null (Feld fehlt, kein Punkt-Trenner, kein numerischer Zeitanteil,
     * oder die HMAC-Signatur passt nicht). applyAltchaFallback() wertet null fail-closed.
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

        $html = parent::parse($arrAttributes);

        // Selbstprüfung gegen veraltete Template-Overrides: nur wenn Turnstile tatsächlich rendert –
        // form_captcha hat keine dieser Marker, dort wäre es Fehlalarm. Blockiert nichts und wirft nie:
        // das HTML geht unabhängig davon unverändert zurück. Der Wächter sitzt in checkTemplateMarkers()
        // selbst (einzige Stelle, die entscheidet).
        $this->checkTemplateMarkers($html);

        return $html;
    }

    /**
     * Rührt logTemplateOutdated() (und damit den Cache im Verifier) NUR an, wenn wirklich ein Marker
     * fehlt – bei intaktem Template kostet der Aufruf nur die String-Vergleiche aus
     * missingTemplateMarkers(). Eigene Methode statt Inline-Code in parse(), damit sie per Reflection
     * mit einem gemockten Verifier testbar ist (parse() selbst ruft parent::parse() -> Template-Loader,
     * im ContaoTestCase nicht sinnvoll aufrufbar).
     */
    private function checkTemplateMarkers(string $html): void
    {
        // form_captcha hat keine dieser Marker, dort wäre jeder Aufruf ein Fehlalarm – deshalb hier
        // und nicht mehr (nur) in parse() geprüft, damit die Bedingung auch von der Test-Suite erreicht wird.
        if ($this->fallbackToCaptcha) {
            return;
        }

        $missing = $this->missingTemplateMarkers($html);

        if ($this->altchaActive && !isset($GLOBALS['TL_BODY']['mandrael-altcha'])) {
            $missing[] = 'TL_BODY[mandrael-altcha]';
        }

        if ([] !== $missing) {
            $this->getVerifier()->logTemplateOutdated($this->strTemplate, $missing);
        }
    }

    /**
     * Rein, ohne Seiteneffekte (per Reflection testbar) – sucht ausschließlich nach nackten Token, nie
     * mit Attributnamen, Anführungszeichen oder Whitespace drumherum: ein Override mit anderer
     * Attributreihenfolge oder anderem Quoting-Stil (single statt double quotes, Leerzeichen um "=")
     * soll keinen Fehlalarm auslösen, nur ein wirklich fehlendes Feld.
     *
     * Token mit ID-Suffix (cf-turnstile-*-<id>, altcha-<id>) laufen über preg_match mit "(?!\d)": reines
     * str_contains würde "cf-turnstile-hp-2" bereits durch "cf-turnstile-hp-25" erfüllt sehen (Präfix-
     * Kollision bei zweistelligen Feld-IDs). Token ohne ID-Suffix (data-sitekey usw.) bleiben str_contains.
     *
     * @return list<string>
     */
    private function missingTemplateMarkers(string $html): array
    {
        $idMarkers = [
            'cf-turnstile-response-'.$this->id,
            'cf-turnstile-hp-'.$this->id,
            'cf-turnstile-ts-'.$this->id,
        ];

        $plainMarkers = ['data-sitekey'];

        if ($this->altchaActive) {
            $idMarkers[] = 'altcha-'.$this->id;
            $plainMarkers[] = 'data-mandrael-altcha';
            $plainMarkers[] = 'data-challengeurl';
            $plainMarkers[] = 'data-workerurl';
        }

        $missing = [];

        foreach ($idMarkers as $marker) {
            if (!preg_match('/'.preg_quote($marker, '/').'(?!\d)/', $html)) {
                $missing[] = $marker;
            }
        }

        foreach ($plainMarkers as $marker) {
            if (!str_contains($html, $marker)) {
                $missing[] = $marker;
            }
        }

        return $missing;
    }

    private function getVerifier(): TurnstileVerifier
    {
        return System::getContainer()->get(TurnstileVerifier::class);
    }

    private function getSubmissionListener(): SubmissionListener
    {
        return System::getContainer()->get(SubmissionListener::class);
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
