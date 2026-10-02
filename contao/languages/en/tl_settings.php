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
    'Without a valid token: check via ALTCHA, suspected spam goes to the spam archive.',
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
    'Optional, from console.mistral.ai. Leave empty to keep the stored key, “-” deletes it.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiBudget'] = [
    'AI requests per day',
    'Cap against costs during bot waves. Empty = 150.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatus'] = [
    'AI spam detection',
    'Status of the AI classification.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusActive'] = 'On: %s, %d of %d requests used today.';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiExplain'] = 'If Turnstile fails, fixed rules check for spam. If a case remains unclear, %3$s decides whether the submission goes to the spam archive or is processed normally. Any submission there can be delivered later. Without a key or once the daily limit is reached, unclear cases are processed normally. Mistral only receives the text and e-mail address.';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusOff'] = 'Off: no key set. TURNSTILE_AI_KEY in .env.local takes precedence over the field.';
