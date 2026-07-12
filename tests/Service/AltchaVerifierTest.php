<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Mandrael\ContaoTurnstileBundle\Service\AltchaVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AltchaVerifierTest extends TestCase
{
    private const SECRET = 'test-kernel-secret';

    public function testCreateChallengeStructure(): void
    {
        $challenge = $this->verifier()->createChallenge();

        $this->assertSame('SHA-256', $challenge['algorithm']);
        $this->assertSame(100000, $challenge['maxnumber']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $challenge['challenge']);
        $this->assertStringContainsString('?expires=', (string) $challenge['salt']);
        $this->assertNotEmpty($challenge['signature']);
    }

    public function testValidSolutionPasses(): void
    {
        $verifier = $this->verifier();

        $this->assertTrue($verifier->validate($this->solve($verifier)));
    }

    public function testReplayIsBlocked(): void
    {
        $verifier = $this->verifier();
        $payload = $this->solve($verifier);

        $this->assertTrue($verifier->validate($payload));
        $this->assertFalse($verifier->validate($payload));
    }

    public function testExpiredChallengeIsBlocked(): void
    {
        $verifier = $this->verifier();
        $salt = bin2hex(random_bytes(12)).'?expires='.(time() - 10).'&';

        $this->assertFalse($verifier->validate($this->solve($verifier, $salt)));
    }

    public function testForgedSignatureIsBlocked(): void
    {
        $verifier = $this->verifier();
        $solution = $this->decode($this->solve($verifier));
        $solution['signature'] = str_repeat('0', 64);

        $this->assertFalse($verifier->validate($this->encode($solution)));
    }

    public function testNumberOutOfRangeIsBlocked(): void
    {
        // number außerhalb 0..RANGE_MAX: die Re-Derivation erzeugt einen anderen Challenge-Hash
        // -> Vergleich scheitert. Kein separater Bounds-Check nötig.
        $verifier = $this->verifier();
        $solution = $this->decode($this->solve($verifier));
        $solution['number'] = 999999999;

        $this->assertFalse($verifier->validate($this->encode($solution)));
    }

    public function testGarbagePayloadReturnsFalseWithoutException(): void
    {
        $verifier = $this->verifier();

        $this->assertFalse($verifier->validate(''));
        $this->assertFalse($verifier->validate('not base64 !@#'));
        $this->assertFalse($verifier->validate($this->encode(['foo' => 'bar'])));
    }

    public function testReservedCharChallengeReturnsFalseWithoutException(): void
    {
        // Cache-Key-Fix: ein challenge mit reserviertem Zeichen darf keine Exception werfen.
        $payload = $this->encode([
            'challenge' => 'a/b',
            'salt' => 'x?expires='.(time() + 100).'&',
            'algorithm' => 'SHA-256',
            'signature' => 'sig',
            'number' => 1,
        ]);

        $this->assertFalse($this->verifier()->validate($payload));
    }

    public function testOverlongPayloadReturnsFalse(): void
    {
        $this->assertFalse($this->verifier()->validate($this->encode(['x' => str_repeat('a', 3000)])));
    }

    public function testCacheFailureIsFailOpen(): void
    {
        // Cache-Backend fällt aus (getItem wirft): kein 500, ein gültiger PoW wird akzeptiert (fail-open),
        // der Replay-Marker bleibt Best Effort. createChallenge nutzt den Cache nicht -> Payload ableitbar.
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('cache down'));

        $verifier = new AltchaVerifier(self::SECRET, $cache);

        $this->assertTrue($verifier->validate($this->solve($verifier)));
    }

    private function verifier(): AltchaVerifier
    {
        return new AltchaVerifier(self::SECRET, new ArrayAdapter());
    }

    /**
     * Baut ein gültiges Client-Payload mit bekannter Zahl (der Worker würde sie per Brute-Force finden).
     */
    private function solve(AltchaVerifier $verifier, ?string $salt = null, int $number = 42): string
    {
        $challenge = $verifier->createChallenge($salt, $number);

        return $this->encode([
            'algorithm' => $challenge['algorithm'],
            'challenge' => $challenge['challenge'],
            'number' => $number,
            'salt' => $challenge['salt'],
            'signature' => $challenge['signature'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return base64_encode((string) json_encode($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $payload): array
    {
        return (array) json_decode((string) base64_decode($payload, true), true);
    }
}
