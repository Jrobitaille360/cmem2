<?php

namespace Pomo;

use Core\AbstractPlugin;
use Core\PluginManager;
use Pomo\Routing\PomoRouteHandler;

class PomoPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('pomo', $this->getRouteHandlers());

        $this->runMigrations(__DIR__ . '/../../docs/docs-api/pomo/migrations/');

    }

    public function getRouteHandlers(): array
    {
        return [
            'pomo' => function ($authService) {
                return new PomoRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Pomo',
            'version'     => '1.0.0',
            'description' => 'Plugin Pomodoro — engagement MVP, support, sync cloud Premium',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin Pomo désactivé");
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.2.2'
        ];
    }
}
