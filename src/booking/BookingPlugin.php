<?php

namespace Booking;

use Core\AbstractPlugin;
use Core\PluginManager;
use Booking\Routing\BookingRouteHandler;

class BookingPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('booking', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'booking' => function ($authService) {
                return new BookingRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Booking',
            'version'     => '1.0.0',
            'description' => 'Réservation publique — page de créneaux hôte, annulation par lien',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin Booking désactivé");
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.15.0'
        ];
    }
}
