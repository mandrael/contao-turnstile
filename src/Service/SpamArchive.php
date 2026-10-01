<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Mandrael\ContaoTurnstileBundle\Mailer\ArchivedMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Spam-Ablage: Mails einer als „Spam sicher" eingestuften Einsendung werden hier abgelegt statt versendet und
 * lassen sich im Backend nachträglich zustellen. Gespeichert wird der fertige Mailtext (kein PHP-Objekt), damit
 * ein Eintrag Bundle- und Symfony-Updates übersteht und kein unserialize() auf Datenbankinhalte nötig ist.
 *
 * Jede Methode, die im Versandpfad läuft, wirft nie: store() meldet ein Scheitern mit null, der Aufrufer nimmt
 * dann den Rückfallweg (Versand wie vor der Ablage), damit keine Einsendung verloren geht.
 */
class SpamArchive
{
    public const TABLE = 'tl_turnstile_spam';
    public const MESSAGE_TABLE = 'tl_turnstile_spam_message';
    public const PATTERN_TABLE = 'tl_turnstile_spam_pattern';
    public const RETENTION_DAYS = 90;
    public const RULE_VERSION = '0.8.0';

    // Lernende Auto-Bestätigung: Ein Signalmuster (die gespeicherten reasons) gilt ab so vielen manuellen
    // Bestätigungen ohne ein einziges „Kein Spam" als sicher, neue Einträge damit werden automatisch als Spam
    // bestätigt (label_by = 0). Ein einziges „Kein Spam" sperrt das Muster dauerhaft.
    public const AUTO_CONFIRM_MIN = 20;

    // Größer: Rückfall statt Ablage. Contao-Uploads liegen standardmäßig weit darunter; die Grenze hält den
    // Mailtext unter üblichen max_allowed_packet-Werten (16 MB).
    public const MAX_BYTES = 12 * 1024 * 1024;

    // Ein Versand, der so lange auf „sending" steht, ist abgebrochen oder unklar ausgegangen.
    public const UNCLEAR_AFTER = 900;

    private const PREVIEW_BYTES = 8000;
    private const NC_ATTACHMENTS_HEADER = 'Notification-Center-Bulky-Item-Storage-Attachments';

    /**
     * @param object|null $bulkyItemStorage Terminal42\NotificationCenterBundle\BulkyItem\BulkyItemStorage, falls
     *                                      das Notification Center installiert ist
     */
    public function __construct(
        private readonly Connection $db,
        private readonly TransportInterface $transports,
        private readonly LoggerInterface $logger,
        private readonly ?object $bulkyItemStorage = null,
    ) {
    }

    /**
     * Legt eine Mail ab. Ohne $archiveId entsteht ein neuer Eintrag, sonst wird die Mail an ihn angehängt.
     *
     * @param array{source?: string, score?: int, reasons?: list<string>} $meta
     *
     * @return int|null ID des Eintrags, null wenn nicht abgelegt werden konnte
     */
    public function store(?int $archiveId, array $meta, RawMessage $message, ?Envelope $envelope): ?int
    {
        try {
            $prepared = $this->prepare($message, $envelope);

            if (null === $prepared) {
                return null;
            }

            $now = time();
            $pattern = substr(implode(', ', $meta['reasons'] ?? []), 0, 255);
            $auto = null === $archiveId ? $this->autoConfirmCount($pattern) : null;

            // Kopf- und Mailzeile gemeinsam, damit ein Fehler beim zweiten INSERT keinen leeren Eintrag hinterlässt.
            $id = $this->db->transactional(function () use ($archiveId, $meta, $prepared, $now, $pattern, $auto): int {
                if (null === $archiveId) {
                    $this->db->insert(self::TABLE, [
                        'tstamp' => $now,
                        'created' => $now,
                        'source' => substr((string) ($meta['source'] ?? ''), 0, 16),
                        'score' => (int) ($meta['score'] ?? 0),
                        'reasons' => $pattern,
                        'subject' => mb_substr($prepared['subject'], 0, 255),
                        'recipients' => implode(', ', $prepared['recipients']),
                        'preview' => $prepared['preview'],
                        'label' => null !== $auto ? 'spam' : 'unreviewed',
                        'label_at' => null !== $auto ? $now : 0,
                        'rule_version' => self::RULE_VERSION,
                    ]);
                    $archiveId = (int) $this->db->lastInsertId();
                } else {
                    $this->db->executeStatement('UPDATE '.self::TABLE.' SET tstamp = ? WHERE id = ?', [$now, $archiveId]);
                }

                $this->db->insert(self::MESSAGE_TABLE, [
                    'pid' => $archiveId,
                    'tstamp' => $now,
                    'mime' => $prepared['mime'],
                    'sender' => $prepared['sender'],
                    'recipients' => implode(', ', $prepared['recipients']),
                    'transport' => $prepared['transport'],
                    'status' => 'stored',
                ]);

                return $archiveId;
            });
        } catch (\Throwable $e) {
            $this->log('error', 'Spam-Ablage fehlgeschlagen ('.$e::class.'), Mail geht auf dem Rückfallweg hinaus.');

            return null;
        }

        // Nach dem Commit: nichts hiervon darf die Mail noch auf den Rückfallweg schicken (log() wirft nie).
        if (null !== $auto) {
            $this->log('info', \sprintf('Spam-Ablage: Eintrag %d automatisch als Spam bestätigt (Muster „%s“, %d× manuell bestätigt).', $id, $pattern, $auto));
        }

        return $id;
    }

    /**
     * „Doch zustellen": Etikett „kein Spam" setzen und jede noch nicht versendete Mail über den gespeicherten
     * Transport verschicken, synchron und an der Mail-Warteschlange vorbei, damit „sent" die Annahme durch den
     * Mailserver bedeutet. Jede Mail wird vorher atomar beansprucht; parallele Aufrufe senden nichts doppelt.
     *
     * @return array{sent: int, failed: int, unclear: int}
     */
    public function deliver(int $id, int $userId, bool $retryUnclear = false): array
    {
        $now = time();
        $result = ['sent' => 0, 'failed' => 0, 'unclear' => 0];
        $head = $this->db->fetchAssociative('SELECT label, reasons FROM '.self::TABLE.' WHERE id = ?', [$id]);

        $this->db->executeStatement(
            'UPDATE '.self::TABLE.' SET label = ?, label_by = ?, label_at = ?, tstamp = ? WHERE id = ?',
            ['ham', $userId, $now, $now, $id],
        );

        if (false !== $head && 'ham' !== $head['label']) {
            try {
                $this->rejectPattern((string) $head['reasons'], $id);
            } catch (\Throwable $e) {
                $this->log('error', 'Spam-Ablage: Muster nicht zurückgesetzt ('.$e::class.'), Zustellung läuft trotzdem.');
            }
        }

        $messages = $this->db->fetchAllAssociative(
            'SELECT id, status, claimed_at FROM '.self::MESSAGE_TABLE.' WHERE pid = ? ORDER BY id',
            [$id],
        );

        foreach ($messages as $row) {
            $status = (string) $row['status'];
            $unclear = 'sending' === $status && (int) $row['claimed_at'] < $now - self::UNCLEAR_AFTER;

            if ('sending' === $status && !$unclear) {
                continue;
            }

            if ($unclear && !$retryUnclear) {
                ++$result['unclear'];

                continue;
            }

            if (!\in_array($status, ['stored', 'failed', 'sending'], true)) {
                continue;
            }

            $claimed = $this->db->executeStatement(
                'UPDATE '.self::MESSAGE_TABLE.' SET status = ?, claimed_at = ? WHERE id = ? AND status = ? AND claimed_at = ?',
                ['sending', $now, $row['id'], $status, (int) $row['claimed_at']],
            );

            if (1 !== $claimed) {
                continue;
            }

            $message = $this->db->fetchAssociative(
                'SELECT mime, sender, recipients, transport FROM '.self::MESSAGE_TABLE.' WHERE id = ?',
                [$row['id']],
            );

            try {
                $recipients = array_map(static fn (string $a): Address => new Address($a), self::split((string) $message['recipients']));

                $this->transports->send(
                    new ArchivedMessage((string) $message['mime'], (string) $message['transport']),
                    new Envelope(new Address((string) $message['sender']), $recipients),
                );

                $this->db->executeStatement(
                    'UPDATE '.self::MESSAGE_TABLE.' SET status = ?, sent_at = ?, error = ? WHERE id = ?',
                    ['sent', time(), '', $row['id']],
                );
                ++$result['sent'];
            } catch (\Throwable $e) {
                $this->db->executeStatement(
                    'UPDATE '.self::MESSAGE_TABLE.' SET status = ?, error = ? WHERE id = ?',
                    ['failed', mb_substr($e::class.': '.$e->getMessage(), 0, 255), $row['id']],
                );
                ++$result['failed'];
            }
        }

        $open = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM '.self::MESSAGE_TABLE.' WHERE pid = ? AND status <> ?',
            [$id, 'sent'],
        );

        if (0 === $open) {
            $this->db->executeStatement('UPDATE '.self::TABLE.' SET delivered = ? WHERE id = ?', [time(), $id]);
        }

        $this->log('info', 'Spam-Ablage: Eintrag '.$id.' zugestellt ('.$result['sent'].' versendet, '.$result['failed'].' fehlgeschlagen).');

        return $result;
    }

    /**
     * Löscht Einträge samt Mails, die älter als RETENTION_DAYS sind, in Portionen.
     */
    public function purge(?int $now = null): int
    {
        $cutoff = ($now ?? time()) - self::RETENTION_DAYS * 86400;
        $deleted = 0;

        do {
            $ids = array_map('intval', $this->db->fetchFirstColumn(
                'SELECT id FROM '.self::TABLE.' WHERE created < ? ORDER BY id LIMIT 200',
                [$cutoff],
            ));

            if ([] === $ids) {
                break;
            }

            $placeholders = implode(',', array_fill(0, \count($ids), '?'));
            $this->db->executeStatement('DELETE FROM '.self::MESSAGE_TABLE.' WHERE pid IN ('.$placeholders.')', $ids);
            $deleted += $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE id IN ('.$placeholders.')', $ids);
        } while (200 === \count($ids));

        return $deleted;
    }

    /**
     * Einträge, die noch in keiner Tageszusammenfassung standen. Ohne Mailinhalte außer dem Betreff.
     *
     * @return list<array{id: int, created: int, source: string, score: int, reasons: string, subject: string, label: string, auto: bool}>
     */
    public function undigested(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created, source, score, reasons, subject, label, label_by FROM '.self::TABLE.' WHERE digested = ? ORDER BY created',
            [0],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'created' => (int) $r['created'],
            'source' => (string) $r['source'],
            'score' => (int) $r['score'],
            'reasons' => (string) $r['reasons'],
            'subject' => (string) $r['subject'],
            'label' => (string) $r['label'],
            'auto' => 'spam' === $r['label'] && 0 === (int) $r['label_by'],
        ], $rows);
    }

    /**
     * @param list<int> $ids
     */
    public function markDigested(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE '.self::TABLE.' SET digested = 1 WHERE id IN ('.implode(',', array_fill(0, \count($ids), '?')).')',
            $ids,
        );
    }

    /**
     * Bestätigt ungeprüfte Einträge als Spam. Bereits zugestellte oder anders eingestufte bleiben unverändert.
     *
     * @param list<int> $ids
     */
    public function markSpam(array $ids, int $userId): int
    {
        if ([] === $ids) {
            return 0;
        }

        $now = time();
        $where = 'label = ? AND delivered = 0 AND id IN ('.implode(',', array_fill(0, \count($ids), '?')).')';
        $patterns = $this->db->fetchFirstColumn('SELECT reasons FROM '.self::TABLE.' WHERE '.$where, ['unreviewed', ...$ids]);
        $count = $this->db->executeStatement(
            'UPDATE '.self::TABLE.' SET label = ?, label_by = ?, label_at = ?, tstamp = ? WHERE '.$where,
            ['spam', $userId, $now, $now, 'unreviewed', ...$ids],
        );

        // ponytail: Muster werden vor dem UPDATE gelesen; ein paralleler Klick kann einmal doppelt zählen, das
        // verschiebt nur den Zeitpunkt der Automatik um eine Bestätigung.
        foreach (array_count_values(array_map('strval', $patterns)) as $pattern => $n) {
            $this->learn((string) $pattern, 'confirmed', $n);
        }

        $this->log('info', 'Spam-Ablage: '.$count.' Eintrag/Einträge als Spam bestätigt.');

        return $count;
    }

    /**
     * Löscht Einträge samt Mails endgültig (ohne tl_undo, wie das Löschen in der Liste).
     *
     * @param list<int> $ids
     */
    public function delete(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, \count($ids), '?'));

        $count = $this->db->transactional(function () use ($ids, $placeholders): int {
            $this->db->executeStatement('DELETE FROM '.self::MESSAGE_TABLE.' WHERE pid IN ('.$placeholders.')', $ids);

            return $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE id IN ('.$placeholders.')', $ids);
        });

        $this->log('info', 'Spam-Ablage: '.$count.' Eintrag/Einträge gelöscht.');

        return $count;
    }

    /**
     * @return array<string, int> Anzahl je Etikett (unreviewed, spam, ham)
     */
    public function countByLabel(): array
    {
        return array_map('intval', $this->db->fetchAllKeyValue('SELECT label, COUNT(*) FROM '.self::TABLE.' GROUP BY label'));
    }

    /**
     * Lernstand je Muster.
     *
     * @return array<string, array{confirmed: int, rejected: int}>
     */
    public function patterns(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT pattern, confirmed, rejected FROM '.self::PATTERN_TABLE);
        } catch (\Throwable) {
            return [];
        }

        $patterns = [];

        foreach ($rows as $row) {
            $patterns[(string) $row['pattern']] = ['confirmed' => (int) $row['confirmed'], 'rejected' => (int) $row['rejected']];
        }

        return $patterns;
    }

    public function countUnreviewed(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.self::TABLE.' WHERE label = ?', ['unreviewed']);
    }

    /**
     * Anzahl manueller Bestätigungen, wenn das Muster automatisch bestätigt wird, sonst null. Wirft nie: Fehlt die
     * Tabelle (Schema noch nicht aktualisiert), bleibt der Eintrag ungeprüft, die Ablage selbst läuft weiter.
     */
    private function autoConfirmCount(string $pattern): ?int
    {
        if ('' === $pattern) {
            return null;
        }

        $stats = $this->patterns()[$pattern] ?? null;

        if (null === $stats || 0 !== $stats['rejected'] || $stats['confirmed'] < self::AUTO_CONFIRM_MIN) {
            return null;
        }

        // Zweite Sperre, falls ein „Kein Spam"-Zähler verloren ging: noch vorhandene Ham-Einträge des Musters.
        $ham = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.self::TABLE.' WHERE label = ? AND reasons = ?', ['ham', $pattern]);

        return 0 === $ham ? $stats['confirmed'] : null;
    }

    /**
     * „Kein Spam": Muster dauerhaft sperren und dessen automatisch bestätigte Einträge zurück auf ungeprüft setzen.
     */
    private function rejectPattern(string $pattern, int $id): void
    {
        if ('' === $pattern) {
            return;
        }

        $this->learn($pattern, 'rejected', 1);

        $reverted = $this->db->executeStatement(
            'UPDATE '.self::TABLE.' SET label = ?, label_at = 0, digested = 0, tstamp = ? WHERE label = ? AND label_by = 0 AND delivered = 0 AND reasons = ? AND id <> ?',
            ['unreviewed', time(), 'spam', $pattern, $id],
        );

        $this->log('info', \sprintf('Spam-Ablage: Muster „%s“ als Kein Spam markiert, automatische Bestätigung dafür gesperrt (%d Einträge zurück auf ungeprüft).', $pattern, $reverted));
    }

    /**
     * Portabel ohne ON DUPLICATE KEY (Tests laufen auf SQLite). Wirft nie: ein verlorener Zähler verzögert nur die
     * Automatik, darf aber keine Aktion im Backend abbrechen.
     */
    private function learn(string $pattern, string $column, int $n): void
    {
        if ('' === $pattern || $n < 1) {
            return;
        }

        try {
            $now = time();

            $update = fn (): int => $this->db->executeStatement('UPDATE '.self::PATTERN_TABLE.' SET '.$column.' = '.$column.' + ?, tstamp = ? WHERE pattern = ?', [$n, $now, $pattern]);

            if (0 === $update()) {
                try {
                    $this->db->insert(self::PATTERN_TABLE, ['tstamp' => $now, 'pattern' => $pattern, 'confirmed' => 0, 'rejected' => 0, $column => $n]);
                } catch (UniqueConstraintViolationException) {
                    $update(); // paralleler Aufruf hat die Zeile gerade angelegt
                }
            }
        } catch (\Throwable $e) {
            $this->log('error', 'Spam-Ablage: Lernzähler nicht gespeichert ('.$e::class.').');
        }
    }

    /**
     * Macht die Mail vollständig und liefert den fertigen Mailtext samt Versanddaten, oder null, wenn sie sich
     * nicht vollständig ablegen lässt.
     *
     * @return array{mime: string, transport: string, sender: string, recipients: list<string>, subject: string, preview: string}|null
     */
    private function prepare(RawMessage $message, ?Envelope $envelope): ?array
    {
        $transport = '';
        $subject = '';
        $preview = '';

        if ($message instanceof Message) {
            $envelope ??= Envelope::create($message);
            $message = clone $message;
            $headers = $message->getHeaders();

            if ($headers->has('X-Transport')) {
                $transport = $headers->get('X-Transport')->getBodyAsString();
                $headers->remove('X-Transport');
            }

            if ($headers->has(self::NC_ATTACHMENTS_HEADER) && !$this->attachNotificationCenterFiles($message)) {
                return null;
            }

            if ($message instanceof Email) {
                $subject = (string) $message->getSubject();
                $body = $message->getTextBody();
                $preview = \is_string($body) ? $body : trim(strip_tags((string) $message->getHtmlBody()));
            }
        } elseif (null === $envelope) {
            // Symfony versendet einen RawMessage ohne Envelope ohnehin nicht.
            return null;
        }

        $mime = $message->toString();

        if (\strlen($mime) > self::MAX_BYTES) {
            $this->log('error', 'Spam-Ablage: Mail größer als '.intdiv(self::MAX_BYTES, 1048576).' MB, Mail geht auf dem Rückfallweg hinaus.');

            return null;
        }

        return [
            'mime' => $mime,
            'transport' => $transport,
            'sender' => $envelope->getSender()->getAddress(),
            'recipients' => array_map(static fn (Address $a): string => $a->getAddress(), $envelope->getRecipients()),
            'subject' => $subject,
            'preview' => mb_strcut($preview, 0, self::PREVIEW_BYTES),
        ];
    }

    /**
     * Das Notification Center hängt Dateien erst beim eigentlichen Versand an (MailerAttachmentsListener) und
     * überspringt dabei fehlende still; seine Ablage verfällt nach sieben Tagen. Deshalb hier selbst auflösen und
     * bei jeder fehlenden Datei scheitern.
     */
    private function attachNotificationCenterFiles(Message $message): bool
    {
        $header = $message->getHeaders()->get(self::NC_ATTACHMENTS_HEADER);

        if (null === $this->bulkyItemStorage || !$message instanceof Email || null === $header || !method_exists($header, 'getAttachmentItems')) {
            $this->log('error', 'Spam-Ablage: Anhänge des Notification Centers nicht auflösbar, Mail geht auf dem Rückfallweg hinaus.');

            return false;
        }

        $files = [];
        $bytes = 0;

        foreach ($header->getAttachmentItems() as $item) {
            $file = $this->bulkyItemStorage->retrieve($item->getVoucher());

            if (!\is_object($file) || !method_exists($file, 'getContents')) {
                $this->log('error', 'Spam-Ablage: Anhang des Notification Centers fehlt, Mail geht auf dem Rückfallweg hinaus.');

                return false;
            }

            $bytes += method_exists($file, 'getSize') ? (int) $file->getSize() : 0;
            $files[] = [$item, $file];
        }

        // Vor dem Laden prüfen: große Anhänge erst in den Speicher zu holen, könnte das Speicherlimit sprengen.
        if ($bytes > self::MAX_BYTES) {
            $this->log('error', 'Spam-Ablage: Anhänge größer als '.intdiv(self::MAX_BYTES, 1048576).' MB, Mail geht auf dem Rückfallweg hinaus.');

            return false;
        }

        foreach ($files as [$item, $file]) {
            $message->attach($file->getContents(), $item->getFilename() ?? $file->getName(), $file->getMimeType());
        }

        $message->getHeaders()->remove(self::NC_ATTACHMENTS_HEADER);

        return true;
    }

    /**
     * @return list<string>
     */
    private static function split(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list))));
    }

    private function log(string $level, string $message): void
    {
        try {
            $this->logger->log($level, 'Cloudflare Turnstile: '.$message, ['contao' => new ContaoContext(__METHOD__, 'error' === $level ? ContaoContext::ERROR : ContaoContext::FORMS)]);
        } catch (\Throwable) {
        }
    }
}
