<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\ContaoManager;

use Mandrael\ContaoTurnstileBundle\ContaoManager\Plugin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class PluginTest extends TestCase
{
    public function testAltchaRouteAllowsOnlyGet(): void
    {
        $plugin = new Plugin();

        $collection = $plugin->getRouteCollection(
            $this->createMock(LoaderResolverInterface::class),
            $this->createMock(KernelInterface::class),
        );

        $route = $collection?->get('mandrael_turnstile_altcha');

        $this->assertNotNull($route);
        $this->assertSame(['GET'], $route->getMethods());
    }
}
