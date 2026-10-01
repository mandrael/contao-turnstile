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

        $all = $this->archive->undigested();
        // Von Hand bestätigter Spam braucht keine Prüfung mehr: nur zählen. Automatisch bestätigte bleiben mit
        // Betreff in der Liste, damit eine echte Anfrage in einem gelernten Muster nicht unbemerkt liegen bleibt.
        $entries = array_values(array_filter($all, static fn (array $e): bool => 'spam' !== $e['label'] || $e['auto']));
        $confirmed = \count($all) - \count($entries);

        if ([] === $entries) {
            if ([] !== $all) {
                $this->archive->markDigested(array_column($all, 'id'));
            }

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
                '%s%s – %s – %d – %s – %s',
                $e['auto'] ? '[automatisch als Spam bestätigt] ' : '',
                Date::parse(Date::getNumericDateFormat(), $e['created']),
                $e['source'],
                $e['score'],
                $e['reasons'],
                $e['subject'],
            ),
            $entries,
        );

        $body = \sprintf("%d neue Einsendungen in der Spam-Ablage:\n\n%s\n\n%sBackend → System → Spam-Ablage.", \count($entries), implode("\n", $lines), $confirmed > 0 ? \sprintf("Außerdem %d bereits als Spam bestätigt.\n\n", $confirmed) : '');

        // Absender ausdrücklich: Contaos Mailer ergänzt keinen, ohne From landet die Mail in der Fehlerwarteschlange.
        $this->mailer->send((new Email())
            ->from($admin ?? $to)
            ->to($to)
            ->subject(\sprintf('%d neue Einsendungen in der Spam-Ablage', \count($entries)))
            ->text($body));

        $this->archive->markDigested(array_column($all, 'id'));
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
