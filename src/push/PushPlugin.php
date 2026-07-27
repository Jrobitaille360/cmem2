<?php

namespace Push;

use Core\AbstractPlugin;
use Core\PluginManager;
use Push\Routing\PushRouteHandler;

class PushPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('push', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'push' => function ($authService) {
                return new PushRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Push',
            'version'     => '1.0.0',
            'description' => 'Web Push (VAPID) — subscriptions, préférences, cron d\'envoi',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', 'Plugin Push désactivé');
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.10.0'
        ];
    }
}
