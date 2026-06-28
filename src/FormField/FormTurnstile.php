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
 */
class FormTurnstile extends FormCaptcha
{
    protected $strTemplate = 'form_mandrael_turnstile';

    private bool $turnstileFallback = false;

    public function __construct($arrAttributes = null)
    {
        parent::__construct($arrAttributes);

        $verifier = $this->getVerifier();

        // Ohne Keys, global deaktiviert oder pro Feld abgewaehlt: verlustfrei auf das
        // Standard-CAPTCHA zurueckfallen.
        if (!$verifier->isConfigured() || !$this->turnstileApplies($arrAttributes)) {
            $this->turnstileFallback = true;
            $this->strTemplate = 'form_captcha';

            return;
        }

        // Werte landen via Widget::__set in arrConfiguration und sind im Template als
        // $this->siteKey usw. lesbar. Bewusst nicht-reservierte Namen (kein theme/size/class).
        $this->siteKey = $verifier->getSiteKey();
        $this->turnstileTheme = $this->configValue('turnstileTheme', 'light');
        $this->turnstileSize = $this->configValue('turnstileSize', 'normal');
        $this->turnstileAppearance = $this->configValue('turnstileAppearance', 'always');
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
        if ($this->turnstileFallback) {
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

        // 'soft' = Bruecke: fehlgeschlagene Pruefung NICHT blocken, aber jede durchgelassene
        // Submission protokollieren (Kategorie ohne Token/PII), damit die Site Privacy-Browser-
        // Fehlalarme von Bots unterscheiden kann. Default 'hard' = bisheriges Verhalten (blocken).
        // Logging laeuft ueber den Verifier (dort ist der Contao-Logger per DI injiziert –
        // monolog.logger.contao ist nicht public, also nicht ueber den Container abrufbar).
        // Der Missing-Token-Warn feuert davon unabhaengig im Verifier, auch im soft-Modus.
        if ('soft' === $this->configValue('turnstileBlocking', 'hard')) {
            $this->getVerifier()->logSoftPass('' === $token ? 'missing-token' : 'verification-failed');

            return;
        }

        $this->class = 'error';
        $this->addError($GLOBALS['TL_LANG']['ERR']['turnstile'] ?? 'Captcha validation failed.');
    }

    public function generate()
    {
        if ($this->turnstileFallback) {
            return parent::generate();
        }

        // Markup kommt vollstaendig aus dem Template form_mandrael_turnstile.
        return '';
    }

    public function parse($arrAttributes = null)
    {
        // Cloudflare-Host in die Seiten-CSP eintragen (nur Contao 5.x, Service nur dort registriert).
        if (!$this->turnstileFallback) {
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
