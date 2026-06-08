<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

PaletteManipulator::create()
    ->addField('turnstileField', 'placeholder', PaletteManipulator::POSITION_AFTER)
    ->applyToPalette('captcha', 'tl_form_field');

$GLOBALS['TL_DCA']['tl_form_field']['fields']['turnstileField'] = [
    'inputType' => 'select',
    'options' => ['', 'on', 'off'],
    'reference' => &$GLOBALS['TL_LANG']['tl_form_field']['turnstileFieldOptions'],
    'eval' => ['tl_class' => 'w50', 'includeBlankOption' => false],
    'sql' => "varchar(8) NOT NULL default ''",
];
