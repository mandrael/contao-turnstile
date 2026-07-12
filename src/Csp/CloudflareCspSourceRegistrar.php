<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Csp;

use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandler;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;

/**
 * Trägt den Cloudflare-Host automatisch in die Seiten-CSP ein (script-src, frame-src),
 * falls die Seite eine CSP nutzt. Nur auf Contao 5.x aktiv (native CSP); der Service wird
 * von der Extension nur dort registriert.
 */
class CloudflareCspSourceRegistrar
{
    private const HOST = 'https://challenges.cloudflare.com';

    public function __construct(private readonly ResponseContextAccessor $responseContextAccessor)
    {
    }

    public function register(): void
    {
        $responseContext = $this->responseContextAccessor->getResponseContext();

        if (null === $responseContext || !$responseContext->has(CspHandler::class)) {
            return;
        }

        // addSource() ergänzt nur, wenn die Direktive bereits eine Source-Liste hat
        // (also nur, wenn die Seite tatsächlich eine CSP gesetzt hat).
        $csp = $responseContext->get(CspHandler::class);
        $csp->addSource('script-src', self::HOST);
        $csp->addSource('frame-src', self::HOST);
    }

    /**
     * Zusätzliche Quellen für den ALTCHA-Fallback: alle same-origin ('self'). worker.js läuft als
     * same-origin Worker (kein blob:/wasm), der Solver ist ein same-origin Modul-Script, die Challenge
     * wird per fetch vom eigenen Endpoint geholt. Nur wenn die Seite überhaupt eine CSP nutzt.
     */
    public function registerAltcha(): void
    {
        $responseContext = $this->responseContextAccessor->getResponseContext();

        if (null === $responseContext || !$responseContext->has(CspHandler::class)) {
            return;
        }

        $csp = $responseContext->get(CspHandler::class);
        $csp->addSource('script-src', "'self'");   // mandrael-altcha.js
        $csp->addSource('worker-src', "'self'");   // worker.js
        $csp->addSource('connect-src', "'self'");  // fetch der challengeurl
    }
}
