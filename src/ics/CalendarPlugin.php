<?php

namespace ICS;

use Core\AbstractPlugin;
use Core\PluginManager;
use ICS\Routing\RouteHandlers\CalendarRouteHandler;
use ICS\Routing\RouteHandlers\CalendarPublicRoute;
use ICS\Routing\RouteHandlers\CalDAVRouteHandler;
use ICS\Routing\RouteHandlers\NotificationRouteHandler;

class CalendarPlugin extends AbstractPlugin
{
    private array $config;
    private ?CalendarRouteHandler     $routeHandler = null;
    private ?CalendarPublicRoute      $publicRouteHandler = null;
    private ?CalDAVRouteHandler       $caldavRouteHandler = null;
    private ?NotificationRouteHandler $notificationRouteHandler = null;

    public function initialize(): void
    {
        // Charger la configuration d'abord
        $this->loadConfig();
        
        // Définir le timezone par défaut pour PHP
        $defaultTimezone = $this->config['config']['default_timezone'] ?? 'America/Montreal';
        date_default_timezone_set($defaultTimezone);
        
        PluginManager::getInstance()->registerPluginRoutes('ics', $this->getRouteHandlers());

        $this->runMigrations(__DIR__ . '/../../docs/docs_ICS/migrations/');
        
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
            'freebusy' => function($authService) {
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
            },
            'notifications' => function($authService) {
                if ($this->notificationRouteHandler === null) {
                    $this->notificationRouteHandler = new NotificationRouteHandler($authService);
                }
                return $this->notificationRouteHandler;
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
}