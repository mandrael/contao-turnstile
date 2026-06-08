<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Csp;

use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandler;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Mandrael\ContaoTurnstileBundle\Csp\CloudflareCspSourceRegistrar;
use Nelmio\SecurityBundle\ContentSecurityPolicy\DirectiveSet;
use Nelmio\SecurityBundle\ContentSecurityPolicy\PolicyManager;
use PHPUnit\Framework\TestCase;

class CloudflareCspSourceRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        // CspHandler existiert erst ab Contao 5.x; auf 4.13 wird der Registrar gar nicht registriert.
        if (!class_exists(CspHandler::class)) {
            $this->markTestSkipped('CSP-Handler nur auf Contao 5.x vorhanden.');
        }
    }

    public function testNoResponseContextIsNoop(): void
    {
        $accessor = $this->createMock(ResponseContextAccessor::class);
        $accessor->method('getResponseContext')->willReturn(null);

        $this->expectNotToPerformAssertions();
        (new CloudflareCspSourceRegistrar($accessor))->register();
    }

    public function testResponseContextWithoutCspHandlerIsNoop(): void
    {
        $accessor = $this->createMock(ResponseContextAccessor::class);
        $accessor->method('getResponseContext')->willReturn(new ResponseContext());

        $this->expectNotToPerformAssertions();
        (new CloudflareCspSourceRegistrar($accessor))->register();
    }

    public function testAddsCloudflareHostToScriptAndFrameSrc(): void
    {
        $directives = new DirectiveSet(new PolicyManager());
        // addSource() ergaenzt nur bestehende Direktiven (autoIgnore), daher vorab setzen.
        $directives->setDirectives(['script-src' => "'self'", 'frame-src' => "'self'"]);
        $csp = new CspHandler($directives);

        $context = new ResponseContext();
        $context->add($csp);

        $accessor = $this->createMock(ResponseContextAccessor::class);
        $accessor->method('getResponseContext')->willReturn($context);

        (new CloudflareCspSourceRegistrar($accessor))->register();

        $this->assertStringContainsString('https://challenges.cloudflare.com', (string) $csp->getDirective('script-src'));
        $this->assertStringContainsString('https://challenges.cloudflare.com', (string) $csp->getDirective('frame-src'));
    }
}
