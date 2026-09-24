<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Mandrael\ContaoTurnstileBundle\Service\AiSpamJudge;
use Mandrael\ContaoTurnstileBundle\Service\SpamClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiSpamJudgeTest extends TestCase
{
    public function testWithoutKeyReturnsNullWithoutHttpCall(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');

        $judge = $this->createJudge($client, key: '');

        self::assertNull($judge->judge(['Text'], ['a@x.at'], ['link']));
    }

    public function testUnknownProviderReturnsNullWithoutHttpCall(): void
    {
        // Unbekannter, nicht leerer Anbieter: kein Fallback auf Mistral, sonst ginge der Schlüssel an den
        // falschen Anbieter.
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');

        $judge = $this->createJudge($client, provider: 'openai', key: 'a-key');

        self::assertNull($judge->judge(['Text'], ['a@x.at'], ['link']));
    }

    public function testEmptyProviderDefaultsToMistral(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => '{"spam":false,"sicher":false}']]],
            ]));
        });

        $judge = $this->createJudge($client, provider: '', key: 'a-key');
        $verdict = $judge->judge(['Text'], ['a@x.at'], []);

        self::assertNotNull($verdict);
        self::assertSame('https://api.mistral.ai/v1/chat/completions', $captured);
    }

    public function testMistralResponseIsParsedAndUsesDefaultModelAndAuthHeader(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => '{"spam":true,"sicher":true,"grund":"Werbung"}']]],
            ]));
        });

        $judge = $this->createJudge($client, provider: 'mistral', key: 'a-key');
        $verdict = $judge->judge(['Text'], ['a@x.at'], ['link']);

        self::assertSame(['spam' => true, 'sure' => true], $verdict);
        self::assertNotNull($captured);
        self::assertSame('https://api.mistral.ai/v1/chat/completions', $captured['url']);
        self::assertContains('Authorization: Bearer a-key', $captured['options']['headers']);
        self::assertStringContainsString('mistral-small-2603', (string) $captured['options']['body']);
    }

    public function testAnthropicProviderUsesApiKeyHeaderAndClaudeModel(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'content' => [['text' => '{"spam":false,"sicher":false,"grund":"echte Anfrage"}']],
            ]));
        });

        $judge = $this->createJudge($client, provider: 'anthropic', key: 'a-key');
        $verdict = $judge->judge(['Text'], ['a@x.at'], ['link']);

        self::assertSame(['spam' => false, 'sure' => false], $verdict);
        self::assertSame('https://api.anthropic.com/v1/messages', $captured['url']);
        self::assertContains('x-api-key: a-key', $captured['options']['headers']);
        self::assertStringContainsString('claude-sonnet-5', (string) $captured['options']['body']);
        self::assertStringContainsString('"temperature":0', (string) $captured['options']['body']);
    }

    public function testEnvModelOverridesDefault(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => '{"spam":false,"sicher":false}']]],
            ]));
        });

        $judge = $this->createJudge($client, provider: 'mistral', key: 'a-key', model: 'mistral-medium-custom');
        $judge->judge(['Text'], ['a@x.at'], []);

        self::assertStringContainsString('mistral-medium-custom', (string) ($captured['body'] ?? ''));
    }

    public function testHttpErrorReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('server error', ['http_code' => 500]));

        $judge = $this->createJudge($client, key: 'a-key');

        self::assertNull($judge->judge(['Text'], ['a@x.at'], []));
    }

    public function testBrokenJsonReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'choices' => [['message' => ['content' => 'das ist kein JSON']]],
        ])));

        $judge = $this->createJudge($client, key: 'a-key');

        self::assertNull($judge->judge(['Text'], ['a@x.at'], []));
    }

    public function testResponseWithoutSpamFieldReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'choices' => [['message' => ['content' => '{"sicher":true}']]],
        ])));

        $judge = $this->createJudge($client, key: 'a-key');

        self::assertNull($judge->judge(['Text'], ['a@x.at'], []));
    }

    public function testClassifyNeverLeaksClientIpIntoAiPrompt(): void
    {
        // Über SpamClassifier::classify() mit gesetzter Client-IP: judge() nimmt gar kein IP-Argument an
        // (Signatur texts/emails/reasons), diese Probe belegt es zusätzlich am tatsächlich gesendeten Body.
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = (string) $options['body'];

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => '{"spam":false,"sicher":false}']]],
            ]));
        });

        $aiJudge = new AiSpamJudge($client, new ArrayAdapter(), new NullLogger(), 'mistral', 'a-key');
        // Eigener, vom Mistral-Client getrennter httpClient fuer die Tor-Abfrage in classify() (leere Liste,
        // kein Tor-Treffer): so bleibt der oben erfasste Body eindeutig der KI-Anfrage zuordenbar.
        $torClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(''));
        $classifier = new SpamClassifier(
            new ArrayAdapter(),
            $aiJudge,
            new NullLogger(),
            $torClient,
            static fn (string $host): bool => 'gmail.com' === $host
        );

        $classifier->classify(
            ['Besuchen Sie unser-shop.com für mehr Informationen'],
            ['kontakt@keine-mx-domain.example'],
            '203.0.113.7'
        );

        self::assertNotNull($captured);
        self::assertStringNotContainsString('203.0.113.7', $captured);
        self::assertStringContainsString('kontakt@keine-mx-domain.example', $captured);
    }

    public function testDailyBudgetBlocksAfter150Calls(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'choices' => [['message' => ['content' => '{"spam":false,"sicher":false}']]],
        ])));

        $cache = new ArrayAdapter();
        $judge = $this->createJudge($client, key: 'a-key', cache: $cache);

        for ($i = 0; $i < 150; ++$i) {
            self::assertNotNull($judge->judge(['Text'], ['a@x.at'], []));
        }

        self::assertNull($judge->judge(['Text'], ['a@x.at'], []));
    }

    private function createJudge(
        HttpClientInterface $client,
        string $provider = 'mistral',
        string $key = 'a-key',
        ?string $model = null,
        ?ArrayAdapter $cache = null,
    ): AiSpamJudge {
        return new AiSpamJudge($client, $cache ?? new ArrayAdapter(), new NullLogger(), $provider, $key, $model);
    }
}
