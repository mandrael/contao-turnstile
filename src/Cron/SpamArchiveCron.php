<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Cron;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Date;
use Contao\StringUtil;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Täglich: alte Einträge löschen, danach – nur wenn eingeschaltet und es überhaupt etwas Neues gibt – eine
 * Sammel-Mail mit den noch nicht gemeldeten Einträgen. Registrierung über den Tag contao.cronjob in
 * config/services.yaml (liefere ich Michael als YAML-Block, siehe Abschlussbericht).
 */
class SpamArchiveCron
{
    public function __construct(
        private readonly SpamArchive $archive,
        private readonly MailerInterface $mailer,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function __invoke(): void
    {
        $this->archive->purge();

        $this->framework->initialize();

        if (!$this->framework->getAdapter(Config::class)->get('turnstileSpamDigest')) {
            return;
        }

        $entries = $this->archive->undigested();

        if ([] === $entries) {
            return;
        }

        $config = $this->framework->getAdapter(Config::class);
        $admin = self::address((string) $config->get('adminEmail'));
        $to = self::address((string) $config->get('turnstileSpamDigestEmail')) ?? $admin;

        if (null === $to) {
            return;
        }

        $lines = array_map(
            static fn (array $e): string => \sprintf(
                '%s – %s – %d – %s – %s',
                Date::parse(Date::getNumericDateFormat(), $e['created']),
                $e['source'],
                $e['score'],
                $e['reasons'],
                $e['subject'],
            ),
            $entries,
        );

        $body = \sprintf("%d neue Einsendungen in der Spam-Ablage:\n\n%s\n\nBackend → System → Spam-Ablage.", \count($entries), implode("\n", $lines));

        // Absender ausdrücklich: Contaos Mailer ergänzt keinen, ohne From landet die Mail in der Fehlerwarteschlange.
        $this->mailer->send((new Email())
            ->from($admin ?? $to)
            ->to($to)
            ->subject(\sprintf('%d neue Einsendungen in der Spam-Ablage', \count($entries)))
            ->text($body));

        $this->archive->markDigested(array_column($entries, 'id'));
    }

    /**
     * Contao speichert Adressen auch als „Name [adresse]".
     */
    private static function address(string $value): ?Address
    {
        [$name, $email] = StringUtil::splitFriendlyEmail(trim($value));

        return '' === $email ? null : new Address($email, $name);
    }
}
