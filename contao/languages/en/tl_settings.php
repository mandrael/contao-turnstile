<?php

$GLOBALS['TL_LANG']['tl_settings']['turnstile_legend'] = 'Cloudflare Turnstile';

$GLOBALS['TL_LANG']['tl_settings']['turnstileSiteKey'] = [
    'Site key',
    'Public site key from the Cloudflare Turnstile dashboard. Important: the Turnstile widget on Cloudflare must list every domain (hostname) this Contao installation is reachable under, otherwise verification fails. If site key and secret key are empty, Contao automatically falls back to the default security question.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSecretKey'] = [
    'Secret key',
    'Secret key from the Cloudflare Turnstile dashboard. Used server-side only to verify the token.',
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
    'Visibility',
    'Controls when the widget becomes visible.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileThemeOptions'] = [
    'auto' => 'Automatic (match page)',
    'light' => 'Light',
    'dark' => 'Dark',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSizeOptions'] = [
    'normal' => 'Normal',
    'flexible' => 'Flexible',
    'compact' => 'Compact',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearanceOptions'] = [
    'always' => 'Always visible',
    'execute' => 'On execute',
    'interaction-only' => 'Interaction only',
];
