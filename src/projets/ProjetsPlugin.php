<?php

namespace Projets;

use Core\AbstractPlugin;
use Core\PluginManager;
use Projets\Routing\ProjetsRouteHandler;

class ProjetsPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('projets', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'projets' => function ($authService) {
                return new ProjetsRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Projets',
            'version'     => '1.0.0',
            'description' => 'Gestion de projet — tâches, hiérarchie, dépendances, round-trip JSON, export .ics',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', 'Plugin Projets désactivé');
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.9.0'
        ];
    }
}
