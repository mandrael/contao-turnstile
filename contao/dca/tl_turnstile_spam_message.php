<?php

use Contao\DC_Table;

/*
 * Je abgelegter Mail eine Zeile. Nie eigenständig im Backend gelistet oder bearbeitet – Anzeige und „Doch
 * zustellen" laufen über die Kopftabelle tl_turnstile_spam (SpamArchiveController::view()). Der Mailtext
 * (mime) wird deshalb nie in einer Liste/Anzeige der Kopftabelle mitgeladen.
 */
$GLOBALS['TL_DCA']['tl_turnstile_spam_message'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_turnstile_spam',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
            ],
        ],
    ],

    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'pid' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'mime' => [
            'sql' => 'longblob NULL',
        ],
        'sender' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'recipients' => [
            'sql' => "text NULL",
        ],
        'transport' => [
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'status' => [
            'sql' => "varchar(16) NOT NULL default 'stored'",
        ],
        'claimed_at' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'sent_at' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'error' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
    ],
];
