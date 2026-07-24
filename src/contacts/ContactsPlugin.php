<?php

namespace Contacts;

use Core\AbstractPlugin;
use Core\PluginManager;
use Contacts\Routing\ContactsRouteHandler;

class ContactsPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('contacts', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'contacts' => function ($authService) {
                return new ContactsRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Contacts',
            'version'     => '1.0.0',
            'description' => 'Pilier Contacts — CRUD, vCard 4.0 import/export, cap max_contacts',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', 'Plugin Contacts désactivé');
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.9.0'
        ];
    }
}
