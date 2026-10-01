<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Backend;

use Contao\DataContainer;
use Contao\StringUtil;
use Mandrael\ContaoTurnstileBundle\Service\AiSpamJudge;

/**
 * Reine Anzeige in den Turnstile-Einstellungen (input_field_callback, kein sql/Wert): zeigt, ob die
 * KI-Einordnung aktiv ist, ohne den Schlüssel preiszugeben. Einrichtung über das Feld turnstileAiKey oder .env.local.
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

        // input_field_callback übernimmt kein tl_class: Widget-Hülle mit clr selbst setzen, sonst rutscht die
        // Infobox unter die gefloateten w50-Felder und ihr (i) landet oben links im Abschnitt.
        return '<div class="widget clr"><h3><label>'.($lang['turnstileAiStatus'][0] ?? 'Mistral AI spam detection').'</label></h3>'
            .'<p class="tl_info">'.StringUtil::specialchars($text).'<br>'.($lang['turnstileAiStatusHint'] ?? 'Enter the key in the Mistral API key field.').'</p></div>';
    }
}
