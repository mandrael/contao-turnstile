<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Mailer;

use Contao\CoreBundle\Monolog\ContaoContext;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Dekoriert mailer.mailer innerhalb von Contaos Mailer (höhere decoration_priority), sieht also jede Mail mit
 * gesetztem Absender und „X-Transport", und setzt die Einstufung einer tokenlosen Einsendung beim Versand um:
 * Mails einer als „Spam sicher" eingestuften Einsendung gehen nicht hinaus, sondern in die Spam-Ablage. Greift vor
 * dem Einreihen in die Messenger-Warteschlange, deshalb synchron wie asynchron.
 *
 * Zustand im Attribut ATTRIBUTE des Haupt-Requests:
 * - mode 'suppress' (Formulargenerator, Kommentare): jede Mail unverändert ablegen, keine versenden
 * - mode 'mark' (Registrierung): an die registrierte Adresse geht eine Kopie nur an sie hinaus – sonst wäre ein
 *   Mensch im Fehlalarm ohne Aktivierungslink ausgesperrt –, alle übrigen Empfänger werden abgelegt
 * - mode 'clean': nichts ändern außer gedrosselten Adressen
 *
 * Scheitert das Ablegen, nimmt die Mail den Rückfallweg: eingetragene Adressen gestrichen, Rest mit „[Spam]" an
 * die Betreiber; bliebe kein Empfänger, an die Admin-Adresse, ohne diese unverändert mit „[Spam]". Eine verlorene Einsendung wiegt schwerer als eine
 * Spam-Mail im Fehlerfall. Betreiberadressen werden dabei nie gestrichen: die Formularempfänger (protected),
 * TL_ADMIN_EMAIL und jede Adresse auf der Domain der aufgerufenen Website.
 */
class SpamAwareMailer implements MailerInterface
{
    public const ATTRIBUTE = '_mandrael_turnstile';

    private const PREFIX = '[Spam] ';

    public function __construct(
        private readonly MailerInterface $inner,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly SpamArchive $archive,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function state(RequestStack $requestStack): ?array
    {
        $state = $requestStack->getMainRequest()?->attributes->get(self::ATTRIBUTE);

        return \is_array($state) ? $state : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function setState(RequestStack $requestStack, array $state): void
    {
        $requestStack->getMainRequest()?->attributes->set(self::ATTRIBUTE, $state);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $state = self::state($this->requestStack);

        match ($state['mode'] ?? null) {
            'suppress' => $this->suppress($state, $message, $envelope),
            'mark' => $message instanceof Email ? $this->mark($state, $message, $envelope) : $this->inner->send($message, $envelope),
            'clean' => $message instanceof Email ? $this->sendWithout($state, $message, $envelope, self::normalize($state['throttled'] ?? []), false) : $this->inner->send($message, $envelope),
            default => $this->inner->send($message, $envelope),
        };
    }

    /**
     * @param array<string, mixed> $state
     */
    private function suppress(array $state, RawMessage $message, ?Envelope $envelope): void
    {
        if ($this->store($state, $message, $envelope)) {
            return;
        }

        if (!$message instanceof Email) {
            $this->inner->send($message, $envelope);

            return;
        }

        $this->sendWithout($state, $message, $envelope, self::normalize($state['addresses'] ?? []), true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function mark(array $state, Email $message, ?Envelope $envelope): void
    {
        $registered = self::normalize($state['addresses'] ?? []);
        $isRegistered = static fn (Address $a): bool => \in_array(strtolower($a->getAddress()), $registered, true);
        $isOther = static fn (Address $a): bool => !$isRegistered($a);

        if ([] !== array_filter(self::recipients($message, $envelope), $isRegistered)) {
            $this->inner->send(...self::restrict($message, $envelope, $isRegistered));
        }

        if ([] === array_filter(self::recipients($message, $envelope), $isOther)) {
            return;
        }

        [$rest, $restEnvelope] = self::restrict($message, $envelope, $isOther);

        if ($this->store($state, $rest, $restEnvelope)) {
            return;
        }

        $this->prefix($rest);
        $this->inner->send($rest, $restEnvelope);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function store(array $state, RawMessage $message, ?Envelope $envelope): bool
    {
        $id = $this->archive->store(
            \is_int($state['archive'] ?? null) ? $state['archive'] : null,
            ['source' => (string) ($state['source'] ?? ''), 'score' => (int) ($state['score'] ?? 0), 'reasons' => (array) ($state['reasons'] ?? [])],
            $message,
            $envelope,
        );

        if (null === $id) {
            return false;
        }

        $state['archive'] = $id;
        self::setState($this->requestStack, $state);
        $this->log('Mail nicht versendet, in der Spam-Ablage (Eintrag '.$id.') (spam-archived).');

        return true;
    }

    /**
     * Streicht $blocked aus To/Cc/Bcc und Envelope, Betreiberadressen ausgenommen, und versendet den Rest.
     * Mit $fallback trägt der Rest „[Spam]", und ohne verbleibenden Empfänger geht die Mail an die Admin-Adresse.
     *
     * @param array<string, mixed> $state
     * @param list<string>         $blocked
     */
    private function sendWithout(array $state, Email $message, ?Envelope $envelope, array $blocked, bool $fallback): void
    {
        $protected = self::normalize([...(array) ($state['protected'] ?? []), (string) ($GLOBALS['TL_ADMIN_EMAIL'] ?? '')]);
        $ownDomains = self::normalize([(string) preg_replace('/^www\./i', '', (string) $this->requestStack->getMainRequest()?->getHost())]);
        $blocked = array_values(array_filter(
            array_diff($blocked, $protected),
            static fn (string $a): bool => !\in_array((string) substr((string) strrchr($a, '@'), 1), $ownDomains, true)
        ));

        if ([] !== $blocked) {
            $keep = static fn (Address $a): bool => !\in_array(strtolower($a->getAddress()), $blocked, true);

            if ([] === array_filter(self::recipients($message, $envelope), $keep)) {
                $admin = trim((string) ($GLOBALS['TL_ADMIN_EMAIL'] ?? ''));

                if (!$fallback) {
                    $this->log('Mail an die im Formular eingetragene Adresse nicht versendet (confirmation-suppressed).');

                    return;
                }

                if ('' !== $admin) {
                    $message = clone $message;
                    $message->getHeaders()->remove('Cc');
                    $message->getHeaders()->remove('Bcc');
                    $message->to($admin);
                    $envelope = null;
                }

                // Ohne Admin-Adresse bleibt nur der ursprüngliche Empfänger: lieber eine Spam-Mail als eine verlorene.
            } else {
                [$message, $envelope] = self::restrict($message, $envelope, $keep);
                $this->log('Mail an die im Formular eingetragene Adresse nicht versendet (confirmation-suppressed).');
            }
        }

        if ($fallback) {
            $this->prefix($message);
        }

        $this->inner->send($message, $envelope);
    }

    /**
     * Kopie mit nur den Empfängern, für die $keep gilt, in To/Cc/Bcc und Envelope.
     *
     * @param \Closure(Address): bool $keep
     *
     * @return array{0: Email, 1: Envelope|null}
     */
    private static function restrict(Email $message, ?Envelope $envelope, \Closure $keep): array
    {
        $copy = clone $message;

        foreach (['To' => $message->getTo(), 'Cc' => $message->getCc(), 'Bcc' => $message->getBcc()] as $header => $list) {
            $copy->getHeaders()->remove($header);
            $filtered = array_values(array_filter($list, $keep));

            if ([] !== $filtered) {
                $copy->{strtolower($header)}(...$filtered);
            }
        }

        if (null !== $envelope) {
            $filtered = array_values(array_filter($envelope->getRecipients(), $keep));
            // Envelope::setRecipients([]) wirft; dann aus den Köpfen der Kopie ableiten lassen.
            $envelope = [] === $filtered ? null : new Envelope($envelope->getSender(), $filtered);
        }

        return [$copy, $envelope];
    }

    /**
     * @return list<Address>
     */
    private static function recipients(Email $message, ?Envelope $envelope): array
    {
        return array_values(array_merge($message->getTo(), $message->getCc(), $message->getBcc(), $envelope?->getRecipients() ?? []));
    }

    private function prefix(Email $message): void
    {
        if (!str_starts_with((string) $message->getSubject(), self::PREFIX)) {
            $message->subject(self::PREFIX.$message->getSubject());
        }
    }

    /**
     * @param array<mixed> $addresses
     *
     * @return list<string>
     */
    private static function normalize(array $addresses): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (mixed $a): string => strtolower(trim((string) $a)), $addresses))));
    }

    private function log(string $message): void
    {
        try {
            $this->logger->info('Cloudflare Turnstile: '.$message, ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]);
        } catch (\Throwable) {
        }
    }
}
