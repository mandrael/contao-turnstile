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

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureMode'] = [
    'Behaviour without a valid Turnstile token',
    'By default the form is rejected. Recommended for registration, booking and contact forms is the fallback stage: it checks submissions without a token mechanically (hidden fields, minimum time, proof of work) and then accepts them. Only if several independent signals (content, sender address, Tor network) together clearly indicate spam, no confirmation goes to the entered address; the site operator still receives the submission with [Spam] in the subject.',
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

$GLOBALS['TL_LANG']['tl_settings']['turnstileSendRemoteIp'] = [
    'Send visitor IP to Cloudflare',
    'Sends the visitor IP to Cloudflare for verification (default). Behind NAT/VPN/iCloud Private Relay turning it off may help; Cloudflare does not validate the IP strictly. Hygiene option, not a guaranteed Safari fix.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileModeOptions'] = [
    'optout' => 'Enable for all forms by default',
    'optin' => 'Enable only for selected forms',
    'off' => 'Disable everywhere',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureModeOptions'] = [
    'block' => 'Block: prevent form submission (default)',
    'altcha' => 'Fallback stage: mechanical check with proof of work, then accept (recommended for important forms)',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileThemeOptions'] = [
    'light' => 'Light',
    'dark' => 'Dark',
    'auto' => 'Automatic (match system)',
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

$GLOBALS['TL_LANG']['tl_settings']['turnstileSpamDigest'] = [
    'Send a daily digest',
    'Sends one daily mail listing new entries in the spam archive (date, source, score, signals, subject - no message content). Off by default; a digest is not mandatory.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSpamDigestEmail'] = [
    'Digest recipient',
    'Address for the daily digest. Empty uses the administrator e-mail address.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatus'] = [
    'AI classification',
    'Set up via TURNSTILE_AI_KEY in .env.local; the key is never shown here.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusActive'] = 'active - %s, %s, %d of %d used today';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusOff'] = 'off';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusHint'] = 'Set up via TURNSTILE_AI_KEY in .env.local.';
