<?php

namespace ICS;

use Core\PluginInterface;
use Core\PluginManager;
use ICS\Routing\RouteHandlers\CalendarRouteHandler;
use AuthGroups\Services\LogService;

class CalendarPlugin implements PluginInterface
{
    private array $config;
    private CalendarRouteHandler $routeHandler;

    public function initialize(): void
    {
        // Charger la configuration
        $this->loadConfig();
        
        // Initialiser les route handlers
        $authService = new \AuthGroups\Services\AuthService();
        $this->routeHandler = new CalendarRouteHandler($authService);
        
        // Enregistrer les routes dans le PluginManager
        PluginManager::getInstance()->registerPluginRoutes('ics', [
            'calendars' => $this->routeHandler
        ]);
        
        // Exécuter les migrations si nécessaire
        $this->runMigrations();
        
        LogService::info("Plugin ICS Calendar initialisé", [
            'version' => $this->getInfo()['version']
        ]);
    }

    public function getRouteHandlers(): array
    {
        return [
            'calendars' => $this->routeHandler
        ];
    }

    public function getInfo(): array
    {
        return [
            'name' => 'ICS Calendar',
            'version' => '1.0.0',
            'description' => 'Module de calendriers ICS',
            'status' => 'active'
        ];
    }

    public function deactivate(): void
    {
        LogService::info("Plugin ICS Calendar désactivé");
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=1.3.0'
        ];
    }

    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/plugin.json';
        if (file_exists($configFile)) {
            $this->config = json_decode(file_get_contents($configFile), true);
        }
    }

    private function runMigrations(): void
    {
        $migrationsPath = __DIR__ . '/docs_ICS/migrations/';
        if (is_dir($migrationsPath)) {
            // Logique de migration
            LogService::info("Migrations ICS exécutées");
        }
    }
}