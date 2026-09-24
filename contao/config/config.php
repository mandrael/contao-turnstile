<?php

use Mandrael\ContaoTurnstileBundle\Backend\SpamArchiveController;
use Mandrael\ContaoTurnstileBundle\FormField\FormTurnstile;

// Standard-CAPTCHA global durch Turnstile ersetzen. Greift überall, wo der Core das
// Captcha-Widget über die FFL-Registry auflöst (Formulargenerator, Registrierung, Kommentare).
$GLOBALS['TL_FFL']['captcha'] = FormTurnstile::class;

// Backend-Modul "Spam-Ablage": Liste über die normale DC_Table-Ansicht, Einzelansicht + "Doch zustellen"
// über den Key "view" (SpamArchiveController als Service, siehe config/services.yaml).
$GLOBALS['BE_MOD']['system']['turnstile_spam'] = [
    'tables' => ['tl_turnstile_spam', 'tl_turnstile_spam_message'],
    'view' => [SpamArchiveController::class, 'view'],
];
