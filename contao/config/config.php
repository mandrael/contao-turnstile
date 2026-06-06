<?php

use Mandrael\ContaoTurnstileBundle\FormField\FormTurnstile;

// Standard-CAPTCHA global durch Turnstile ersetzen. Greift ueberall, wo der Core das
// Captcha-Widget ueber die FFL-Registry aufloest (Formulargenerator, Registrierung, Kommentare).
$GLOBALS['TL_FFL']['captcha'] = FormTurnstile::class;
