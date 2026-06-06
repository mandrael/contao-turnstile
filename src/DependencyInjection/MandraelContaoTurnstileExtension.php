<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\DependencyInjection;

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
    }
}
