<?php

namespace Items;

use Core\AbstractPlugin;
use Core\PluginManager;
use Items\Routing\ItemRouteHandler;

class ItemsPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('items', $this->getRouteHandlers());

        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', 'Plugin Items initialisé', [
                'version' => $this->getInfo()['version']
            ]);
        }
    }

    public function getRouteHandlers(): array
    {
        return [
            'items' => function ($authService) {
                return new ItemRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Items',
            'version'     => '1.0.0',
            'description' => 'Gestionnaire générique d\'items avec contrôle d\'accès (private/public/share)',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', 'Plugin Items désactivé');
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.2.4'
        ];
    }
}
