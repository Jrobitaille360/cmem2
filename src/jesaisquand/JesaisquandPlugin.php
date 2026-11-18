<?php

namespace Jesaisquand;

use Core\AbstractPlugin;
use Core\PluginManager;
use Jesaisquand\Routing\JesaisquandRouteHandler;

class JesaisquandPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('jesaisquand', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'jesaisquand' => function ($authService) {
                return new JesaisquandRouteHandler($authService);
            },
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Jesaisquand',
            'version'     => '1.0.0',
            'description' => 'Je sais quand — paquets de cartes et multijoueur au tour par tour',
            'status'      => 'active',
        ];
    }

    public function getDependencies(): array
    {
        return ['cmem2_core' => '>=1.3.0'];
    }
}
