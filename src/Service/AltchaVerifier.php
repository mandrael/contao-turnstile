<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Eigenständiger SHA-256-Proof-of-Work, damit der ALTCHA-Fallback auf Contao 4.13, 5.3 und 5.7 exakt
 * gleich läuft (Contao bringt ein eigenes ALTCHA erst ab 5.4 mit). Replay-Schutz über einen PSR-6-Cache
 * (selbstaufräumende TTL), keine Datenbank, kein Cron.
 */
class AltchaVerifier
{
    private const ALGORITHM = 'SHA-256';
    private const RANGE_MAX = 100000;

    // Nicht unter dem Minimum, das Contao für sein eigenes ALTCHA vorsieht (3600 s): ein langsamer
    // Ausfüller mit Turnstile-Fehlalarm hätte sonst eine abgelaufene Lösung und würde geblockt.
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
        // Das Expiry steckt im Salt: bei der Re-Derivation (validate) wird derselbe Salt übergeben,
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
        // Längen-Guard vor dem Decode: ein gültiges Payload ist wenige hundert Byte.
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

        // challenge ist ein SHA-256-Hex -> als Cache-Key sicher; reservierte Zeichen würden sonst
        // CacheItem::validateKey eine Exception werfen lassen (500 beim Formular-Submit).
        if (!\is_string($json['challenge']) || !preg_match('/^[0-9a-f]{64}$/', $json['challenge'])) {
            return false;
        }

        parse_str(explode('?', (string) $json['salt'], 2)[1] ?? '', $params);

        if ((int) ($params['expires'] ?? 0) < time()) {
            return false;
        }

        // Krypto zuerst, OHNE Cache-Zugriff: nur wer den PoW zum signierten Challenge gelöst hat, passt.
        // (Reihenfolge bewusst vor dem Cache, damit ein Cache-Ausfall die Verifikation nicht verhindert.)
        $check = $this->createChallenge((string) $json['salt'], (int) $json['number']);

        if (
            !hash_equals((string) $check['algorithm'], (string) $json['algorithm'])
            || !hash_equals((string) $check['challenge'], (string) $json['challenge'])
            || !hash_equals((string) $check['signature'], (string) $json['signature'])
        ) {
            return false;
        }

        // Replay-Schutz fail-closed: wirft der Cache, oder liefert save() false, gilt die Validierung
        // als fehlgeschlagen – ein PoW ohne funktionierenden Replay-Marker wäre sonst beliebig oft
        // einlösbar. Der try-Block umfasst ausschließlich Cache-I/O, daher ist \Throwable hier eng
        // begrenzt.
        // ponytail: zwei exakt parallele Requests sehen beide isHit() === false (PSR-6 kennt kein
        // atomares Add) – Grenze bleibt bestehen, Ausbauweg Symfony Lock, falls parallele
        // Wiederverwendung je real beobachtet wird.
        try {
            $item = $this->cache->getItem('mandrael_altcha_'.$json['challenge']);

            if ($item->isHit()) {
                return false;                       // bereits eingelöst
            }

            if (!$this->cache->save($item->set(true)->expiresAfter(self::EXPIRY))) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
