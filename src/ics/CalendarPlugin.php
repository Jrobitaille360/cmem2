<?php

namespace ICS;

use Core\PluginInterface;
use Core\PluginManager;
use ICS\Routing\RouteHandlers\CalendarRouteHandler;
use ICS\Routing\RouteHandlers\CalendarPublicRoute;
use ICS\Routing\RouteHandlers\CalDAVRouteHandler;

class CalendarPlugin implements PluginInterface
{
    private array $config;
    private ?CalendarRouteHandler $routeHandler = null;
    private ?CalendarPublicRoute $publicRouteHandler = null;
    private ?CalDAVRouteHandler $caldavRouteHandler = null;

    /**
     * Logging sûr qui vérifie si LogService est disponible
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        // Vérifier si LogService est disponible et chargé
        if (class_exists('\AuthGroups\Services\LogService')) {
            try {
                switch ($level) {
                    case 'info':
                        \AuthGroups\Services\LogService::info($message, $context);
                        break;
                    case 'warning':
                        \AuthGroups\Services\LogService::warning($message, $context);
                        break;
                    case 'error':
                        \AuthGroups\Services\LogService::error($message, $context);
                        break;
                }
            } catch (\Exception $e) {
                // Si LogService échoue, ne rien faire pour éviter les boucles
            }
        }
    }

    public function initialize(): void
    {
        // Charger la configuration
        $this->loadConfig();
        
        // Enregistrer les route handlers dans le PluginManager (sans les instancier immédiatement)
        PluginManager::getInstance()->registerPluginRoutes('ics', [
            'calendars' => function($authService) {
                if ($this->routeHandler === null) {
                    $this->routeHandler = new CalendarRouteHandler($authService);
                }
                return $this->routeHandler;
            },
            'caldav' => function($authService) {
                if ($this->caldavRouteHandler === null) {
                    $this->caldavRouteHandler = new CalDAVRouteHandler($authService);
                }
                return $this->caldavRouteHandler;
            },
            'calendar' => function($authService) {
                // Route publique pour les fichiers ICS
                if ($this->publicRouteHandler === null) {
                    $this->publicRouteHandler = new CalendarPublicRoute();
                }
                return $this->publicRouteHandler;
            }
        ]);
        
        // Exécuter les migrations si nécessaire
        $this->runMigrations();
        
        // Log seulement si les constantes de log sont définies
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin ICS Calendar initialisé", [
                'version' => $this->getInfo()['version']
            ]);
        }
    }

    public function getRouteHandlers(): array
    {
        // Retourner une factory function pour créer le handler à la demande
        return [
            'calendars' => function($authService) {
                if ($this->routeHandler === null) {
                    $this->routeHandler = new CalendarRouteHandler($authService);
                }
                return $this->routeHandler;
            },
            'caldav' => function($authService) {
                if ($this->caldavRouteHandler === null) {
                    $this->caldavRouteHandler = new CalDAVRouteHandler($authService);
                }
                return $this->caldavRouteHandler;
            },
            'calendar' => function($authService) {
                if ($this->publicRouteHandler === null) {
                    $this->publicRouteHandler = new CalendarPublicRoute();
                }
                return $this->publicRouteHandler;
            }
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
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin ICS Calendar désactivé");
        }
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
            if (defined('LOG_ENABLED') && LOG_ENABLED) {
                error_log("Migrations ICS exécutées");
            }
        }
    }
}