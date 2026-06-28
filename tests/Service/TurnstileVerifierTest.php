<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Contao\Config;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoTurnstileBundle\Service\TurnstileVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
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

    public function testEmptyTokenLogsWarning(): void
    {
        // Fehlendes Token (kaputter Feldname/Template, JS aus) -> genau eine diagnostische Warnung,
        // damit ein flaechiger Ausfall im Prod-Log auffaellt.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->assertFalse($this->createVerifier(new MockHttpClient(), logger: $logger)->validate(''));
    }

    public function testRemoteIpCanBeDisabled(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['body'];

            return new MockResponse((string) json_encode(['success' => true]));
        });

        $requestStack = new RequestStack();
        $requestStack->push(new Request([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.5']));

        $config = ['turnstileSiteKey' => 'site-key', 'turnstileSecretKey' => 'secret-key', 'turnstileSendRemoteIp' => ''];
        $this->assertTrue($this->createVerifier($client, $config, requestStack: $requestStack)->validate('a-token'));

        $body = \is_string($captured) ? $captured : http_build_query((array) $captured);
        $this->assertStringNotContainsString('remoteip', $body);
    }

    public function testLogSoftPassLogsInfoWithCategory(): void
    {
        // soft-Modus: durchgelassene Submission wird auf info protokolliert, Kategorie ohne Token/PII.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('missing-token'));

        $this->createVerifier(new MockHttpClient(), logger: $logger)->logSoftPass('missing-token');
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

    public function testConfigErrorCodeLogsWarning(): void
    {
        // Falscher/abgelaufener Key -> diagnostische Warnung (sonst blockiert er still alle Formulare).
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $client = new MockHttpClient(new MockResponse((string) json_encode(['success' => false, 'error-codes' => ['invalid-input-secret']])));

        $this->assertFalse($this->createVerifier($client, logger: $logger)->validate('a-token'));
    }

    public function testBotErrorCodeStaysSilent(): void
    {
        // Gewoehnliche Bot-/Replay-Codes duerfen NICHT geloggt werden.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $client = new MockHttpClient(new MockResponse((string) json_encode(['success' => false, 'error-codes' => ['timeout-or-duplicate']])));

        $this->assertFalse($this->createVerifier($client, logger: $logger)->validate('a-token'));
    }

    public function testPayloadContainsSecretResponseAndRemoteIp(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['body'];

            return new MockResponse((string) json_encode(['success' => true]));
        });

        $requestStack = new RequestStack();
        $requestStack->push(new Request([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.5']));

        $this->assertTrue($this->createVerifier($client, requestStack: $requestStack)->validate('a-token'));

        $body = \is_string($captured) ? $captured : http_build_query((array) $captured);
        $this->assertStringContainsString('secret=secret-key', $body);
        $this->assertStringContainsString('response=a-token', $body);
        $this->assertStringContainsString('remoteip=203.0.113.5', $body);
    }

    public function testPayloadWithoutRequestOmitsRemoteIp(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['body'];

            return new MockResponse((string) json_encode(['success' => true]));
        });

        $this->assertTrue($this->createVerifier($client)->validate('a-token'));

        $body = \is_string($captured) ? $captured : http_build_query((array) $captured);
        $this->assertStringContainsString('secret=secret-key', $body);
        $this->assertStringNotContainsString('remoteip', $body);
    }

    /**
     * @param array<string, string> $config
     */
    private function createVerifier(HttpClientInterface $client, array $config = ['turnstileSiteKey' => 'site-key', 'turnstileSecretKey' => 'secret-key'], ?LoggerInterface $logger = null, ?RequestStack $requestStack = null): TurnstileVerifier
    {
        $adapter = $this->mockAdapter(['get']);
        $adapter->method('get')->willReturnCallback(static fn (string $key) => $config[$key] ?? null);

        $framework = $this->mockContaoFramework([Config::class => $adapter]);

        return new TurnstileVerifier($client, $logger ?? new NullLogger(), $requestStack ?? new RequestStack(), $framework);
    }
}
