<?php

$GLOBALS['TL_LANG']['tl_turnstile_spam']['created'] = ['Datum', 'Zeitpunkt der Ablage.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['source'] = ['Quelle', 'Formular, Kommentar oder Registrierung.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['score'] = ['Punkte', 'Punktzahl der Einstufung.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasons'] = ['Signale', 'Verdachtssignale, die zur Einstufung geführt haben.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['subject'] = ['Betreff', 'Betreff der ersten abgelegten Mail.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['label'] = ['Status', 'Ungeprüft, Spam, kein Spam oder zugestellt.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['ruleVersion'] = ['Regelversion', 'Bundle-Version zum Zeitpunkt der Einstufung.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['preview'] = ['Vorschau', 'Text der ersten Mail, gekürzt.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['recipients'] = 'Empfänger';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['messages'] = 'Mails dieser Einsendung';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['error'] = 'Fehler';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['sentAt'] = 'Versendet am';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['sentMeansAccepted'] = '„Versendet“ heißt: vom Mailserver angenommen.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['commentHint'] = 'Zustellen verschickt nur die Mails; veröffentlicht wird weiterhin im Kommentar-Modul, Abonnenten werden nicht nachträglich benachrichtigt.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['backToList'] = 'Zurück zur Liste';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deliver'] = 'Doch zustellen';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['retryUnclear'] = 'Trotzdem erneut senden – mögliche Doppelzustellung';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['delivered'] = '%d Mail(s) versendet, %d fehlgeschlagen, %d Ergebnis unklar.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['alreadyDelivered'] = 'Bereits zugestellt am %s.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['notFound'] = 'Eintrag nicht gefunden.';

$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusUnreviewed'] = 'ungeprüft';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusHam'] = 'kein Spam, noch nicht zugestellt';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusDelivered'] = 'zugestellt am %s';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusUnclear'] = 'Ergebnis unklar';

$GLOBALS['TL_LANG']['tl_turnstile_spam']['status'] = [
    'stored' => 'abgelegt',
    'sending' => 'wird gesendet',
    'sent' => 'versendet',
    'failed' => 'fehlgeschlagen',
    'unclear' => 'Ergebnis unklar',
];

$GLOBALS['TL_LANG']['tl_turnstile_spam']['view'] = ['Ansehen', 'Eintrag %s ansehen'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['delete'] = ['Löschen', 'Eintrag %s löschen'];

$GLOBALS['TL_LANG']['tl_turnstile_spam']['sources'] = ['form' => 'Formular', 'comment' => 'Kommentar', 'registration' => 'Registrierung'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['labels'] = ['unreviewed' => 'ungeprüft', 'ham' => 'kein Spam', 'spam' => 'Spam'];

$GLOBALS['TL_LANG']['tl_turnstile_spam']['feed'] = ['Posteingang', 'Posteingang'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['tableView'] = 'Tabellenansicht';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['filters'] = ['unreviewed' => 'Ungeprüft', 'spam' => 'Spam', 'ham' => 'Kein Spam', 'all' => 'Alle'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['points'] = '%d Punkte';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['details'] = 'Details';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['markSpam'] = 'Spam bestätigen';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['markAllSpam'] = 'Alle %d angezeigten als Spam bestätigen';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deliverNotSpam'] = 'Kein Spam – zustellen';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['confirmDeliver'] = 'Die Mails dieser Einsendung jetzt wirklich zustellen?';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['confirmDelete'] = 'Diesen Eintrag endgültig löschen?';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['markedSpam'] = '%d als Spam bestätigt.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deleted'] = '%d gelöscht.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['feedEmpty'] = 'Keine Einträge.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['prev'] = 'Zurück';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['next'] = 'Weiter';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['pageOf'] = 'Seite %d von %d';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusSpam'] = 'Spam, bestätigt am %s';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasonLabels'] = [
    'gibberish-many' => 'Zeichensalat in mehreren Feldern',
    'gibberish-one' => 'Zeichensalat in einem Feld',
    'link' => 'Link im Text',
    'repeat' => 'gleicher Text aus mehreren Netzen',
    'dotted-address' => 'Mailadresse mit Punkten zerstückelt',
    'no-mx' => 'Maildomain ohne Mailserver',
    'tor-exit' => 'gesendet über Tor',
    'net-burst' => 'viele Einsendungen aus einem Netz',
    'ai-spam' => 'KI: Spam',
    'ai-clean' => 'KI: unauffällig',
];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['done'] = ['spam' => 'Als Spam bestätigt', 'delete' => 'Gelöscht'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['ajaxUnclear'] = 'Ergebnis unklar – bitte Seite neu laden.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusSpamAuto'] = 'Spam, automatisch bestätigt am %s';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['patternProgress'] = 'Muster %d/%d bestätigt, danach automatisch Spam';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['patternBlocked'] = 'Wird nie automatisch bestätigt (einmal als Kein Spam markiert)';
