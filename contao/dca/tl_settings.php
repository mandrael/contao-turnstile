<?php

use Contao\Config;
use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\StringUtil;

PaletteManipulator::create()
    ->addLegend('turnstile_legend', 'global_legend', PaletteManipulator::POSITION_AFTER)
    ->addField('turnstileSiteKey', 'turnstile_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('turnstileSecretKey', 'turnstile_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('turnstileMode', 'turnstile_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('turnstileTheme', 'turnstile_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('turnstileSize', 'turnstile_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('turnstileAppearance', 'turnstile_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_settings');

$GLOBALS['TL_DCA']['tl_settings']['fields']['turnstileSiteKey'] = [
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'maxlength' => 255, 'decodeEntities' => true, 'autocomplete' => 'off'],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['turnstileSecretKey'] = [
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'maxlength' => 255, 'hideInput' => true, 'decodeEntities' => true, 'autocomplete' => 'new-password'],
    // letzte 4 Zeichen des gespeicherten Secrets neben dem Label zeigen (Verifikation, ohne den Wert preiszugeben)
    'xlabel' => [
        static function (): string {
            $secret = (string) Config::get('turnstileSecretKey');

            if ('' === $secret) {
                return '';
            }

            return '<span style="margin-left:.5em;color:#999;font-weight:400">(…' . StringUtil::specialchars(substr($secret, -4)) . ')</span>';
        },
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['turnstileMode'] = [
    'inputType' => 'select',
    'options' => ['optout', 'optin', 'off'],
    'reference' => &$GLOBALS['TL_LANG']['tl_settings']['turnstileModeOptions'],
    'eval' => ['tl_class' => 'w50 clr', 'includeBlankOption' => false],
    'default' => 'optout',
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['turnstileTheme'] = [
    'inputType' => 'select',
    'options' => ['light', 'dark', 'auto'],
    'reference' => &$GLOBALS['TL_LANG']['tl_settings']['turnstileThemeOptions'],
    'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
    'default' => 'light',
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['turnstileSize'] = [
    'inputType' => 'select',
    'options' => ['normal', 'flexible', 'compact'],
    'reference' => &$GLOBALS['TL_LANG']['tl_settings']['turnstileSizeOptions'],
    'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['turnstileAppearance'] = [
    'inputType' => 'select',
    'options' => ['always', 'interaction-only', 'execute'],
    'reference' => &$GLOBALS['TL_LANG']['tl_settings']['turnstileAppearanceOptions'],
    'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
];
