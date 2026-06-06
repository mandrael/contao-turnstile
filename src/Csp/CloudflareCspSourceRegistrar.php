<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Csp;

use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandler;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;

/**
 * Traegt den Cloudflare-Host automatisch in die Seiten-CSP ein (script-src, frame-src),
 * falls die Seite eine CSP nutzt. Nur auf Contao 5.x aktiv (native CSP); der Service wird
 * von der Extension nur dort registriert.
 */
class CloudflareCspSourceRegistrar
{
    private const HOST = 'https://challenges.cloudflare.com';

    private ResponseContextAccessor $responseContextAccessor;

    public function __construct(ResponseContextAccessor $responseContextAccessor)
    {
        $this->responseContextAccessor = $responseContextAccessor;
    }

    public function register(): void
    {
        $responseContext = $this->responseContextAccessor->getResponseContext();

        if (null === $responseContext || !$responseContext->has(CspHandler::class)) {
            return;
        }

        // addSource() ergaenzt nur, wenn die Direktive bereits eine Source-Liste hat
        // (also nur, wenn die Seite tatsaechlich eine CSP gesetzt hat).
        $csp = $responseContext->get(CspHandler::class);
        $csp->addSource('script-src', self::HOST);
        $csp->addSource('frame-src', self::HOST);
    }
}
