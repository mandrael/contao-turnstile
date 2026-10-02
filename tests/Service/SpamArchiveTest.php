<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mandrael\ContaoTurnstileBundle\Mailer\ArchivedMessage;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Transports;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\AbstractHeader;
use Symfony\Component\Mime\RawMessage;

class SpamArchiveTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE tl_turnstile_spam (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INT NOT NULL DEFAULT 0, created INT NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT \'\', score INT NOT NULL DEFAULT 0, reasons TEXT NOT NULL DEFAULT \'\', pattern TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\', recipients TEXT, preview TEXT, digested INT NOT NULL DEFAULT 0, delivered INT NOT NULL DEFAULT 0, label TEXT NOT NULL DEFAULT \'unreviewed\', label_by INT NOT NULL DEFAULT 0, label_at INT NOT NULL DEFAULT 0, rule_version TEXT NOT NULL DEFAULT \'\')');
        $this->db->executeStatement('CREATE TABLE tl_turnstile_spam_message (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INT NOT NULL DEFAULT 0, tstamp INT NOT NULL DEFAULT 0, mime BLOB, sender TEXT NOT NULL DEFAULT \'\', recipients TEXT, transport TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'stored\', claimed_at INT NOT NULL DEFAULT 0, sent_at INT NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT \'\')');
        $this->db->executeStatement('CREATE TABLE tl_turnstile_spam_pattern (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INT NOT NULL DEFAULT 0, pattern TEXT NOT NULL DEFAULT \'\' UNIQUE, confirmed INT NOT NULL DEFAULT 0, rejected INT NOT NULL DEFAULT 0, reset_at INT NOT NULL DEFAULT 0)');
    }

    public function testStoreKeepsFinishedMailWithMetaAndWithoutTransportHeader(): void
    {
        $message = self::email()->cc('bot@fake.example');
        $message->getHeaders()->addTextHeader('X-Transport', 'kurse');

        $id = $this->archive()->store(null, ['source' => 'form', 'score' => 9, 'reasons' => ['tor-exit', 'dotted-address']], $message, null);

        self::assertIsInt($id);
        $head = $this->db->fetchAssociative('SELECT * FROM tl_turnstile_spam WHERE id = ?', [$id]);
        self::assertSame('form', $head['source']);
        self::assertSame(9, (int) $head['score']);
        self::assertSame('tor-exit, dotted-address', $head['reasons']);
        self::assertSame('Anmeldung Grundkurs', $head['subject']);
        self::assertSame('unreviewed', $head['label']);
        self::assertSame(SpamArchive::RULE_VERSION, $head['rule_version']);
        self::assertStringContainsString('Ich möchte mich anmelden', $head['preview']);

        $mail = $this->db->fetchAssociative('SELECT * FROM tl_turnstile_spam_message WHERE pid = ?', [$id]);
        self::assertSame('kurse', $mail['transport']);
        self::assertSame('institut@nk.at', $mail['sender']);
        self::assertSame('kurs@nk.at, bot@fake.example', $mail['recipients']);
        self::assertStringContainsString('Subject: Anmeldung Grundkurs', $mail['mime']);
        self::assertStringNotContainsString('X-Transport', $mail['mime']);
        self::assertSame('stored', $mail['status']);
        self::assertTrue($message->getHeaders()->has('X-Transport'), 'Original bleibt unverändert');
    }

    public function testStoreAppendsSecondMailToExistingEntry(): void
    {
        $archive = $this->archive();
        $id = $archive->store(null, [], self::email(), null);

        self::assertSame($id, $archive->store($id, [], self::email()->to('bot@fake.example'), null));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam_message WHERE pid = ?', [$id]));
    }

    public function testStoreRawMessageNeedsEnvelope(): void
    {
        $archive = $this->archive();

        self::assertNull($archive->store(null, [], new RawMessage('roh'), null));
        self::assertIsInt($archive->store(null, [], new RawMessage('roh'), new Envelope(new Address('a@nk.at'), [new Address('b@nk.at')])));
    }

    public function testStoreRejectsOversizedMail(): void
    {
        $message = self::email()->attach(str_repeat('x', SpamArchive::MAX_BYTES), 'gross.bin');

        self::assertNull($this->archive()->store(null, [], $message, null));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam'));
    }

    public function testStoreReturnsNullOnDatabaseError(): void
    {
        $this->db->executeStatement('DROP TABLE tl_turnstile_spam_message');

        self::assertNull($this->archive()->store(null, [], self::email(), null));
    }

    public function testStoreEmbedsNotificationCenterAttachments(): void
    {
        $message = self::email();
        $message->getHeaders()->add(new FakeNcAttachmentsHeader([new FakeNcItem('v1', 'anmeldung.pdf')]));

        $storage = new FakeBulkyStorage(['v1' => new FakeNcFile('PDF-INHALT', 'x.pdf', 'application/pdf')]);
        $id = $this->archive(storage: $storage)->store(null, [], $message, null);

        $mime = (string) $this->db->fetchOne('SELECT mime FROM tl_turnstile_spam_message WHERE pid = ?', [$id]);
        self::assertStringContainsString('anmeldung.pdf', $mime);
        self::assertStringContainsString(base64_encode('PDF-INHALT'), $mime);
        self::assertStringNotContainsString('Notification-Center-Bulky-Item-Storage-Attachments', $mime);
    }

    public function testStoreRejectsOversizedNotificationCenterAttachmentsBeforeLoading(): void
    {
        $message = self::email();
        $message->getHeaders()->add(new FakeNcAttachmentsHeader([new FakeNcItem('v1', 'gross.pdf')]));
        $storage = new FakeBulkyStorage(['v1' => new FakeNcFile('klein', 'x.pdf', 'application/pdf', SpamArchive::MAX_BYTES + 1)]);

        self::assertNull($this->archive(storage: $storage)->store(null, [], $message, null));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam'));
    }

    public function testStoreLeavesNoHeadRowWhenMessageInsertFails(): void
    {
        $this->db->executeStatement('DROP TABLE tl_turnstile_spam_message');

        self::assertNull($this->archive()->store(null, [], self::email(), null));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam'));
    }

    public function testStoreFailsWhenNotificationCenterAttachmentIsMissing(): void
    {
        $message = self::email();
        $message->getHeaders()->add(new FakeNcAttachmentsHeader([new FakeNcItem('weg', null)]));

        self::assertNull($this->archive(storage: new FakeBulkyStorage([]))->store(null, [], $message, null));
        self::assertNull($this->archive()->store(null, [], $message, null), 'ohne Ablage des Notification Centers');
    }

    public function testDeliverSendsEachMailOnceMarksHamAndDelivered(): void
    {
        $archive = $this->archive($transport = new RecordingTransport());
        $id = $archive->store(null, [], self::email(), null);
        $archive->store($id, [], self::email()->to('bot@fake.example'), null);

        self::assertSame(['sent' => 2, 'failed' => 0, 'unclear' => 0], $archive->deliver($id, 7));
        self::assertSame(['sent' => 0, 'failed' => 0, 'unclear' => 0], $archive->deliver($id, 7));
        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(ArchivedMessage::class, $transport->sent[0][0]);
        self::assertSame(['kurs@nk.at'], array_map(static fn (Address $a): string => $a->getAddress(), $transport->sent[0][1]->getRecipients()));

        $head = $this->db->fetchAssociative('SELECT label, label_by, delivered FROM tl_turnstile_spam WHERE id = ?', [$id]);
        self::assertSame('ham', $head['label']);
        self::assertSame(7, (int) $head['label_by']);
        self::assertGreaterThan(0, (int) $head['delivered']);
    }

    public function testDeliverFailureIsRecordedAndRetried(): void
    {
        $transport = new RecordingTransport(fail: true);
        $archive = $this->archive($transport);
        $id = $archive->store(null, [], self::email(), null);

        self::assertSame(['sent' => 0, 'failed' => 1, 'unclear' => 0], $archive->deliver($id, 1));
        self::assertSame('failed', $this->db->fetchOne('SELECT status FROM tl_turnstile_spam_message'));
        self::assertStringContainsString('Mailserver weg', (string) $this->db->fetchOne('SELECT error FROM tl_turnstile_spam_message'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT delivered FROM tl_turnstile_spam'));
        self::assertSame('ham', $this->db->fetchOne('SELECT label FROM tl_turnstile_spam'), 'Etikett unabhängig vom Versanderfolg');

        $transport->fail = false;
        self::assertSame(['sent' => 1, 'failed' => 0, 'unclear' => 0], $archive->deliver($id, 1));
    }

    public function testDeliverLeavesUnclearSendingUntilExplicitRetry(): void
    {
        $archive = $this->archive($transport = new RecordingTransport());
        $id = $archive->store(null, [], self::email(), null);
        $this->db->executeStatement('UPDATE tl_turnstile_spam_message SET status = ?, claimed_at = ?', ['sending', time() - SpamArchive::UNCLEAR_AFTER - 5]);

        self::assertSame(['sent' => 0, 'failed' => 0, 'unclear' => 1], $archive->deliver($id, 1));
        self::assertSame([], $transport->sent);
        self::assertSame(['sent' => 1, 'failed' => 0, 'unclear' => 0], $archive->deliver($id, 1, true));
    }

    public function testDeliverSkipsRecentlyClaimedMail(): void
    {
        $archive = $this->archive($transport = new RecordingTransport());
        $id = $archive->store(null, [], self::email(), null);
        // Vor einer Minute beansprucht: läuft vermutlich noch, auch mit ausdrücklicher Wiederholung nicht anfassen.
        $this->db->executeStatement('UPDATE tl_turnstile_spam_message SET status = ?, claimed_at = ?', ['sending', time() - 60]);

        self::assertSame(['sent' => 0, 'failed' => 0, 'unclear' => 0], $archive->deliver($id, 1, true));
        self::assertSame([], $transport->sent);
    }

    public function testArchivedMessageChoosesStoredTransportThroughTransports(): void
    {
        $default = new RecordingTransport();
        $kurse = new RecordingTransport();
        $transports = new Transports(['default' => $default, 'kurse' => $kurse]);
        $envelope = new Envelope(new Address('a@nk.at'), [new Address('b@nk.at')]);

        $transports->send(new ArchivedMessage("Subject: X\r\n\r\nText", 'kurse'), $envelope);
        $transports->send(new ArchivedMessage("Subject: Y\r\n\r\nText"), $envelope);

        self::assertCount(1, $kurse->sent);
        self::assertCount(1, $default->sent);
        self::assertStringNotContainsString('X-Transport', $kurse->sent[0][0]->toString());
    }

    public function testPurgeRemovesEntriesOlderThanRetentionWithTheirMails(): void
    {
        $archive = $this->archive();
        $old = $archive->store(null, [], self::email(), null);
        $young = $archive->store(null, [], self::email(), null);
        $now = time();
        $this->db->executeStatement('UPDATE tl_turnstile_spam SET created = ? WHERE id = ?', [$now - 91 * 86400, $old]);
        $this->db->executeStatement('UPDATE tl_turnstile_spam SET created = ? WHERE id = ?', [$now - 89 * 86400, $young]);

        self::assertSame(1, $archive->purge($now));
        self::assertSame([(string) $young], array_map('strval', $this->db->fetchFirstColumn('SELECT id FROM tl_turnstile_spam')));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam_message WHERE pid = ?', [$old]));
    }

    public function testDigestHelpersAndUnreviewedCount(): void
    {
        $archive = $this->archive();
        $a = $archive->store(null, ['source' => 'form', 'score' => 7, 'reasons' => ['tor-exit']], self::email(), null);
        $b = $archive->store(null, ['source' => 'comment'], self::email(), null);

        self::assertSame(2, $archive->countUnreviewed());
        self::assertSame([$a, $b], array_column($archive->undigested(), 'id'));
        self::assertSame('tor-exit', $archive->undigested()[0]['reasons']);

        $archive->markDigested([$a]);
        self::assertSame([$b], array_column($archive->undigested(), 'id'));

        $archive->deliver($b, 1);
        self::assertSame(1, $archive->countUnreviewed());
    }

    public function testMarkSpamOnlyTouchesUnreviewedAndDeleteRemovesMails(): void
    {
        $archive = $this->archive();
        $a = $archive->store(null, [], self::email(), null);
        $b = $archive->store(null, [], self::email(), null);
        $archive->deliver($b, 1);

        self::assertSame(1, $archive->markSpam([$a, $b], 7));
        self::assertSame(['ham' => 1, 'spam' => 1], $archive->countByLabel());
        self::assertSame(0, $archive->countUnreviewed());
        self::assertSame(7, (int) $this->db->fetchOne('SELECT label_by FROM tl_turnstile_spam WHERE id = ?', [$a]));

        self::assertSame(1, $archive->delete([$a]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_turnstile_spam_message WHERE pid = ?', [$a]));
        self::assertSame(['ham' => 1], $archive->countByLabel());
        self::assertSame(0, $archive->markSpam([], 7));
    }

    public function testPatternKeyIgnoresOrderAndVolatileSignalsAndSkipsRegistrationAndAi(): void
    {
        $key = SpamArchive::patternKey('form', ['tor-exit', 'gibberish-many', 'repeat']);

        self::assertSame('form|r'.SpamArchive::RULE_REVISION.'|gibberish-many,tor-exit', $key);
        self::assertSame($key, SpamArchive::patternKey('form', ['gibberish-many', 'net-burst', 'tor-exit']));
        self::assertNotSame($key, SpamArchive::patternKey('comment', ['gibberish-many', 'tor-exit']));
        self::assertSame('', SpamArchive::patternKey('registration', ['gibberish-many', 'tor-exit']));
        self::assertSame('', SpamArchive::patternKey('form', ['link', 'no-mx', 'ai-spam']));
        self::assertSame('', SpamArchive::patternKey('form', ['repeat']));
    }

    public function testPatternIsLearnedAndAutoConfirmedFromThreshold(): void
    {
        $archive = $this->archive();
        $meta = ['source' => 'form', 'reasons' => ['gibberish-many', 'link', 'tor-exit']];
        $key = SpamArchive::patternKey('form', $meta['reasons']);
        $ids = [];

        for ($i = 0; $i < SpamArchive::AUTO_CONFIRM_MIN; ++$i) {
            $ids[] = $archive->store(null, $meta, self::email(), null);
        }

        // Bis zur Schwelle bleibt alles ungeprüft; ein anderes Muster zählt nicht mit.
        self::assertSame(SpamArchive::AUTO_CONFIRM_MIN, $archive->countUnreviewed());
        $other = $archive->store(null, ['source' => 'form', 'reasons' => ['link']], self::email(), null);

        foreach (\array_slice($ids, 1) as $id) {
            self::assertSame(1, $archive->markSpam([$id], 7));
        }

        self::assertSame([$key => ['confirmed' => SpamArchive::AUTO_CONFIRM_MIN - 1, 'rejected' => 0, 'reset_at' => 0]], $archive->patterns());
        self::assertSame('unreviewed', $this->label($archive->store(null, $meta, self::email(), null)), 'eine Bestätigung zu wenig');

        // Erneutes Bestätigen derselben Einträge zählt nicht doppelt.
        self::assertSame(0, $archive->markSpam(\array_slice($ids, 1), 7));
        self::assertSame(1, $archive->markSpam([$ids[0]], 7));

        $auto = $archive->store(null, $meta, self::email(), null);
        $head = $this->db->fetchAssociative('SELECT label, label_by, label_at FROM tl_turnstile_spam WHERE id = ?', [$auto]);
        self::assertSame('spam', $head['label']);
        self::assertSame(0, (int) $head['label_by']);
        self::assertGreaterThan(0, (int) $head['label_at']);
        self::assertSame(1, $archive->countAutoConfirmed());
        self::assertSame('unreviewed', $this->label($other));
        self::assertSame('unreviewed', $this->label($archive->store(null, ['source' => 'form', 'reasons' => ['link']], self::email(), null)));
        // Dieselben Signale über eine Registrierung oder mit KI-Urteil werden nie automatisch bestätigt.
        self::assertSame('unreviewed', $this->label($archive->store(null, ['source' => 'registration'] + $meta, self::email(), null)));
        self::assertSame('unreviewed', $this->label($archive->store(null, ['source' => 'form', 'reasons' => [...$meta['reasons'], 'ai-spam']], self::email(), null)));
        // Angehängte Mails eines bestehenden Eintrags ändern dessen Etikett nicht.
        self::assertSame($other, $archive->store($other, $meta, self::email(), null));
        self::assertSame('unreviewed', $this->label($other));
    }

    public function testBulkConfirmCountsOncePerPatternAndAnonymousConfirmNever(): void
    {
        $archive = $this->archive();
        $meta = ['source' => 'form', 'reasons' => ['tor-exit', 'dotted-address']];
        $ids = [];

        for ($i = 0; $i < 6; ++$i) {
            $ids[] = $archive->store(null, $meta, self::email(), null);
        }

        self::assertSame(3, $archive->markSpam(\array_slice($ids, 0, 3), 7));
        self::assertSame(1, $archive->patterns()[SpamArchive::patternKey('form', $meta['reasons'])]['confirmed']);

        self::assertSame(3, $archive->markSpam(\array_slice($ids, 3), 0));
        self::assertSame(1, $archive->patterns()[SpamArchive::patternKey('form', $meta['reasons'])]['confirmed'], 'ohne Benutzer kein Lernen');
    }

    public function testNotSpamBlocksPatternAndRevertsAutoConfirmed(): void
    {
        $archive = $this->archive();
        $meta = ['source' => 'form', 'reasons' => ['tor-exit', 'dotted-address']];
        $key = SpamArchive::patternKey('form', $meta['reasons']);
        $this->db->insert('tl_turnstile_spam_pattern', ['pattern' => $key, 'confirmed' => SpamArchive::AUTO_CONFIRM_MIN]);

        $a = $archive->store(null, $meta, self::email(), null);
        $b = $archive->store(null, $meta, self::email(), null);
        $manual = $archive->store(null, ['source' => 'form', 'reasons' => ['link']], self::email(), null);
        self::assertSame(['spam', 'spam'], [$this->label($a), $this->label($b)]);

        $archive->deliver($a, 7);
        // Zweites Zustellen desselben Eintrags zählt kein zweites „Kein Spam".
        $archive->deliver($a, 7);

        self::assertSame('ham', $this->label($a));
        self::assertSame('unreviewed', $this->label($b), 'automatisch bestätigt -> zurück zur Prüfung');
        self::assertSame(1, $archive->patterns()[$key]['rejected']);
        self::assertSame('unreviewed', $this->label($archive->store(null, $meta, self::email(), null)));

        // Von Hand bestätigter Spam bleibt bestätigt.
        $archive->markSpam([$manual], 7);
        $archive->deliver($b, 7);
        self::assertSame('spam', $this->label($manual));
    }

    public function testUnblockLiftsBlockAndRestartsCounterIgnoringOlderHam(): void
    {
        $archive = $this->archive();
        $meta = ['source' => 'form', 'reasons' => ['tor-exit', 'dotted-address']];
        $key = SpamArchive::patternKey('form', $meta['reasons']);
        $this->db->insert('tl_turnstile_spam_pattern', ['pattern' => $key, 'confirmed' => SpamArchive::AUTO_CONFIRM_MIN]);

        $archive->deliver($archive->store(null, $meta, self::email(), null), 7);
        self::assertSame(1, $archive->patterns()[$key]['rejected']);

        self::assertFalse($archive->unblock('', 7));
        self::assertTrue($archive->unblock($key, 7));
        self::assertSame(0, $archive->patterns()[$key]['confirmed']);
        self::assertSame(0, $archive->patterns()[$key]['rejected']);

        // Wieder 20 Bestätigungen: der alte Ham-Eintrag (vor dem Aufheben) sperrt nicht mehr.
        $this->db->executeStatement('UPDATE tl_turnstile_spam SET label_at = label_at - 10 WHERE label = ?', ['ham']);
        $this->db->executeStatement('UPDATE tl_turnstile_spam_pattern SET confirmed = ? WHERE pattern = ?', [SpamArchive::AUTO_CONFIRM_MIN, $key]);
        self::assertSame('spam', $this->label($archive->store(null, $meta, self::email(), null)));
    }

    public function testRevertedEntryReturnsToDigestAndLostRejectionStillBlocks(): void
    {
        $archive = $this->archive();
        $meta = ['source' => 'form', 'reasons' => ['link', 'no-mx']];
        $this->db->insert('tl_turnstile_spam_pattern', ['pattern' => SpamArchive::patternKey('form', $meta['reasons']), 'confirmed' => SpamArchive::AUTO_CONFIRM_MIN]);

        $a = $archive->store(null, $meta, self::email(), null);
        $b = $archive->store(null, $meta, self::email(), null);
        self::assertTrue($archive->undigested()[0]['auto']);
        $archive->markDigested([$a, $b]);

        $archive->deliver($a, 7);
        self::assertSame([$b], array_column($archive->undigested(), 'id'), 'zurückgesetzt -> wieder in der Zusammenfassung');
        self::assertFalse($archive->undigested()[0]['auto']);

        // Zähler verloren (z. B. Fehler beim Schreiben): der Ham-Eintrag sperrt trotzdem.
        $this->db->executeStatement('UPDATE tl_turnstile_spam_pattern SET rejected = 0');
        self::assertSame('unreviewed', $this->label($archive->store(null, $meta, self::email(), null)));
    }

    public function testMissingPatternTableNeverBreaksArchive(): void
    {
        $this->db->executeStatement('DROP TABLE tl_turnstile_spam_pattern');
        $archive = $this->archive();

        $id = $archive->store(null, ['reasons' => ['tor-exit']], self::email(), null);
        self::assertIsInt($id);
        self::assertSame('unreviewed', $this->label($id));
        self::assertSame(1, $archive->markSpam([$id], 7));
        self::assertSame([], $archive->patterns());

        $ham = $archive->store(null, ['reasons' => ['tor-exit']], self::email(), null);
        self::assertSame(1, $archive->deliver((int) $ham, 7)['sent']);
    }

    private function label(?int $id): string
    {
        return (string) $this->db->fetchOne('SELECT label FROM tl_turnstile_spam WHERE id = ?', [$id]);
    }

    private function archive(?TransportInterface $transport = null, ?object $storage = null): SpamArchive
    {
        return new SpamArchive($this->db, $transport ?? new RecordingTransport(), new NullLogger(), $storage);
    }

    private static function email(): Email
    {
        return (new Email())->from('institut@nk.at')->to('kurs@nk.at')->subject('Anmeldung Grundkurs')->text('Ich möchte mich anmelden.');
    }
}

class RecordingTransport extends AbstractTransport
{
    /** @var list<array{0: RawMessage, 1: Envelope}> */
    public array $sent = [];

    public function __construct(public bool $fail = false)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'recording://';
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->fail) {
            throw new \RuntimeException('Mailserver weg');
        }

        $this->sent[] = [$message->getOriginalMessage(), $message->getEnvelope()];
    }
}

class FakeNcItem
{
    public function __construct(private readonly string $voucher, private readonly ?string $filename)
    {
    }

    public function getVoucher(): string
    {
        return $this->voucher;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }
}

class FakeNcAttachmentsHeader extends AbstractHeader
{
    /**
     * @param list<FakeNcItem> $items
     */
    public function __construct(private readonly array $items)
    {
        parent::__construct('Notification-Center-Bulky-Item-Storage-Attachments');
    }

    public function setBody(mixed $body): void
    {
    }

    public function getBody(): string
    {
        return 'vouchers';
    }

    public function getBodyAsString(): string
    {
        return 'vouchers';
    }

    /**
     * @return list<FakeNcItem>
     */
    public function getAttachmentItems(): array
    {
        return $this->items;
    }
}

class FakeNcFile
{
    public function __construct(private readonly string $contents, private readonly string $name, private readonly string $mimeType, private readonly ?int $size = null)
    {
    }

    public function getContents(): string
    {
        return $this->contents;
    }

    public function getSize(): int
    {
        return $this->size ?? \strlen($this->contents);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}

class FakeBulkyStorage
{
    /**
     * @param array<string, FakeNcFile> $files
     */
    public function __construct(private readonly array $files)
    {
    }

    public function retrieve(string $voucher): ?FakeNcFile
    {
        return $this->files[$voucher] ?? null;
    }
}
