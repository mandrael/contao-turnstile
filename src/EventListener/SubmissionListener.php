<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\EventListener;

use Contao\Form;
use Contao\FormFieldModel;
use Contao\Model\Registry;
use Contao\StringUtil;
use Mandrael\ContaoTurnstileBundle\Mailer\SpamAwareMailer;
use Mandrael\ContaoTurnstileBundle\Service\SpamClassifier;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verbindet die Einstufung tokenloser Einsendungen mit den drei Oberflächen, die das Captcha nutzen. Das
 * Widget meldet nur „tokenlos, mechanische Stufe bestanden" (Modus 'pending'); eingestuft wird dort, wo die
 * Felder bekannt sind, und immer bevor die erste Mail an den Absender hinausgeht:
 * - Formulargenerator: prepareFormData, vor Formularmail und Notification Center
 * - Registrierung: schon im Widget, weil die Aktivierungsmail (Core) bzw. die Notification des Notification
 *   Centers vor jedem Registrierungs-Hook verschickt wird
 * - Kommentare: schon im Widget, weil die Abo-Bestätigung vor jedem Hook verschickt wird
 */
class SubmissionListener
{
    // Feldprüfungen, deren Werte kein Freitext sind; eine URL-Pflichtangabe darf das Link-Signal nicht auslösen.
    private const NON_TEXT_RGXP = ['digit', 'natural', 'prcnt', 'phone', 'date', 'time', 'datim', 'url', 'email', 'friendly', 'postal', 'alnum'];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SpamClassifier $classifier,
    ) {
    }

    /**
     * Vom Widget aufgerufen, wenn eine tokenlose Einsendung die mechanische Stufe bestanden hat.
     *
     * @param array<string, mixed> $post
     */
    public function onTokenlessPass(array $post): void
    {
        $ip = $this->requestStack->getMainRequest()?->getClientIp();
        $formSubmit = \is_string($post['FORM_SUBMIT'] ?? null) ? $post['FORM_SUBMIT'] : '';

        if (str_starts_with($formSubmit, 'com_') && \array_key_exists('comment', $post)) {
            $texts = self::strings([$post['name'] ?? null, $post['comment'] ?? null]);
            $this->decide('suppress', 'comment', $texts, self::strings([$post['email'] ?? null]), $ip, []);

            return;
        }

        if (str_starts_with($formSubmit, 'tl_registration')) {
            $texts = self::strings([$post['firstname'] ?? null, $post['lastname'] ?? null, $post['company'] ?? null, $post['street'] ?? null, $post['city'] ?? null]);
            // Keine Drossel: Die Aktivierungsmail ist Contaos eigene Adressbestätigung.
            $this->decide('mark', 'registration', $texts, self::strings([$post['email'] ?? null]), $ip, [], false);

            return;
        }

        SpamAwareMailer::setState($this->requestStack, ['mode' => 'pending', 'ip' => $ip]);
    }

    /**
     * Contao 4.13 übergibt vier Argumente, 5.x fünf.
     *
     * @param array<string, mixed>                  $submitted
     * @param array<string, mixed>                  $labels
     * @param array<int|string, FormFieldModel>     $fields
     * @param array<string, mixed>                  $files
     */
    public function onPrepareFormData(array $submitted, array $labels, array $fields, Form $form, array $files = []): void
    {
        $state = SpamAwareMailer::state($this->requestStack);

        if ('pending' !== ($state['mode'] ?? null)) {
            return;
        }

        $texts = [];
        $emails = [];

        foreach ($fields as $field) {
            $value = $submitted[$field->name] ?? null;

            if (!\is_string($value) || '' === trim($value)) {
                continue;
            }

            if ('email' === $field->rgxp) {
                $emails[] = trim($value);
            } elseif (\in_array($field->type, ['text', 'textarea'], true) && !\in_array($field->rgxp, self::NON_TEXT_RGXP, true)) {
                $texts[] = $value;
            }
        }

        // Wie der Core (Form::processFormData): Kommaliste, auch „Name <adresse>".
        $protected = array_map(
            static fn (string $r): string => (string) (StringUtil::splitFriendlyEmail($r)[1] ?? ''),
            self::strings(StringUtil::splitCsv((string) $form->recipient))
        );

        $this->decide('suppress', 'form', $texts, $emails, $state['ip'] ?? null, $protected);
    }

    /**
     * Läuft nach dem Speichern, aber vor Admin-Mail und Abonnenten-Benachrichtigung. notified = 1 lässt
     * notifyCommentsSubscribers() abbrechen. Geändert werden muss genau die Instanz aus der Model-Registry,
     * denn Contao übergibt sie danach an notifyCommentsSubscribers(); ein reines SQL-Update sähe sie nicht.
     * Über die Registry statt CommentsModel, weil das Comments-Bundle keine Abhängigkeit dieses Bundles ist.
     *
     * @param array<string, mixed> $set
     */
    public function onAddComment(int|string $id, array $set, mixed $comments = null): void
    {
        if ('suppress' !== (SpamAwareMailer::state($this->requestStack)['mode'] ?? null)) {
            return;
        }

        $comment = Registry::getInstance()->fetch('tl_comments', (int) $id);

        if (null === $comment) {
            return;
        }

        // __set statt Eigenschaftszugriff: Contao\Model kennt die Spalten von tl_comments nicht statisch.
        // Ganzzahlen, weil Contao 5 die Spalten als INT führt ('' wirft dort im Strict-Mode); 4.13 (CHAR(1))
        // liest '0' ebenso als falsch.
        $comment->__set('published', 0);
        $comment->__set('notified', 1);
        $comment->save();
    }

    /**
     * @param list<string> $texts
     * @param list<string> $emails
     * @param list<string> $protected
     */
    private function decide(string $spamMode, string $source, array $texts, array $emails, ?string $ip, array $protected, bool $throttle = true): void
    {
        $result = $this->classifier->classify($texts, $emails, $ip);

        SpamAwareMailer::setState($this->requestStack, [
            'mode' => $result['spam'] ? $spamMode : 'clean',
            'source' => $source,
            'score' => $result['score'],
            'reasons' => $result['reasons'],
            'addresses' => $emails,
            'protected' => $protected,
            'throttled' => $result['spam'] || !$throttle ? [] : $this->classifier->throttledRecipients($emails),
        ]);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => \is_string($v) ? trim($v) : '',
            $values
        ), static fn (string $v): bool => '' !== $v));
    }
}
