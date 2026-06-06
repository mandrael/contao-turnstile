<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Mandrael\ContaoTurnstileBundle\MandraelContaoTurnstileBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(MandraelContaoTurnstileBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }
}
