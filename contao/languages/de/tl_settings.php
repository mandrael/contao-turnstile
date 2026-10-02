<?php

$GLOBALS['TL_LANG']['tl_settings']['turnstile_legend'] = 'Cloudflare Turnstile';

$GLOBALS['TL_LANG']['tl_settings']['turnstileSiteKey'] = [
    'Site Key',
    'Aus dem Cloudflare-Dashboard; dort alle Domains dieser Installation eintragen.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSecretKey'] = [
    'Secret Key',
    'Aus dem Cloudflare-Dashboard. Bleibt auf dem Server.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileMode'] = [
    'Turnstile einsetzen',
    'Einzelne Formularfelder lassen sich abweichend einstellen.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureMode'] = [
    'Wenn Turnstile scheitert',
    'Ohne gültiges Token: Prüfung per ALTCHA, bei Spam-Verdacht in die Spam-Ablage.',
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
    '„Nach Formular-Interaktion“ braucht ein dafür vorbereitetes Formular.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSendRemoteIp'] = [
    'Besucher-IP an Cloudflare senden',
    'Standard: an. Bei häufigen Fehlschlägen hinter VPN oder iCloud Private Relay abschalten.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileModeOptions'] = [
    'optout' => 'In allen Formularen',
    'optin' => 'Nur in ausgewählten Formularen',
    'off' => 'Nirgends',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileFailureModeOptions'] = [
    'block' => 'Einsendung ablehnen (Standard)',
    'altcha' => 'Ersatzprüfung mit ALTCHA, dann annehmen und einstufen (empfohlen)',
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

$GLOBALS['TL_LANG']['tl_settings']['turnstileSpamDigest'] = [
    'Tageszusammenfassung versenden',
    'Einmal täglich eine Mail mit den neuen Einträgen der Spam-Ablage, ohne Inhalt der Einsendungen.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileSpamDigestEmail'] = [
    'Empfänger der Zusammenfassung',
    'Adresse für die Tageszusammenfassung. Leer verwendet die Administrator-E-Mail-Adresse.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiKey'] = [
    'Mistral-API-Schlüssel',
    'Optional, von console.mistral.ai. Leer lassen behält den gespeicherten, „-“ löscht ihn.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiBudget'] = [
    'KI-Anfragen pro Tag',
    'Obergrenze gegen Kosten bei Bot-Wellen. Leer = 150.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatus'] = [
    'KI-Spamerkennung',
    'Status der KI-Einordnung.',
];

$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusActive'] = 'An: %s, heute %d von %d Anfragen genutzt.';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiExplain'] = 'Scheitert Turnstile, prüfen feste Regeln auf Spam. Bleibt ein Fall unklar, entscheidet %3$s, ob die Einsendung in die Spam-Ablage kommt oder normal verarbeitet wird. Dort kann jede Einsendung nachträglich zugestellt werden. Ohne Schlüssel oder wenn das Tageslimit erreicht ist, werden unklare Fälle normal verarbeitet. An Mistral gehen nur Text und Mailadresse.';
$GLOBALS['TL_LANG']['tl_settings']['turnstileAiStatusOff'] = 'Aus: kein Schlüssel eingetragen. TURNSTILE_AI_KEY in .env.local hat Vorrang vor dem Feld.';
