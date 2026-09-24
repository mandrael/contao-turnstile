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
        } catch (\Throwable) {
            return '';
        }

        if ($count < 1) {
            return '';
        }

        return '<p class="tl_info">'.\sprintf($GLOBALS['TL_LANG']['tl_turnstile_spam']['systemMessage'] ?? '%d unreviewed entries in the spam archive (deleted after 90 days).', $count).'</p>';
    }
}
