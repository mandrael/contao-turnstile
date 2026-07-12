<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Controller;

use Mandrael\ContaoTurnstileBundle\Service\AltchaVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Liefert pro Abruf eine frische, kurzlebige ALTCHA-Challenge (Cache-Control: no-store). Ein
 * eingebettetes Pre-Signing wäre durch einen Page-Cache/CDN teilbar – der erste Submit markierte
 * die Challenge als Replay und wies alle weiteren gültigen Lösungen ab. Ein eigener Endpoint umgeht das.
 */
class AltchaChallengeController
{
    public function __construct(private readonly AltchaVerifier $altcha)
    {
    }

    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->altcha->createChallenge());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
