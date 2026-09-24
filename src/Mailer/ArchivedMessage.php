<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Mailer;

use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\RawMessage;

/**
 * Fertiger Mailtext aus der Spam-Ablage. Symfonys Transports::send() liest bei jeder Unterklasse von RawMessage
 * getHeaders() und wählt über „X-Transport" den Transport; über diesen Kopf kommt der beim Ablegen gespeicherte
 * Transportname zurück. Der Mailtext selbst enthält den Kopf nicht mehr.
 */
class ArchivedMessage extends RawMessage
{
    private readonly Headers $transportHeaders;

    public function __construct(string $mime, string $transport = '')
    {
        parent::__construct($mime);

        $this->transportHeaders = new Headers();

        if ('' !== $transport) {
            $this->transportHeaders->addTextHeader('X-Transport', $transport);
        }
    }

    public function getHeaders(): Headers
    {
        return $this->transportHeaders;
    }
}
