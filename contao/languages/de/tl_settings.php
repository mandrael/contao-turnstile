<?php

$GLOBALS['TL_LANG']['tl_settings']['turnstile_legend'] = 'Cloudflare Turnstile';

$GLOBALS['TL_LANG']['tl_settings']['turnstileSiteKey'] = [
    'Site Key',
    'Öffentlicher Site Key aus dem Cloudflare-Turnstile-Dashboard. Wichtig: Im Turnstile-Widget bei Cloudflare müssen alle Domains (Hostnames) eingetragen sein, unter denen diese Contao-Installation erreichbar ist – sonst schlägt die Überprüfung fehl. Sind Site Key und Secret Key leer, nutzt Contao automatisch wieder die Standard-Sicherheitsfrage.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSecretKey'] = [
    'Secret Key',
    'Geheimer Schlüssel aus dem Cloudflare-Turnstile-Dashboard. Wird ausschließlich serverseitig zur Token-Überprüfung verwendet.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileTheme'] = [
    'Erscheinungsbild',
    'Farbschema des Turnstile-Widgets.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSize'] = [
    'Größe',
    'Größe des Turnstile-Widgets.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearance'] = [
    'Anzeige',
    'Legt fest, wann das Widget sichtbar wird.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileThemeOptions'] = [
    'auto' => 'Automatisch (an Seite anpassen)',
    'light' => 'Hell',
    'dark' => 'Dunkel',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSizeOptions'] = [
    'normal' => 'Normal',
    'flexible' => 'Flexibel',
    'compact' => 'Kompakt',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearanceOptions'] = [
    'always' => 'Immer sichtbar',
    'execute' => 'Bei Ausführung',
    'interaction-only' => 'Nur bei Interaktion',
];
