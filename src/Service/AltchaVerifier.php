<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Self-contained ALTCHA-Proof-of-Work (SHA-256), nachgebaut nach dem Contao-Core-Schema, aber OHNE
 * Rueckgriff auf dessen @internal, Doctrine-gekoppelten Verifier – damit identisch auf Contao 4.13 und 5.x.
 * Replay-Schutz ueber einen PSR-6-Cache (selbstaufraeumende TTL), keine DB, kein Cron.
 */
class AltchaVerifier
{
    private const ALGORITHM = 'SHA-256';
    private const RANGE_MAX = 100000;

    // Nicht unter Cores eigenem Minimum (challenge_expiry ->min(3600)): ein langsamer Ausfueller mit
    // Turnstile-False-Positive haette sonst eine abgelaufene Loesung und wuerde geblockt.
    private const EXPIRY = 3600;

    public function __construct(
        private readonly string $secret,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<string, string|int>
     */
    public function createChallenge(?string $salt = null, ?int $number = null): array
    {
        $algo = 'sha256';
        // Das Expiry steckt im Salt: bei der Re-Derivation (validate) wird derselbe Salt uebergeben,
        // die Ablaufzeit also NICHT neu berechnet.
        $salt ??= bin2hex(random_bytes(12)).'?expires='.(time() + self::EXPIRY).'&';
        $number ??= random_int(0, self::RANGE_MAX);
        $challenge = hash($algo, $salt.$number);

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'salt' => $salt,
            'signature' => hash_hmac($algo, $challenge, $this->secret),
            'maxnumber' => self::RANGE_MAX,
        ];
    }

    public function validate(string $payload): bool
    {
        // Laengen-Guard vor dem Decode: ein gueltiges Payload ist wenige hundert Byte.
        if ('' === $payload || \strlen($payload) > 2048) {
            return false;
        }

        try {
            $json = json_decode(base64_decode($payload, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!\is_array($json)) {
            return false;
        }

        foreach (['challenge', 'salt', 'algorithm', 'signature', 'number'] as $key) {
            if (!isset($json[$key]) || (!\is_string($json[$key]) && !\is_int($json[$key]))) {
                return false;
            }
        }

        // challenge ist ein SHA-256-Hex -> als Cache-Key sicher; reservierte Zeichen wuerden sonst
        // CacheItem::validateKey eine Exception werfen lassen (500 beim Formular-Submit).
        if (!\is_string($json['challenge']) || !preg_match('/^[0-9a-f]{64}$/', $json['challenge'])) {
            return false;
        }

        parse_str(explode('?', (string) $json['salt'], 2)[1] ?? '', $params);

        if ((int) ($params['expires'] ?? 0) < time()) {
            return false;
        }

        // Krypto zuerst, OHNE Cache-Zugriff: nur wer den PoW zum signierten Challenge geloest hat, passt.
        // (Reihenfolge bewusst vor dem Cache, damit ein Cache-Ausfall die Verifikation nicht verhindert.)
        $check = $this->createChallenge((string) $json['salt'], (int) $json['number']);

        if (
            !hash_equals((string) $check['algorithm'], (string) $json['algorithm'])
            || !hash_equals((string) $check['challenge'], (string) $json['challenge'])
            || !hash_equals((string) $check['signature'], (string) $json['signature'])
        ) {
            return false;
        }

        // Replay-Schutz best-effort ueber den Cache. Faellt das Backend aus, den Submit NICHT mit einem
        // 500 abwuergen: der PoW ist gueltig -> fail-open (der Replay-Marker bleibt Best Effort). Der
        // try-Block umfasst ausschliesslich Cache-I/O, daher ist \Throwable hier eng begrenzt.
        try {
            $item = $this->cache->getItem('mandrael_altcha_'.$json['challenge']);

            if ($item->isHit()) {
                return false;                       // bereits eingeloest
            }

            $this->cache->save($item->set(true)->expiresAfter(self::EXPIRY));
        } catch (\Throwable) {
            // Cache-Backend nicht verfuegbar: Verifikation bleibt gueltig, Replay-Marker nicht gesetzt.
        }

        return true;
    }
}
