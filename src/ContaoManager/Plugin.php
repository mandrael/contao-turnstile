<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Mandrael\ContaoTurnstileBundle\Controller\AltchaChallengeController;
use Mandrael\ContaoTurnstileBundle\MandraelContaoTurnstileBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(MandraelContaoTurnstileBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    /**
     * Route programmatisch statt via routes.yaml: kein einzelner `type:`-Loader funktioniert auf
     * beiden Versionsseiten (Symfony 5.4/Contao 4.13 kennt nur 'annotation', Symfony 7/Contao 5.7 nur
     * 'attribute'). RouteCollection/Route sind ueber 5.4–7.x stabil.
     */
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $collection = new RouteCollection();
        $collection->add('mandrael_turnstile_altcha', new Route(
            '/_mandrael_turnstile/altcha',
            ['_controller' => AltchaChallengeController::class],
        ));

        return $collection;
    }
}
