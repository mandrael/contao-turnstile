<?php

$GLOBALS['TL_LANG']['tl_settings']['turnstile_legend'] = 'Cloudflare Turnstile';

$GLOBALS['TL_LANG']['tl_settings']['turnstileSiteKey'] = [
    'Site key',
    'From the Cloudflare dashboard; list every domain of this installation there.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSecretKey'] = [
    'Secret key',
    'From the Cloudflare dashboard. Stays on the server.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileMode'] = [
    'Use Turnstile',
    'Individual form fields can be set differently.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureMode'] = [
    'If Turnstile fails',
    'Without a valid token: check via ALTCHA, certain spam goes to the spam archive.',
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
    '“Show after form interaction” needs a form prepared for it.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSendRemoteIp'] = [
    'Send visitor IP to Cloudflare',
    'Default: on. Turn off if verification often fails behind a VPN or iCloud Private Relay.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileModeOptions'] = [
    'optout' => 'In all forms',
    'optin' => 'Only in selected forms',
    'off' => 'Nowhere',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureModeOptions'] = [
    'block' => 'Reject the submission (default)',
    'altcha' => 'Fallback check with ALTCHA, then accept and classify (recommended)',
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
    'One daily mail with the new entries in the spam archive, without submission content.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSpamDigestEmail'] = [
    'Digest recipient',
    'Address for the daily digest. Empty uses the administrator e-mail address.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiKey'] = [
    'Mistral API key',
    'Optional, from console.mistral.ai. Then add Mistral to your privacy policy.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatus'] = [
    'AI spam detection',
    'Status of the AI classification.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusActive'] = 'On: %s, model %s, %d of %d requests used today.';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiExplain'] = 'Adds to the checks, replaces nothing: Turnstile, ALTCHA and the scoring always run. Mistral is only asked about borderline cases (suspicious, but not certain spam). If Mistral judges “certain spam”, the submission goes to the spam archive, otherwise it is processed normally, also on errors or timeouts. Only text fields and the e-mail address are sent, never the IP. At most %d requests per day.';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusOff'] = 'Off: no key set. TURNSTILE_AI_KEY in .env.local takes precedence over the field.';
