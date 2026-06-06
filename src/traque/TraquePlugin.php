<?php

namespace Traque;

use Core\AbstractPlugin;
use Core\PluginManager;
use Traque\Routing\TraqueRouteHandler;

class TraquePlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('traque', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'traque' => function ($authService) {
                return new TraqueRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Traque',
            'version'     => '1.0.0',
            'description' => 'Plugin Traque : RPG AR mobile — monstres, combat AD&D, joueurs, classement',
            'status'      => 'active'
        ];
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.7.0'
        ];
    }
}
