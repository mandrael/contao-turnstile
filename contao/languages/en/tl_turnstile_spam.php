<?php

$GLOBALS['TL_LANG']['tl_turnstile_spam']['created'] = ['Date', 'Time the entry was archived.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['source'] = ['Source', 'Form, comment or registration.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['score'] = ['Score', 'Classification score.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasons'] = ['Signals', 'Signals that led to the classification.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['subject'] = ['Subject', 'Subject of the first archived mail.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['label'] = ['Label', 'unreviewed = not checked yet, ham = "deliver anyway" was triggered.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['ruleVersion'] = ['Rule version', 'Bundle version at the time of classification.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['preview'] = ['Preview', 'Text of the first mail, truncated.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['recipients'] = 'Recipients';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['messages'] = 'Mails of this submission';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['error'] = 'Error';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['sentAt'] = 'Sent at';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['sentMeansAccepted'] = '"Sent" means the mail server accepted it.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['commentHint'] = 'Delivering only sends the mails; publishing still happens in the comments module, subscribers are not notified retroactively.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['backToList'] = 'Back to the list';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deliver'] = 'Deliver anyway';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['retryUnclear'] = 'Send again anyway - possible duplicate delivery';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['delivered'] = '%d mail(s) sent, %d failed, %d unclear.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['alreadyDelivered'] = 'Already delivered on %s.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['notFound'] = 'Entry not found.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['systemMessage'] = '%d unreviewed entries in the spam archive (deleted after 90 days).';

$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusUnreviewed'] = 'unreviewed';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusHam'] = 'not spam, still open';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusDelivered'] = 'delivered on %s';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusUnclear'] = 'unclear result';

$GLOBALS['TL_LANG']['tl_turnstile_spam']['status'] = [
    'stored' => 'stored',
    'sending' => 'sending',
    'sent' => 'sent',
    'failed' => 'failed',
    'unclear' => 'unclear result',
];

$GLOBALS['TL_LANG']['tl_turnstile_spam']['view'] = ['View', 'View entry %s'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['delete'] = ['Delete', 'Delete entry %s'];
