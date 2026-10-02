<?php

/*
 * Lernstand der Spam-Ablage je Lernmuster (SpamArchive::patternKey). Bleibt beim Aufräumen der Ablage erhalten, damit
 * Bestätigungen und ein „Kein Spam" nicht nach RETENTION_DAYS verloren gehen. Nur Schema, keine Backend-Ansicht.
 */
$GLOBALS['TL_DCA']['tl_turnstile_spam_pattern'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pattern' => 'unique',
            ],
        ],
    ],

    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'pattern' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'confirmed' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'rejected' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'reset_at' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
    ],
];
