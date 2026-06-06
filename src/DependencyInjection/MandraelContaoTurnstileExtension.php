<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\DependencyInjection;

use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

// Klassische Extension + YamlFileLoader, damit die Service-Registrierung auch unter
// Symfony 5.4 (Contao 4.13) funktioniert. Alias: mandrael_contao_turnstile.
class MandraelContaoTurnstileExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../../config')
        );

        $loader->load('services.yaml');

        // CSP-Auto-Registrierung nur auf Contao 5.x: dort existiert die native CSP-API.
        // Auf 4.13 bliebe die Referenz auf ResponseContextAccessor sonst beim Kompilieren haengen.
        if (class_exists(CspHandler::class)) {
            $loader->load('services_csp.yaml');
        }
    }
}
