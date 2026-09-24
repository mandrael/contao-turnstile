<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Mailer;

use Mandrael\ContaoTurnstileBundle\Mailer\SpamAwareMailer;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class SpamAwareMailerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_ADMIN_EMAIL']);

        parent::tearDown();
    }

    public function testWithoutAttributeMessageIsUnchangedAndNotArchived(): void
    {
        $message = (new Email())->from('absender@example.com')->to('institut@nk.at')->subject('Kontaktformular');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with($message, null);
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::never())->method('store');

        (new SpamAwareMailer($inner, new RequestStack(), new NullLogger(), $archive))->send($message);

        self::assertSame('Kontaktformular', $message->getSubject());
    }

    public function testSuppressArchivesTheUnchangedOriginalAndSendsNothing(): void
    {
        $message = (new Email())->from('absender@example.com')->to('institut@nk.at')->cc('bot@fake.example')->subject('Kontaktformular');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::never())->method('send');
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::once())->method('store')
            ->with(null, ['source' => 'form', 'score' => 9, 'reasons' => ['tor-exit', 'dotted-address']], $message, null)
            ->willReturn(17);

        $stack = $this->stack(['mode' => 'suppress', 'source' => 'form', 'score' => 9, 'reasons' => ['tor-exit', 'dotted-address'], 'addresses' => ['bot@fake.example']]);
        (new SpamAwareMailer($inner, $stack, new NullLogger(), $archive))->send($message);

        // Unverändert abgelegt: Cc noch da, kein Präfix.
        self::assertSame(['bot@fake.example'], self::addresses($message->getCc()));
        self::assertSame('Kontaktformular', $message->getSubject());
        self::assertSame(17, SpamAwareMailer::state($stack)['archive']);
    }

    public function testSuppressAppendsFurtherMailsToTheSameEntry(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::never())->method('send');
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::exactly(2))->method('store')
            ->willReturnCallback(static fn (?int $id): int => $id ?? 5);

        $stack = $this->stack(['mode' => 'suppress', 'addresses' => ['bot@fake.example']]);
        $mailer = new SpamAwareMailer($inner, $stack, new NullLogger(), $archive);
        $mailer->send((new Email())->from('a@nk.at')->to('institut@nk.at')->subject('Formular'));
        $mailer->send((new Email())->from('a@nk.at')->to('bot@fake.example')->subject('Bestätigung'));

        self::assertSame(5, SpamAwareMailer::state($stack)['archive']);
    }

    public function testSuppressRawMessageIsArchivedToo(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::never())->method('send');

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'suppress']), new NullLogger(), $this->archive(3));
        $mailer->send(new RawMessage('roh'), new Envelope(new Address('a@nk.at'), [new Address('b@nk.at')]));
    }

    public function testFallbackRemovesEnteredAddressAndPrefixesOnce(): void
    {
        $message = (new Email())->from('absender@example.com')->to('institut@nk.at')->cc('bot@fake.example')->bcc('BOT@fake.example')->subject('[Spam] Kontaktformular');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::callback(static function (Email $sent): bool {
            return [] === $sent->getCc() && [] === $sent->getBcc() && ['institut@nk.at'] === self::addresses($sent->getTo())
                && '[Spam] Kontaktformular' === $sent->getSubject();
        }));

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'suppress', 'addresses' => ['bot@fake.example']]), new NullLogger(), $this->archive(null));
        $mailer->send($message);
    }

    public function testFallbackPureSenderMailGoesToAdminWithPrefix(): void
    {
        $GLOBALS['TL_ADMIN_EMAIL'] = 'admin@nk.at';
        $message = (new Email())->from('institut@nk.at')->to('bot@fake.example')->subject('Ihre Anmeldung');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::callback(static function (Email $sent, ?Envelope $envelope = null): bool {
            return ['admin@nk.at'] === self::addresses($sent->getTo()) && '[Spam] Ihre Anmeldung' === $sent->getSubject();
        }), null);

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'suppress', 'addresses' => ['bot@fake.example']]), new NullLogger(), $this->archive(null));
        $mailer->send($message, new Envelope(new Address('institut@nk.at'), [new Address('bot@fake.example')]));
    }

    public function testFallbackPureSenderMailWithoutAdminIsDropped(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::never())->method('send');

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'suppress', 'addresses' => ['bot@fake.example']]), new NullLogger(), $this->archive(null));
        $mailer->send((new Email())->from('institut@nk.at')->to('bot@fake.example')->subject('Ihre Anmeldung'));
    }

    public function testFallbackKeepsOperatorAddressesEvenIfEntered(): void
    {
        $GLOBALS['TL_ADMIN_EMAIL'] = 'admin@nk.at';
        $message = (new Email())->from('a@nk.at')->to('kurs@partner.example')->cc('admin@nk.at')->bcc('office@request-host.example')->subject('Anmeldung');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::callback(static function (Email $sent): bool {
            return ['kurs@partner.example'] === self::addresses($sent->getTo()) && ['admin@nk.at'] === self::addresses($sent->getCc())
                && ['office@request-host.example'] === self::addresses($sent->getBcc()) && '[Spam] Anmeldung' === $sent->getSubject();
        }));

        $stack = $this->stack(
            ['mode' => 'suppress', 'addresses' => ['kurs@partner.example', 'admin@nk.at', 'office@request-host.example'], 'protected' => ['kurs@partner.example']],
            'https://www.request-host.example/kontakt',
        );
        (new SpamAwareMailer($inner, $stack, new NullLogger(), $this->archive(null)))->send($message);
    }

    public function testFallbackFiltersEnvelope(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::anything(), self::callback(
            static fn (?Envelope $e): bool => null !== $e && ['institut@nk.at'] === self::addresses($e->getRecipients())
        ));

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'suppress', 'addresses' => ['bot@fake.example']]), new NullLogger(), $this->archive(null));
        $mailer->send(
            (new Email())->from('a@nk.at')->to('institut@nk.at')->cc('bot@fake.example')->subject('X'),
            new Envelope(new Address('a@nk.at'), [new Address('institut@nk.at'), new Address('bot@fake.example')]),
        );
    }

    public function testFallbackRawMessageIsPassedThrough(): void
    {
        $message = new RawMessage('roh');
        $envelope = new Envelope(new Address('a@nk.at'), [new Address('b@nk.at')]);

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with($message, $envelope);

        (new SpamAwareMailer($inner, $this->stack(['mode' => 'suppress']), new NullLogger(), $this->archive(null)))->send($message, $envelope);
    }

    public function testMarkSendsActivationToRegisteredAddressWithoutArchiving(): void
    {
        $message = (new Email())->from('a@nk.at')->to('Neu@Mitglied.example')->subject('Ihre Registrierung');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::callback(
            static fn (Email $sent): bool => ['Neu@Mitglied.example'] === self::addresses($sent->getTo()) && 'Ihre Registrierung' === $sent->getSubject()
        ));
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::never())->method('store');

        (new SpamAwareMailer($inner, $this->stack(['mode' => 'mark', 'addresses' => ['neu@mitglied.example']]), new NullLogger(), $archive))->send($message);
    }

    public function testMarkArchivesAdminNotification(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::never())->method('send');

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'mark', 'addresses' => ['neu@mitglied.example']]), new NullLogger(), $this->archive(8));
        $mailer->send((new Email())->from('a@nk.at')->to('admin@nk.at')->subject('Neue Registrierung'));
    }

    public function testMarkSplitsMixedMailIntoActivationCopyAndArchivedRest(): void
    {
        // Notification Center: eine Aktivierungsmail mit Betreiber in Cc. Der Mensch muss den Link bekommen,
        // die Betreiber keine Spam-Mail.
        $message = (new Email())->from('a@nk.at')->to('neu@mitglied.example')->cc('kurs@nk.at')->subject('Bitte bestätigen');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::callback(
            static fn (Email $sent): bool => ['neu@mitglied.example'] === self::addresses($sent->getTo()) && [] === $sent->getCc()
        ));
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::once())->method('store')->with(null, self::anything(), self::callback(
            static fn (Email $stored): bool => [] === $stored->getTo() && ['kurs@nk.at'] === self::addresses($stored->getCc())
        ))->willReturn(4);

        (new SpamAwareMailer($inner, $this->stack(['mode' => 'mark', 'addresses' => ['neu@mitglied.example']]), new NullLogger(), $archive))->send($message);

        self::assertSame(['kurs@nk.at'], self::addresses($message->getCc()), 'Original bleibt unverändert');
    }

    public function testMarkFallbackSendsRestWithPrefixButNeverDropsRegisteredAddress(): void
    {
        $sent = [];
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::exactly(2))->method('send')->willReturnCallback(static function (Email $m) use (&$sent): void {
            $sent[] = [self::addresses([...$m->getTo(), ...$m->getCc()]), $m->getSubject()];
        });

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'mark', 'addresses' => ['neu@mitglied.example']]), new NullLogger(), $this->archive(null));
        $mailer->send((new Email())->from('a@nk.at')->to('neu@mitglied.example')->cc('kurs@nk.at')->subject('Bitte bestätigen'));

        self::assertSame([[['neu@mitglied.example'], 'Bitte bestätigen'], [['kurs@nk.at'], '[Spam] Bitte bestätigen']], $sent);
    }

    public function testCleanRemovesOnlyThrottledAddressCaseInsensitiveWithoutPrefix(): void
    {
        $message = (new Email())->from('a@nk.at')->to('institut@nk.at')->cc('Viel@Genutzt.example')->subject('Kontaktformular');

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send')->with(self::callback(
            static fn (Email $sent): bool => [] === $sent->getCc() && 'Kontaktformular' === $sent->getSubject()
        ));
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::never())->method('store');

        (new SpamAwareMailer($inner, $this->stack(['mode' => 'clean', 'throttled' => ['viel@genutzt.example']]), new NullLogger(), $archive))->send($message);
    }

    public function testCleanDropsMailWhoseOnlyRecipientIsThrottled(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::never())->method('send');

        $mailer = new SpamAwareMailer($inner, $this->stack(['mode' => 'clean', 'throttled' => ['viel@genutzt.example']]), new NullLogger(), $this->archive(null));
        $mailer->send((new Email())->from('a@nk.at')->to('viel@genutzt.example')->subject('Bestätigung'));
    }

    private function archive(?int $result): SpamArchive
    {
        $archive = $this->createStub(SpamArchive::class);
        $archive->method('store')->willReturn($result);

        return $archive;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function stack(array $state, string $url = 'http://request-host-unused.example/'): RequestStack
    {
        $request = Request::create($url);
        $request->attributes->set(SpamAwareMailer::ATTRIBUTE, $state);

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    /**
     * @param list<Address> $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_map(static fn (Address $a): string => $a->getAddress(), $addresses);
    }
}
