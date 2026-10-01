<?php

use Mandrael\ContaoTurnstileBundle\Backend\SpamArchiveController;
use Mandrael\ContaoTurnstileBundle\FormField\FormTurnstile;

// Standard-CAPTCHA global durch Turnstile ersetzen. Greift überall, wo der Core das
// Captcha-Widget über die FFL-Registry auflöst (Formulargenerator, Registrierung, Kommentare).
$GLOBALS['TL_FFL']['captcha'] = FormTurnstile::class;

// Backend-Modul "Spam-Ablage": Standardansicht ist der Posteingang (Key "feed", tl_turnstile_spam leitet ohne
// mode=table dorthin um), Einzelansicht + "Doch zustellen" über den Key "view", die DC_Table-Liste unter
// mode=table (SpamArchiveController als Service, siehe config/services.yaml).
$GLOBALS['BE_MOD']['system']['turnstile_spam'] = [
    'tables' => ['tl_turnstile_spam', 'tl_turnstile_spam_message'],
    'feed' => [SpamArchiveController::class, 'feed'],
    'view' => [SpamArchiveController::class, 'view'],
    'stylesheet' => 'bundles/mandraelcontaoturnstile/spam-archive.css',
    'javascript' => 'bundles/mandraelcontaoturnstile/spam-archive.js',
];
