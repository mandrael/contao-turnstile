<?php

$GLOBALS['TL_LANG']['tl_settings']['turnstile_legend'] = 'Cloudflare Turnstile';

$GLOBALS['TL_LANG']['tl_settings']['turnstileSiteKey'] = [
    'Site key',
    'Public site key from the Cloudflare dashboard. Every domain of the installation must be listed in the Turnstile widget, otherwise verification fails.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSecretKey'] = [
    'Secret key',
    'Secret key from the Cloudflare dashboard. The server uses it to verify the token with Cloudflare; it is never sent to the browser.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileMode'] = [
    'Turnstile activation',
    'Whether Turnstile applies to all forms or only selected ones.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileTheme'] = [
    'Appearance theme',
    'Colour scheme of the Turnstile widget.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSize'] = [
    'Size',
    'Size of the Turnstile widget.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearance'] = [
    'Widget display',
    'When the widget becomes visible. “Show after form interaction” only works if the form is prepared for it.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileModeOptions'] = [
    'optout' => 'Enable for all forms by default',
    'optin' => 'Enable only for selected forms',
    'off' => 'Disable everywhere',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileThemeOptions'] = [
    'light' => 'Light',
    'dark' => 'Dark',
    'auto' => 'Automatic (match page)',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSizeOptions'] = [
    'normal' => 'Normal',
    'flexible' => 'Flexible',
    'compact' => 'Compact',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearanceOptions'] = [
    'always' => 'Always show',
    'interaction-only' => 'Show only when interaction is required',
    'execute' => 'Show after form interaction',
];
