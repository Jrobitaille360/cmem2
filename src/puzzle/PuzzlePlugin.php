<?php

namespace Puzzle;

use Core\AbstractPlugin;
use Core\PluginManager;
use Puzzle\Routing\PuzzleRouteHandler;

class PuzzlePlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('puzzle', $this->getRouteHandlers());

        $this->runMigrations(__DIR__ . '/migrations/');

    }

    public function getRouteHandlers(): array
    {
        return [
            'puzzle' => function ($authService) {
                return new PuzzleRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Puzzle',
            'version'     => '1.0.0',
            'description' => 'Plugin puzzle : carrousel, thèmes, sauvegarde en ligne, casse-têtes partagés',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin Puzzle désactivé");
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=1.3.0'
        ];
    }
}
