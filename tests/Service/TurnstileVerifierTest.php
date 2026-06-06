<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Contao\Config;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoTurnstileBundle\Service\TurnstileVerifier;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifierTest extends ContaoTestCase
{
    public function testValidTokenPasses(): void
    {
        $verifier = $this->createVerifier(new MockHttpClient(new MockResponse((string) json_encode(['success' => true]))));

        $this->assertTrue($verifier->validate('a-token'));
    }

    public function testInvalidTokenIsBlocked(): void
    {
        $verifier = $this->createVerifier(new MockHttpClient(new MockResponse((string) json_encode(['success' => false]))));

        $this->assertFalse($verifier->validate('a-token'));
    }

    public function testEmptyTokenIsBlocked(): void
    {
        $verifier = $this->createVerifier(new MockHttpClient(new MockResponse((string) json_encode(['success' => true]))));

        $this->assertFalse($verifier->validate(''));
        $this->assertFalse($verifier->validate(null));
    }

    public function testTransportErrorFailsOpen(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Cloudflare not reachable');
        });

        // Netzwerk-/Timeout-Fehler -> bewusst durchlassen (fail-open).
        $this->assertTrue($this->createVerifier($client)->validate('a-token'));
    }

    public function testIsConfigured(): void
    {
        $this->assertTrue($this->createVerifier(new MockHttpClient())->isConfigured());

        $empty = $this->createVerifier(new MockHttpClient(), ['turnstileSiteKey' => '', 'turnstileSecretKey' => '']);
        $this->assertFalse($empty->isConfigured());
    }

    /**
     * @param array<string, string> $config
     */
    private function createVerifier(HttpClientInterface $client, array $config = ['turnstileSiteKey' => 'site-key', 'turnstileSecretKey' => 'secret-key']): TurnstileVerifier
    {
        $adapter = $this->mockAdapter(['get']);
        $adapter->method('get')->willReturnCallback(static fn (string $key) => $config[$key] ?? null);

        $framework = $this->mockContaoFramework([Config::class => $adapter]);

        return new TurnstileVerifier($client, new NullLogger(), new RequestStack(), $framework);
    }
}
