<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

PaletteManipulator::create()
    ->addField('turnstileDisabled', 'placeholder', PaletteManipulator::POSITION_AFTER)
    ->applyToPalette('captcha', 'tl_form_field');

$GLOBALS['TL_DCA']['tl_form_field']['fields']['turnstileDisabled'] = [
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => "char(1) NOT NULL default ''",
];
