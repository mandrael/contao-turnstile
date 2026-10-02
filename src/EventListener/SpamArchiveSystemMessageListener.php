<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\EventListener;

use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;

/**
 * Hook getSystemMessages (4.13 und 5.x): macht die Ablage im Backend sichtbar, unabhängig von der
 * Tageszusammenfassung. Vor der Migration existiert die Tabelle noch nicht – jeder Fehler ergibt deshalb
 * bewusst nur eine leere Meldung statt eines Fehlers auf jeder Backend-Seite.
 */
class SpamArchiveSystemMessageListener
{
    public function __construct(private readonly SpamArchive $archive)
    {
    }

    public function onGetSystemMessages(): string
    {
        try {
            $count = $this->archive->countUnreviewed();
            $auto = $this->archive->countAutoConfirmed();
        } catch (\Throwable) {
            return '';
        }

        $html = '';

        if ($count > 0) {
            $html .= '<p class="tl_info">'.\sprintf($GLOBALS['TL_LANG']['MSC']['turnstileSpamSystemMessage'] ?? '%d unreviewed entries in the spam archive (deleted after 90 days).', $count).'</p>';
        }

        // Automatisch bestätigte Einträge stehen nicht unter „Ungeprüft"; ohne diesen Hinweis bliebe eine fälschlich
        // bestätigte echte Einsendung bis zur Löschung unbemerkt.
        if ($auto > 0) {
            $html .= '<p class="tl_info">'.\sprintf($GLOBALS['TL_LANG']['MSC']['turnstileSpamAutoMessage'] ?? '%d entries automatically confirmed as spam in the last 14 days.', $auto).'</p>';
        }

        return $html;
    }
}
