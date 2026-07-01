<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\FormField;

use Contao\Config;
use Contao\FormCaptcha;
use Contao\System;
use Mandrael\ContaoTurnstileBundle\Csp\CloudflareCspSourceRegistrar;
use Mandrael\ContaoTurnstileBundle\Service\TurnstileVerifier;

/**
 * Ersetzt das Standard-CAPTCHA. Wird vom Formular-Compiler ueber $GLOBALS['TL_FFL']['captcha']
 * per "new" erzeugt (nicht ueber den Container), daher Service-Zugriff via System::getContainer().
 *
 * @property string $siteKey
 * @property string $turnstileTheme
 * @property string $turnstileSize
 * @property string $turnstileAppearance
 * @property string $turnstileTiming
 */
class FormTurnstile extends FormCaptcha
{
    // Mindest-Ausfuellzeit in Sekunden: schneller = mit hoher Sicherheit ein Skript, kein Mensch.
    // ponytail: bewusst konservativ (kaum Fehlalarme), feste Schwelle; bei Bedarf spaeter konfigurierbar.
    private const MIN_FILL_SECONDS = 3;

    protected $strTemplate = 'form_mandrael_turnstile';

    private bool $fallbackToCaptcha = false;

    public function __construct($arrAttributes = null)
    {
        parent::__construct($arrAttributes);

        $verifier = $this->getVerifier();

        // Ohne Keys, global deaktiviert oder pro Feld abgewaehlt: verlustfrei auf das
        // Standard-CAPTCHA zurueckfallen.
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
        // Signierter Render-Zeitstempel fuer den Timing-Check (Sekundaerfilter im filter-Modus).
        $this->turnstileTiming = $this->signTime(time());
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
        // Roh aus dem ParameterBag (nicht ueber Contao\Input): das opake CF-Token darf nicht durch
        // die XSS-/Encoding-Schicht. all() ohne Schluessel wirft bei Array-Input kein BadRequest.
        $post = null !== $request ? $request->request->all() : [];
        // Feldname pro Widget-Instanz eindeutig (analog Core-Captcha: captcha_<id>), sonst teilen sich
        // mehrere Turnstile-Felder eines Formulars denselben POST-Schluessel und PHP behaelt nur den
        // letzten Wert. Faellt auf den Cloudflare-Default cf-turnstile-response zurueck, falls ein
        // Template-Override das -<id>-Suffix verliert – sonst braeche das Feld still.
        $value = $post['cf-turnstile-response-'.$this->id] ?? $post['cf-turnstile-response'] ?? null;
        $token = \is_string($value) ? $value : '';

        if ($this->getVerifier()->validate($token)) {
            return;
        }

        $this->applyFallback($post, $token);
    }

    /**
     * Verhalten, wenn die Turnstile-Pruefung fehlschlaegt (Einstellung turnstileFailureMode):
     * 'block' (Default und unbekannte Werte) weist ab; 'filter' laesst nach dem Honeypot/Timing-
     * Sekundaerfilter durch. Die Stufe 'altcha' wird in 0.7.0 hier eingehaengt.
     *
     * @param array<string, mixed> $post
     */
    private function applyFallback(array $post, string $token): void
    {
        if ('filter' === $this->configValue('turnstileFailureMode', 'block')) {
            $this->applyFilterFallback($post, $token);

            return;
        }

        $this->blockWithError();
    }

    /**
     * Fallback 'filter': offensichtliche Bots (Honeypot befuellt oder unmenschlich schnell
     * abgeschickt) trotzdem blocken; nur den mehrdeutigen Rest (z. B. Turnstile-Fehlalarme bei
     * Privacy-Browsern) durchlassen + protokollieren (Kategorie ohne Token/PII). Logging laeuft
     * ueber den Verifier (dort ist der Contao-Logger per DI injiziert – monolog.logger.contao ist
     * nicht public, also nicht ueber den Container abrufbar); der Missing-Token-Warn feuert davon
     * unabhaengig im Verifier.
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

    private function blockWithError(): void
    {
        $this->class = 'error';
        $this->addError($GLOBALS['TL_LANG']['ERR']['turnstile'] ?? 'Captcha validation failed.');
    }

    /**
     * Honeypot: ein per CSS verstecktes Feld, das ein Mensch nie sieht. Ist es befuellt (oder kommt
     * es als unerwarteter Typ an), war ein Skript am Werk. Kein Fehlalarm-Risiko fuer echte Nutzer.
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
     * Timing: signierter Render-Zeitstempel. Schneller als MIN_FILL_SECONDS = Bot. Fehlt das Feld
     * oder ist die Signatur ungueltig (Template-Override, Cache, Faelschung), wird NICHT geblockt
     * (fail-open) – der Honeypot bleibt als Schranke. So entstehen keine Fehlalarme durch Edge-Cases.
     *
     * @param array<string, mixed> $post
     */
    private function submittedTooFast(array $post): bool
    {
        $raw = $post['cf-turnstile-ts-'.$this->id] ?? null;

        if (!\is_string($raw) || !str_contains($raw, '.')) {
            return false;
        }

        [$time, $sig] = explode('.', $raw, 2);

        if (!ctype_digit($time)) {
            return false;
        }

        if (!hash_equals($this->signTime((int) $time), $raw)) {
            return false;
        }

        return time() - (int) $time < self::MIN_FILL_SECONDS;
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

        // Markup kommt vollstaendig aus dem Template form_mandrael_turnstile.
        return '';
    }

    public function parse($arrAttributes = null)
    {
        // Cloudflare-Host in die Seiten-CSP eintragen (nur Contao 5.x, Service nur dort registriert).
        if (!$this->fallbackToCaptcha) {
            $container = System::getContainer();

            if ($container->has(CloudflareCspSourceRegistrar::class)) {
                $container->get(CloudflareCspSourceRegistrar::class)->register();
            }
        }

        return parent::parse($arrAttributes);
    }

    private function getVerifier(): TurnstileVerifier
    {
        return System::getContainer()->get(TurnstileVerifier::class);
    }

    private function configValue(string $key, string $default): string
    {
        $value = trim((string) Config::get($key));

        return '' !== $value ? $value : $default;
    }
}
