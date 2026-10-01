<?php

$GLOBALS['TL_LANG']['tl_turnstile_spam']['created'] = ['Date', 'Time the entry was archived.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['source'] = ['Source', 'Form, comment or registration.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['score'] = ['Score', 'Classification score.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasons'] = ['Signals', 'Signals that led to the classification.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['subject'] = ['Subject', 'Subject of the first archived mail.'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['label'] = ['Status', 'Unreviewed, confirmed spam, not spam ("deliver anyway" triggered) or delivered.'];
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

$GLOBALS['TL_LANG']['tl_turnstile_spam']['sources'] = ['form' => 'Form', 'comment' => 'Comment', 'registration' => 'Registration'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['labels'] = ['unreviewed' => 'unreviewed', 'ham' => 'not spam', 'spam' => 'spam'];

$GLOBALS['TL_LANG']['tl_turnstile_spam']['feed'] = ['Inbox', 'Inbox'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['tableView'] = 'Table view';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['filters'] = ['unreviewed' => 'Unreviewed', 'spam' => 'Spam', 'ham' => 'Not spam', 'all' => 'All'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['points'] = '%d points';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['details'] = 'Details';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['markSpam'] = 'Confirm spam';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['markAllSpam'] = 'Confirm all %d shown as spam';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deliverNotSpam'] = 'Not spam – deliver';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['confirmDeliver'] = 'Really deliver the mails of this submission now?';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['confirmDelete'] = 'Delete this entry permanently?';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['markedSpam'] = '%d confirmed as spam.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['deleted'] = '%d deleted.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['feedEmpty'] = 'No entries.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['prev'] = 'Previous';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['next'] = 'Next';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['pageOf'] = 'Page %d of %d';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusSpam'] = 'spam, confirmed on %s';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['reasonLabels'] = [
    'gibberish-many' => 'gibberish in several fields',
    'gibberish-one' => 'gibberish in one field',
    'link' => 'link in text',
    'repeat' => 'same text from several networks',
    'dotted-address' => 'e-mail address split by dots',
    'no-mx' => 'mail domain without mail server',
    'tor-exit' => 'sent via Tor',
    'net-burst' => 'many submissions from one network',
    'ai-spam' => 'AI: spam',
    'ai-clean' => 'AI: clean',
];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['done'] = ['spam' => 'Confirmed as spam', 'delete' => 'Deleted'];
$GLOBALS['TL_LANG']['tl_turnstile_spam']['ajaxUnclear'] = 'Unclear result – please reload the page.';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['statusSpamAuto'] = 'spam, auto-confirmed on %s';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['patternProgress'] = 'pattern %d of %d confirmed – then automatic';
$GLOBALS['TL_LANG']['tl_turnstile_spam']['patternBlocked'] = 'pattern never automatic (marked not spam before)';
