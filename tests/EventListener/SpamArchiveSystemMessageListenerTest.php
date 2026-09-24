<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\EventListener;

use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoTurnstileBundle\EventListener\SpamArchiveSystemMessageListener;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;

class SpamArchiveSystemMessageListenerTest extends ContaoTestCase
{
    public function testNoUnreviewedEntriesReturnsEmptyString(): void
    {
        $archive = $this->createMock(SpamArchive::class);
        $archive->method('countUnreviewed')->willReturn(0);

        self::assertSame('', (new SpamArchiveSystemMessageListener($archive))->onGetSystemMessages());
    }

    public function testUnreviewedEntriesReturnMessageWithCount(): void
    {
        $archive = $this->createMock(SpamArchive::class);
        $archive->method('countUnreviewed')->willReturn(3);

        $message = (new SpamArchiveSystemMessageListener($archive))->onGetSystemMessages();

        self::assertStringContainsString('3', $message);
        self::assertStringContainsString('tl_info', $message);
    }

    public function testExceptionYieldsEmptyStringInsteadOfThrowing(): void
    {
        // Vor der Migration existiert die Tabelle noch nicht; countUnreviewed() wirft dann eine DB-Exception.
        $archive = $this->createMock(SpamArchive::class);
        $archive->method('countUnreviewed')->willThrowException(new \RuntimeException('table missing'));

        self::assertSame('', (new SpamArchiveSystemMessageListener($archive))->onGetSystemMessages());
    }
}
