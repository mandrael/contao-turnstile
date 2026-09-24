<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Backend;

use Contao\DataContainer;
use Contao\StringUtil;
use Mandrael\ContaoTurnstileBundle\Service\AiSpamJudge;

/**
 * Reine Anzeige in den Turnstile-Einstellungen (input_field_callback, kein sql/Wert): zeigt, ob die
 * KI-Einordnung aktiv ist, ohne den Schlüssel preiszugeben. Einrichtung läuft über .env.local.
 */
class AiStatusField
{
    public function __construct(private readonly AiSpamJudge $judge)
    {
    }

    public function render(DataContainer $dc, string $xlabel = ''): string
    {
        $status = $this->judge->status();
        $lang = &$GLOBALS['TL_LANG']['tl_settings'];

        $text = $status['active']
            ? \sprintf($lang['turnstileAiStatusActive'] ?? 'active - %s, %s, %d of %d used today', $status['provider'], $status['model'], $status['used'], $status['budget'])
            : ($lang['turnstileAiStatusOff'] ?? 'off');

        return '<div class="tl_info" style="margin-bottom:1em">'.StringUtil::specialchars($text).'<br>'.($lang['turnstileAiStatusHint'] ?? 'Set up via TURNSTILE_AI_KEY in .env.local.').'</div>';
    }
}
