<?php

$GLOBALS['TL_LANG']['tl_settings']['turnstile_legend'] = 'Cloudflare Turnstile';

$GLOBALS['TL_LANG']['tl_settings']['turnstileSiteKey'] = [
    'Site Key',
    'Öffentlicher Site Key aus dem Cloudflare-Dashboard. Alle Domains der Installation müssen im Turnstile-Widget hinterlegt sein, sonst schlägt die Prüfung fehl.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSecretKey'] = [
    'Secret Key',
    'Geheimer Schlüssel aus dem Cloudflare-Dashboard. Der Server prüft damit das Token bei Cloudflare; er wird nie an den Browser ausgeliefert.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileMode'] = [
    'Turnstile-Aktivierung',
    'Gilt Turnstile für alle Formulare oder nur für ausgewählte.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureMode'] = [
    'Verhalten, wenn Turnstile-Prüfung fehlschlägt',
    'Turnstile kann False-Positives haben (Firewalls, Safari, spezielle Konfigurationen). Standard ist, dass das Formular dann hart abgewiesen wird. Deswegen gibt es einen Fallback-Modus, der solche Fälle mitigiert.',
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
    'Widget-Anzeige',
    'Wann das Widget sichtbar wird. „Nach Formular-Interaktion anzeigen“ greift nur mit dafür vorbereitetem Formular.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSendRemoteIp'] = [
    'Besucher-IP an Cloudflare senden',
    'Sendet die Besucher-IP zur Prüfung an Cloudflare (Standard). Hinter NAT/VPN/iCloud Private Relay kann Abschalten helfen; Cloudflare prüft die IP nicht strikt. Hygiene-Option, kein garantierter Safari-Fix.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileModeOptions'] = [
    'optout' => 'Standardmäßig für alle Formulare aktivieren',
    'optin' => 'Nur bei ausgewählten Formularen aktivieren',
    'off' => 'Überall deaktivieren',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureModeOptions'] = [
    'block' => 'Blockieren: Formular-Absenden wird verhindert (Standard)',
    'filter' => 'Fallback: Honeypot & Zeitprüfung (Rest durchlassen, protokollieren)',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileThemeOptions'] = [
    'light' => 'Hell',
    'dark' => 'Dunkel',
    'auto' => 'Automatisch (an System anpassen)',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSizeOptions'] = [
    'normal' => 'Normal',
    'flexible' => 'Flexibel',
    'compact' => 'Kompakt',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearanceOptions'] = [
    'always' => 'Immer anzeigen',
    'interaction-only' => 'Nur bei erforderlicher Interaktion anzeigen',
    'execute' => 'Nach Formular-Interaktion anzeigen',
];
