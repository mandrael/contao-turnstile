<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Cron;

use Contao\Config;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoTurnstileBundle\Cron\SpamArchiveCron;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class SpamArchiveCronTest extends ContaoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TL_CONFIG']['dateFormat'] = 'Y-m-d';
    }

    public function testAlwaysPurgesEvenWithDigestOff(): void
    {
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::once())->method('purge');
        $archive->expects(self::never())->method('undigested');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $framework = $this->createContaoFrameworkMock([Config::class => $this->createConfiguredAdapterMock([
            'get' => false,
        ])]);

        (new SpamArchiveCron($archive, $mailer, $framework))();
    }

    public function testDigestOnButNoEntriesSendsNothing(): void
    {
        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::once())->method('purge');
        $archive->expects(self::once())->method('undigested')->willReturn([]);
        $archive->expects(self::never())->method('markDigested');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $framework = $this->createContaoFrameworkMock([Config::class => $this->createConfiguredAdapterMock([
            'get' => true,
        ])]);

        (new SpamArchiveCron($archive, $mailer, $framework))();
    }

    public function testDigestOnWithEntriesSendsOneMailAndMarksDigested(): void
    {
        $entries = [
            ['id' => 1, 'created' => 1000, 'source' => 'form', 'score' => 9, 'reasons' => 'tor', 'subject' => 'A'],
            ['id' => 2, 'created' => 2000, 'source' => 'comment', 'score' => 5, 'reasons' => 'links', 'subject' => 'B'],
        ];

        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::once())->method('purge');
        $archive->expects(self::once())->method('undigested')->willReturn($entries);
        $archive->expects(self::once())->method('markDigested')->with([1, 2]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->with(self::callback(static function (RawMessage $message): bool {
                self::assertInstanceOf(Email::class, $message);
                self::assertSame('digest@example.com', $message->getTo()[0]->getAddress());
                self::assertSame('digest@example.com', $message->getFrom()[0]->getAddress());

                return true;
            }));

        $config = $this->createAdapterMock(['get']);
        $config->method('get')->willReturnMap([
            ['turnstileSpamDigest', true],
            ['turnstileSpamDigestEmail', 'digest@example.com'],
        ]);

        $framework = $this->createContaoFrameworkMock([Config::class => $config]);

        (new SpamArchiveCron($archive, $mailer, $framework))();
    }

    public function testFailedDigestSendLeavesEntriesUndigested(): void
    {
        $archive = $this->createMock(SpamArchive::class);
        $archive->method('undigested')->willReturn([['id' => 1, 'created' => 1000, 'source' => 'form', 'score' => 9, 'reasons' => 'tor', 'subject' => 'A']]);
        $archive->expects(self::never())->method('markDigested');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('Mailserver weg'));

        $config = $this->createAdapterMock(['get']);
        $config->method('get')->willReturnMap([
            ['turnstileSpamDigest', true],
            ['turnstileSpamDigestEmail', 'digest@example.com'],
        ]);

        $this->expectException(\RuntimeException::class);
        (new SpamArchiveCron($archive, $mailer, $this->createContaoFrameworkMock([Config::class => $config])))();
    }

    public function testEmptyDigestAddressFallsBackToAdminEmail(): void
    {
        $entries = [['id' => 1, 'created' => 1000, 'source' => 'form', 'score' => 9, 'reasons' => 'tor', 'subject' => 'A']];

        $archive = $this->createMock(SpamArchive::class);
        $archive->method('undigested')->willReturn($entries);
        $archive->expects(self::once())->method('markDigested')->with([1]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->with(self::callback(static function (RawMessage $message): bool {
                self::assertInstanceOf(Email::class, $message);
                self::assertSame('admin@example.com', $message->getTo()[0]->getAddress());
                self::assertSame('Institut', $message->getFrom()[0]->getName());
                self::assertSame('admin@example.com', $message->getFrom()[0]->getAddress());

                return true;
            }));

        $config = $this->createAdapterMock(['get']);
        $config->method('get')->willReturnMap([
            ['turnstileSpamDigest', true],
            ['turnstileSpamDigestEmail', ''],
            ['adminEmail', 'Institut [admin@example.com]'],
        ]);

        $framework = $this->createContaoFrameworkMock([Config::class => $config]);

        (new SpamArchiveCron($archive, $mailer, $framework))();
    }
}
