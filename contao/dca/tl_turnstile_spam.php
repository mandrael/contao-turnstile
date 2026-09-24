<?php

use Contao\Backend;
use Contao\Database;
use Contao\DataContainer;
use Contao\Date;
use Contao\DC_Table;
use Contao\StringUtil;

/*
 * Liste der als „Spam sicher" eingestuften Einsendungen. Bearbeiten und Kopieren gibt es nicht – nur Ansehen
 * (eigene Seite über key=view, siehe SpamArchiveController) und Löschen. Löschen nimmt die Mails aus
 * tl_turnstile_spam_message mit (ctable-Kaskade in DC_Table::delete()).
 */
$GLOBALS['TL_DCA']['tl_turnstile_spam'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_turnstile_spam_message'],
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'backendSearchIgnore' => true,
        'ondelete_callback' => [[tl_turnstile_spam::class, 'dropUndo']],
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'created' => 'index',
                'label' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['created DESC'],
            'panelLayout' => 'filter;limit',
        ],
        'label' => [
            'fields' => ['created', 'source', 'score', 'reasons', 'subject', 'label'],
            'showColumns' => true,
            'label_callback' => [tl_turnstile_spam::class, 'formatColumns'],
        ],
        'operations' => [
            'view' => [
                'href' => 'key=view',
                'icon' => 'show.svg',
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\''.($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '').'\'))return false;Backend.getScrollOffset()"',
            ],
        ],
    ],

    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'created' => [
            'label' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['created'],
            'filter' => true,
            'sorting' => true,
            'flag' => DataContainer::SORT_DAY_DESC,
            'eval' => ['rgxp' => 'datim'],
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'source' => [
            'label' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['source'],
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['sources'],
            'sorting' => true,
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        'score' => [
            'label' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['score'],
            'sorting' => true,
            'sql' => "smallint(6) NOT NULL default 0",
        ],
        'reasons' => [
            'label' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasons'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'subject' => [
            'label' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['subject'],
            'search' => true,
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'recipients' => [
            'sql' => "text NULL",
        ],
        'preview' => [
            'sql' => "text NULL",
        ],
        'digested' => [
            'sql' => "tinyint(1) unsigned NOT NULL default 0",
        ],
        'delivered' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'label' => [
            'label' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['label'],
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_turnstile_spam']['labels'],
            'sorting' => true,
            'sql' => "varchar(16) NOT NULL default 'unreviewed'",
        ],
        'label_by' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'label_at' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'rule_version' => [
            'sql' => "varchar(16) NOT NULL default ''",
        ],
    ],
];

/**
 * Formatierung der Listenspalten: Etikett/Status zusammengefasst, Signale gekürzt. Reine Anzeigelogik, deshalb
 * hier statt im Controller.
 *
 * @internal
 */
class tl_turnstile_spam extends Backend
{
    public function formatColumns(array $row, string|array $label, DataContainer $dc, array $args): array
    {
        // $args ist nach der Reihenfolge von label.fields indiziert, nicht nach Feldnamen.
        $args[1] = $GLOBALS['TL_LANG']['tl_turnstile_spam']['sources'][$row['source']] ?? $row['source'];
        // Contao gibt Listenwerte ungefiltert aus; Betreff und Signale stammen aus fremden Mails.
        $args[3] = StringUtil::specialchars(StringUtil::substr((string) $row['reasons'], 60));
        $args[4] = StringUtil::specialchars((string) $row['subject']);

        $args[5] = match (true) {
            (int) $row['delivered'] > 0 => \sprintf($GLOBALS['TL_LANG']['tl_turnstile_spam']['statusDelivered'] ?? '%s', Date::parse(Date::getNumericDateFormat(), (int) $row['delivered'])),
            'ham' === $row['label'] => $GLOBALS['TL_LANG']['tl_turnstile_spam']['statusHam'] ?? 'ham',
            default => $GLOBALS['TL_LANG']['tl_turnstile_spam']['statusUnreviewed'] ?? 'unreviewed',
        };

        return $args;
    }

    /**
     * Contao kopiert gelöschte Zeilen samt Mailtext nach tl_undo (Standard 30 Tage). Die Ablage soll nach dem
     * Löschen aber wirklich weg sein, deshalb den Undo-Eintrag gleich mit entfernen.
     */
    public function dropUndo(DataContainer $dc, int $undoId): void
    {
        Database::getInstance()->prepare('DELETE FROM tl_undo WHERE id = ?')->execute($undoId);
    }
}
