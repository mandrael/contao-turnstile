<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\DataContainer;
use Contao\Date;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Posteingang der Ablage (BE_MOD-Key „feed", Standardansicht des Moduls): alle Einträge offen untereinander mit
 * Spam bestätigen / Doch zustellen / Löschen direkt am Eintrag. Ohne JavaScript normale Formulare mit Redirect,
 * mit JavaScript (public/spam-archive.js) per fetch und JSON, ohne Neuladen. Daneben die Einzelansicht
 * (Key „view") mit Mailstatus und „Trotzdem erneut senden" für unklare Zustellungen. Liest die Kopf- und Mail-Daten selbst per DBAL, weil SpamArchive keine
 * Einzelabfrage anbietet und dafür nicht verändert werden soll.
 */
class SpamArchiveController
{
    private const MODULE = 'turnstile_spam';

    private const PER_PAGE = 50;

    /**
     * Filter des Posteingangs → WHERE-Bedingung (nur feste Werte, nie Eingaben).
     */
    private const FILTERS = [
        'unreviewed' => "label = 'unreviewed'",
        'auto' => "label = 'spam' AND label_by = 0",
        'spam' => "label = 'spam'",
        'ham' => "label = 'ham'",
        'all' => '1 = 1',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SpamArchive $archive,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $csrfTokenName = 'contao_csrf_token',
    ) {
    }

    public function feed(DataContainer $dc): string|RedirectResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $filter = (string) $request?->query->get('filter', 'unreviewed');
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'unreviewed';
        $page = max(1, (int) $request?->query->get('page', 1));

        if (null !== $request && $request->isMethod('POST') && 'turnstile_spam_feed' === $request->request->get('FORM_SUBMIT')) {
            if (!$this->hasValidToken($request)) {
                if (self::isFetch($request)) {
                    throw new ResponseException(new JsonResponse(['ok' => false, 'text' => '', 'counts' => []], 400));
                }

                return new RedirectResponse($this->feedUrl($filter, $page));
            }

            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->request->get('ids')))));
            $text = $this->act((string) $request->request->get('tsa_action'), $ids);

            if (self::isFetch($request)) {
                throw new ResponseException(new JsonResponse(['ok' => null !== $text, 'text' => $text ?? '', 'counts' => $this->counts()], null !== $text ? 200 : 400));
            }

            if (null !== $text) {
                Message::addConfirmation($text);
            }

            return new RedirectResponse($this->feedUrl($filter, $page));
        }

        $where = self::FILTERS[$filter];
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.SpamArchive::TABLE.' WHERE '.$where);
        // Nach Aktionen kann die letzte Seite leer werden: auf die tatsächlich letzte Seite zurückfallen.
        $page = min($page, max(1, (int) ceil($total / self::PER_PAGE)));
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created, source, score, reasons, pattern, subject, recipients, preview, label, label_by, label_at, delivered FROM '.SpamArchive::TABLE
            .' WHERE '.$where.' ORDER BY created DESC, id DESC LIMIT '.self::PER_PAGE.' OFFSET '.(($page - 1) * self::PER_PAGE),
        );

        return $this->renderFeed($filter, $page, $rows, $total, $this->counts());
    }

    /**
     * Führt eine Aktion des Posteingangs aus; liefert den Rückmeldetext oder null bei unbekannter Aktion.
     *
     * @param list<int> $ids
     */
    private function act(string $action, array $ids): ?string
    {
        $lang = &$GLOBALS['TL_LANG']['tl_turnstile_spam'];

        if ([] === $ids) {
            return null;
        }

        switch ($action) {
            case 'spam':
                $count = $this->archive->markSpam($ids, $this->currentUserId());

                return \sprintf($lang['markedSpam'] ?? '%d marked as spam.', $count);

            case 'delete':
                $count = $this->archive->delete($ids);

                return \sprintf($lang['deleted'] ?? '%d deleted.', $count);

            case 'unblock':
                $pattern = (string) $this->db->fetchOne('SELECT pattern FROM '.SpamArchive::TABLE.' WHERE id = ?', [$ids[0]]);

                return $this->archive->unblock($pattern, $this->currentUserId()) ? ($lang['unblocked'] ?? 'Automatic confirmation allowed again.') : null;

            case 'deliver':
                // Einzeln: Zustellen versendet echte Mails und ist nie eine Sammelaktion.
                $result = $this->archive->deliver($ids[0], $this->currentUserId());

                return \sprintf($lang['delivered'] ?? '%d sent, %d failed, %d unclear.', $result['sent'], $result['failed'], $result['unclear']);
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>          $counts
     */
    private function renderFeed(string $filter, int $page, array $rows, int $total, array $counts): string
    {
        $lang = &$GLOBALS['TL_LANG']['tl_turnstile_spam'];
        $counts['all'] = ($counts['unreviewed'] ?? 0) + ($counts['spam'] ?? 0) + ($counts['ham'] ?? 0);

        $tabs = '';

        foreach (array_keys(self::FILTERS) as $key) {
            $tabs .= '<a href="'.StringUtil::specialchars($this->feedUrl($key)).'"'.($key === $filter ? ' class="active"' : '').'>'
                .($lang['filters'][$key] ?? $key).' <span data-count="'.$key.'">'.($counts[$key] ?? 0).'</span></a>';
        }

        $bulk = '';

        if ('unreviewed' === $filter && [] !== $rows) {
            $bulk = $this->actionForm(array_map(static fn (array $r): int => (int) $r['id'], $rows), ['spam' => \sprintf($lang['markAllSpam'] ?? 'Mark all %d as spam', \count($rows))], 'tsa-bulk');
        }

        $cards = '';
        $patterns = $this->archive->patterns();

        foreach ($rows as $row) {
            $cards .= $this->renderCard($row, $patterns);
        }

        if ('' === $cards) {
            $cards = '<p class="tl_empty">'.($lang['feedEmpty'] ?? 'No entries.').'</p>';
        }

        $pager = '';

        if ($total > self::PER_PAGE) {
            $pager = '<p class="tsa-pager">'
                .($page > 1 ? '<a href="'.StringUtil::specialchars($this->feedUrl($filter, $page - 1)).'">‹ '.($lang['prev'] ?? 'Previous').'</a> ' : '')
                .\sprintf($lang['pageOf'] ?? 'Page %d of %d', $page, (int) ceil($total / self::PER_PAGE))
                .($page * self::PER_PAGE < $total ? ' <a href="'.StringUtil::specialchars($this->feedUrl($filter, $page + 1)).'">'.($lang['next'] ?? 'Next').' ›</a>' : '')
                .'</p>';
        }

        return '<div id="tl_buttons"><a href="'.StringUtil::specialchars($this->router->generate('contao_backend', ['do' => self::MODULE, 'mode' => 'table'])).'" class="header_back">'.($lang['tableView'] ?? 'Table view').'</a></div>'
            .Message::generate()
            .'<div class="tsa-feed">'
            .'<nav class="tsa-tabs">'.$tabs.'</nav>'
            .$bulk
            .$cards
            .$pager
            .'</div>';
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * @param array<string, array{confirmed: int, rejected: int, reset_at: int, blocked: bool}> $patterns Lernstand je Muster
     */
    private function renderCard(array $row, array $patterns = []): string
    {
        $lang = &$GLOBALS['TL_LANG']['tl_turnstile_spam'];
        $id = (int) $row['id'];
        $delivered = (int) $row['delivered'];
        $label = (string) $row['label'];
        $date = static fn (int $t, string $f = ' H:i'): string => Date::parse(Date::getNumericDateFormat().$f, $t);

        $status = match (true) {
            $delivered > 0 => \sprintf($lang['statusDelivered'] ?? '%s', $date($delivered, '')),
            'spam' === $label && 0 === (int) ($row['label_by'] ?? 1) => \sprintf($lang['statusSpamAuto'] ?? '%s', $date((int) $row['label_at'], '')),
            'spam' === $label => \sprintf($lang['statusSpam'] ?? '%s', $date((int) $row['label_at'], '')),
            'ham' === $label => $lang['statusHam'] ?? 'ham',
            default => $lang['statusUnreviewed'] ?? 'unreviewed',
        };

        $reasons = '';

        foreach (array_filter(array_map('trim', explode(',', (string) $row['reasons']))) as $reason) {
            $reasons .= '<li>'.StringUtil::specialchars($lang['reasonLabels'][$reason] ?? $reason).'</li>';
        }

        // Lernstand des Musters, damit sichtbar ist, wann die Automatik greift. Leeres Muster: wird nie gelernt.
        $pattern = (string) ($row['pattern'] ?? '');
        $stats = $patterns[$pattern] ?? ['confirmed' => 0, 'rejected' => 0, 'reset_at' => 0, 'blocked' => false];
        $blocked = '' !== $pattern && $stats['blocked'];
        $learning = match (true) {
            $blocked => $lang['patternBlocked'] ?? 'pattern blocked',
            '' !== $pattern && 'unreviewed' === $label && 0 === $delivered => \sprintf($lang['patternProgress'] ?? '%d of %d', min($stats['confirmed'], SpamArchive::AUTO_CONFIRM_MIN), SpamArchive::AUTO_CONFIRM_MIN),
            default => '',
        };

        // Eigenes Formular ohne Skript: Nach dem Aufheben lädt die Seite neu und zeigt den neuen Lernstand.
        $unblock = $blocked ? $this->actionForm([$id], ['unblock' => $lang['unblock'] ?? 'Allow automatic confirmation again'], 'tsa-unblock') : '';

        $actions = [];

        if ('unreviewed' === $label) {
            $actions['spam'] = $lang['markSpam'] ?? 'Mark as spam';
        }

        if (0 === $delivered) {
            $actions['deliver'] = $lang['deliverNotSpam'] ?? 'Not spam - deliver';
        }

        $actions['delete'] = $lang['delete'][0] ?? 'Delete';

        // Betreff, Empfänger und Text stammen aus fremden Einsendungen: alles escapen.
        return '<article class="tsa-card tsa-'.StringUtil::specialchars($delivered > 0 ? 'delivered' : $label).'" id="tsa-'.$id.'">'
            .'<header><strong>'.$date((int) $row['created']).'</strong> · '
            .StringUtil::specialchars((string) ($lang['sources'][$row['source']] ?? $row['source'])).' · '
            .\sprintf($lang['points'] ?? '%d points', (int) $row['score'])
            .' <span class="tsa-badge">'.StringUtil::specialchars($status).'</span></header>'
            .('' !== $reasons ? '<ul class="tsa-reasons">'.$reasons.('' !== $learning ? '<li class="tsa-learning">'.StringUtil::specialchars($learning).'</li>' : '').'</ul>' : '')
            .'<dl><dt>'.($lang['recipients'] ?? 'To').'</dt><dd>'.StringUtil::specialchars((string) $row['recipients']).'</dd>'
            .'<dt>'.($lang['subject'][0] ?? 'Subject').'</dt><dd>'.StringUtil::specialchars((string) $row['subject']).'</dd></dl>'
            .'<pre class="tsa-text">'.StringUtil::specialchars((string) $row['preview']).'</pre>'
            .$unblock
            .$this->actionForm([$id], $actions, 'tsa-actions', '<a href="'.StringUtil::specialchars($this->viewUrl($id)).'">'.($lang['details'] ?? 'Details').'</a>')
            .'</article>';
    }

    /**
     * @param list<int>             $ids
     * @param array<string, string> $actions Aktion → Beschriftung
     */
    private function actionForm(array $ids, array $actions, string $class, string $extra = ''): string
    {
        $lang = &$GLOBALS['TL_LANG']['tl_turnstile_spam'];
        $request = $this->requestStack->getCurrentRequest();
        $buttons = '';

        foreach ($actions as $action => $text) {
            $confirm = match (true) {
                'deliver' === $action => $lang['confirmDeliver'] ?? 'Really send?',
                'delete' === $action => $lang['confirmDelete'] ?? 'Really delete?',
                'unblock' === $action => $lang['confirmUnblock'] ?? 'Allow again?',
                'spam' === $action && 'tsa-bulk' === $class => \sprintf($lang['confirmMarkAll'] ?? 'Mark all %d as spam?', \count($ids)),
                default => '',
            };

            // Kurztext für den eingeklappten Eintrag; beim Zustellen zeigt das Skript das Versandergebnis.
            $done = $lang['done'][$action] ?? '';

            $buttons .= '<button type="submit" name="tsa_action" value="'.$action.'" class="tl_submit tsa-'.$action.'"'
                .('' !== $confirm ? ' data-confirm="'.StringUtil::specialchars($confirm).'"' : '')
                .('' !== $done ? ' data-done="'.StringUtil::specialchars($done).'"' : '').'>'.StringUtil::specialchars($text).'</button> ';
        }

        return '<form class="'.$class.'" data-unclear="'.StringUtil::specialchars($lang['ajaxUnclear'] ?? 'Unclear result - please reload.').'" action="'.StringUtil::specialchars((string) $request?->getRequestUri()).'" method="post">'
            .'<input type="hidden" name="FORM_SUBMIT" value="turnstile_spam_feed">'
            .'<input type="hidden" name="REQUEST_TOKEN" value="'.StringUtil::specialchars($this->csrfTokenManager->getDefaultTokenValue()).'">'
            .'<input type="hidden" name="ids" value="'.implode(',', $ids).'">'
            .$buttons.$extra
            .'</form>';
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
            if (!$this->hasValidToken($request)) {
                return new RedirectResponse($this->viewUrl($id));
            }

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
            .'<tr><th>'.($lang['source'][0] ?? 'Source').'</th><td>'.StringUtil::specialchars((string) ($lang['sources'][$head['source']] ?? $head['source'])).'</td></tr>'
            .'<tr><th>'.($lang['score'][0] ?? 'Score').'</th><td>'.StringUtil::specialchars((string) $head['score']).'</td></tr>'
            .'<tr><th>'.($lang['reasons'][0] ?? 'Reasons').'</th><td>'.StringUtil::specialchars((string) $head['reasons']).'</td></tr>'
            .'<tr><th>'.($lang['label'][0] ?? 'Label').'</th><td>'.StringUtil::specialchars((string) ($lang['labels'][$head['label']] ?? $head['label'])).'</td></tr>'
            .'<tr><th>'.($lang['ruleVersion'][0] ?? 'Rule version').'</th><td>'.StringUtil::specialchars((string) $head['rule_version']).'</td></tr>'
            .'<tr><th style="vertical-align:top">'.($lang['preview'][0] ?? 'Preview').'</th><td style="white-space:pre-wrap">'.StringUtil::specialchars((string) $head['preview']).'</td></tr>'
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
        return $this->feedUrl('unreviewed');
    }

    private function feedUrl(string $filter, int $page = 1): string
    {
        return $this->router->generate('contao_backend', array_filter(['do' => self::MODULE, 'key' => 'feed', 'filter' => $filter, 'page' => $page > 1 ? $page : null]));
    }

    private function viewUrl(int $id): string
    {
        return $this->router->generate('contao_backend', ['do' => self::MODULE, 'key' => 'view', 'id' => $id]);
    }

    /**
     * Eigener Header statt X-Requested-With: Contao behandelt X-Requested-With als Backend-Ajax (4.13 leitet die
     * Anfrage dann an Ajax::executePostActions statt an den Modul-Key, der RequestTokenListener prüft kein Token).
     */
    private static function isFetch(Request $request): bool
    {
        return '1' === $request->headers->get('X-Tsa-Fetch');
    }

    /**
     * Zusätzlich zum RequestTokenListener, damit keine Aktion ohne gültiges Token läuft, auch wenn der Listener eine
     * Anfrage überspringt. Symfony randomisiert den Tokenwert je Ausgabe, deshalb isTokenValid statt Vergleich.
     */
    private function hasValidToken(Request $request): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $request->request->get('REQUEST_TOKEN')));
    }


    /**
     * Zähler der Reiter; „auto" ist eine Teilmenge von „spam".
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return $this->archive->countByLabel() + ['auto' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.SpamArchive::TABLE.' WHERE '.self::FILTERS['auto'])];
    }

}
