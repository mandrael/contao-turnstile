<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function getSiteKey(): string
    {
        return $this->configValue('turnstileSiteKey');
    }

    public function getSecretKey(): string
    {
        return $this->configValue('turnstileSecretKey');
    }

    private function configValue(string $key): string
    {
        // Zugriff ueber den Framework-Adapter statt statischem Config::get -> testbar/mockbar.
        $this->framework->initialize();

        return trim((string) $this->framework->getAdapter(Config::class)->get($key));
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getSiteKey() && '' !== $this->getSecretKey();
    }

    public function validate(?string $token): bool
    {
        if (null === $token || '' === $token) {
            return false;
        }

        $payload = [
            'secret' => $this->getSecretKey(),
            'response' => $token,
        ];

        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request && null !== $request->getClientIp()) {
            $payload['remoteip'] = $request->getClientIp();
        }

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => $payload,
                'timeout' => self::TIMEOUT,
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            // Transport-/Timeout-Fehler: Cloudflare nicht erreichbar. Bewusst fail-open,
            // damit ein CF-Ausfall nicht alle Formulare blockiert. Niemals Secret/$GLOBALS loggen.
            $this->logger->error(
                'Cloudflare Turnstile nicht erreichbar, Absenden wird durchgelassen: '.$e->getMessage(),
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
            );

            return true;
        }

        // Ungueltiges/gefaelschtes Token: hart blockieren (fail-closed).
        return true === ($data['success'] ?? false);
    }
}
