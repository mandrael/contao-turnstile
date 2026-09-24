<?php

$GLOBALS['TL_LANG']['tl_turnstile_spam']['created'] = ['Datum', 'Zeitpunkt der Ablage.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['source'] = ['Quelle', 'Formular, Kommentar oder Registrierung.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['score'] = ['Punkte', 'Punktzahl der Einstufung.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasons'] = ['Signale', 'Verdachtssignale, die zur Einstufung geführt haben.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['subject'] = ['Betreff', 'Betreff der ersten abgelegten Mail.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['label'] = ['Etikett', 'unreviewed = noch nicht geprüft, ham = „Doch zustellen" ausgelöst.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['ruleVersion'] = ['Regelversion', 'Bundle-Version zum Zeitpunkt der Einstufung.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['preview'] = ['Vorschau', 'Text der ersten Mail, gekürzt.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['recipients'] = 'Empfänger';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['messages'] = 'Mails dieser Einsendung';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['error'] = 'Fehler';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['sentAt'] = 'Versendet am';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['sentMeansAccepted'] = '„Versendet" heißt: vom Mailserver angenommen.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['commentHint'] = 'Zustellen verschickt nur die Mails; veröffentlicht wird weiterhin im Kommentar-Modul, Abonnenten werden nicht nachträglich benachrichtigt.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['backToList'] = 'Zurück zur Liste';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deliver'] = 'Doch zustellen';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['retryUnclear'] = 'Trotzdem erneut senden – mögliche Doppelzustellung';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['delivered'] = '%d Mail(s) versendet, %d fehlgeschlagen, %d Ergebnis unklar.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['alreadyDelivered'] = 'Bereits zugestellt am %s.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['notFound'] = 'Eintrag nicht gefunden.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['systemMessage'] = '%d ungeprüfte Einträge in der Spam-Ablage (werden nach 90 Tagen gelöscht).';

$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusUnreviewed'] = 'ungeprüft';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusHam'] = 'kein Spam, noch offen';
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
