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
        $value = null !== $request ? ($request->request->all()['cf-turnstile-response'] ?? null) : null;
        $token = \is_string($value) ? $value : '';

        if (!$this->getVerifier()->validate($token)) {
            $this->class = 'error';
            $this->addError($GLOBALS['TL_LANG']['ERR']['turnstile'] ?? 'Captcha validation failed.');
        }
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
