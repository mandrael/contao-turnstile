<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Contao\Date;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Eigene Ansicht für einen Ablage-Eintrag (BE_MOD-Key „view", siehe contao/config/config.php) und die
 * „Doch zustellen"-Aktion. Kein Editieren/Kopieren – nur Ansehen, Zustellen, Löschen (Löschen läuft über die
 * normale DC_Table-Aktion der Liste). Liest die Kopf- und Mail-Daten selbst per DBAL, weil SpamArchive keine
 * Einzelabfrage anbietet und dafür nicht verändert werden soll.
 */
class SpamArchiveController
{
    private const MODULE = 'turnstile_spam';

    public function __construct(
        private readonly Connection $db,
        private readonly SpamArchive $archive,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function view(DataContainer $dc): string|RedirectResponse
    {
        $id = (int) $dc->id;
        $head = $this->db->fetchAssociative('SELECT * FROM '.SpamArchive::TABLE.' WHERE id = ?', [$id]);

        if (false === $head) {
            Message::addError($GLOBALS['TL_LANG']['tl_turnstile_spam']['notFound'] ?? 'Not found.');

            return new RedirectResponse($this->listUrl());
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request && $request->isMethod('POST') && 'turnstile_spam_deliver' === $request->request->get('FORM_SUBMIT')) {
            $result = $this->archive->deliver($id, $this->currentUserId(), (bool) $request->request->get('retryUnclear'));

            Message::addConfirmation(\sprintf(
                $GLOBALS['TL_LANG']['tl_turnstile_spam']['delivered'] ?? '%d sent, %d failed, %d unclear.',
                $result['sent'],
                $result['failed'],
                $result['unclear'],
            ));

            return new RedirectResponse($this->viewUrl($id));
        }

        $messages = $this->db->fetchAllAssociative(
            'SELECT id, sender, recipients, transport, status, sent_at, error, claimed_at FROM '.SpamArchive::MESSAGE_TABLE.' WHERE pid = ? ORDER BY id',
            [$id],
        );

        return $this->render($head, $messages);
    }

    /**
     * @param array<string, mixed>        $head
     * @param list<array<string, mixed>>  $messages
     */
    private function render(array $head, array $messages): string
    {
        $lang = &$GLOBALS['TL_LANG']['tl_turnstile_spam'];
        $now = time();
        $hasUnclear = false;
        $rows = '';

        foreach ($messages as $message) {
            $unclear = 'sending' === $message['status'] && (int) $message['claimed_at'] < $now - SpamArchive::UNCLEAR_AFTER;
            $hasUnclear = $hasUnclear || $unclear;

            $status = $unclear ? ($lang['statusUnclear'] ?? 'unclear') : (string) $message['status'];
            $sentAt = (int) $message['sent_at'] > 0 ? Date::parse(Date::getNumericDateFormat().' H:i', (int) $message['sent_at']) : '-';

            $rows .= '<tr>'
                .'<td>'.StringUtil::specialchars((string) $message['recipients']).'</td>'
                .'<td>'.StringUtil::specialchars(($lang['status'][$status] ?? null) ?: $status).'</td>'
                .'<td>'.StringUtil::specialchars((string) $message['error']).'</td>'
                .'<td>'.StringUtil::specialchars($sentAt).'</td>'
                .'</tr>';
        }

        $deliverForm = (int) $head['delivered'] > 0
            ? '<p class="tl_info">'.\sprintf($lang['alreadyDelivered'] ?? 'Already delivered on %s.', StringUtil::specialchars(Date::parse(Date::getNumericDateFormat().' H:i', (int) $head['delivered']))).'</p>'
            : $this->deliverForm((int) $head['id'], $hasUnclear);

        return '<div id="tl_buttons"><a href="'.StringUtil::specialchars($this->listUrl()).'" class="header_back">'.($lang['backToList'] ?? 'Back').'</a></div>'
            .Message::generate()
            .'<div class="tl_listing_container">'
            .'<table class="tl_listing">'
            .'<tr><th>'.($lang['created'][0] ?? 'Date').'</th><td>'.StringUtil::specialchars(Date::parse(Date::getNumericDateFormat().' H:i', (int) $head['created'])).'</td></tr>'
            .'<tr><th>'.($lang['source'][0] ?? 'Source').'</th><td>'.StringUtil::specialchars((string) $head['source']).'</td></tr>'
            .'<tr><th>'.($lang['score'][0] ?? 'Score').'</th><td>'.StringUtil::specialchars((string) $head['score']).'</td></tr>'
            .'<tr><th>'.($lang['reasons'][0] ?? 'Reasons').'</th><td>'.StringUtil::specialchars((string) $head['reasons']).'</td></tr>'
            .'<tr><th>'.($lang['label'][0] ?? 'Label').'</th><td>'.StringUtil::specialchars((string) $head['label']).'</td></tr>'
            .'<tr><th>'.($lang['ruleVersion'][0] ?? 'Rule version').'</th><td>'.StringUtil::specialchars((string) $head['rule_version']).'</td></tr>'
            .'<tr><th>'.($lang['preview'][0] ?? 'Preview').'</th><td style="white-space:pre-wrap">'.StringUtil::specialchars((string) $head['preview']).'</td></tr>'
            .'</table>'
            .'<h2>'.($lang['messages'] ?? 'Mails').'</h2>'
            .'<p class="tl_info">'.($lang['sentMeansAccepted'] ?? '"sent" means the mail server accepted it.').'</p>'
            .('comment' === $head['source'] ? '<p class="tl_info">'.($lang['commentHint'] ?? 'Delivering only sends the mails; publish the comment in the comments module.').'</p>' : '')
            .'<table class="tl_listing">'
            .'<tr><th>'.($lang['recipients'] ?? 'Recipients').'</th><th>'.($lang['status'][0] ?? 'Status').'</th><th>'.($lang['error'] ?? 'Error').'</th><th>'.($lang['sentAt'] ?? 'Sent at').'</th></tr>'
            .$rows
            .'</table>'
            .$deliverForm
            .'</div>';
    }

    private function deliverForm(int $id, bool $hasUnclear): string
    {
        $lang = &$GLOBALS['TL_LANG']['tl_turnstile_spam'];
        $token = StringUtil::specialchars($this->csrfTokenManager->getDefaultTokenValue());

        $checkbox = $hasUnclear
            ? '<p><label><input type="checkbox" name="retryUnclear" value="1"> '.($lang['retryUnclear'] ?? 'Send again anyway - possible duplicate delivery.').'</label></p>'
            : '';

        return '<form action="'.StringUtil::specialchars($this->viewUrl($id)).'" method="post">'
            .'<input type="hidden" name="FORM_SUBMIT" value="turnstile_spam_deliver">'
            .'<input type="hidden" name="REQUEST_TOKEN" value="'.$token.'">'
            .$checkbox
            .'<button type="submit" class="tl_submit">'.($lang['deliver'] ?? 'Deliver anyway').'</button>'
            .'</form>';
    }

    private function currentUserId(): int
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return \is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

    private function listUrl(): string
    {
        return $this->router->generate('contao_backend', ['do' => self::MODULE]);
    }

    private function viewUrl(int $id): string
    {
        return $this->router->generate('contao_backend', ['do' => self::MODULE, 'key' => 'view', 'id' => $id]);
    }
}
