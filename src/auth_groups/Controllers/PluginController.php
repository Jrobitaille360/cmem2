<?php

namespace AuthGroups\Controllers;

use Core\PluginManager;
use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;

class PluginController
{
    public function listPlugins(): void
    {
        try {
            $pluginManager = PluginManager::getInstance();
            $plugins = $pluginManager->getLoadedPlugins();
            
            Response::success('Plugins récupérés avec succès', [
                'plugins' => $plugins,
                'count' => count($plugins)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des plugins", [
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des plugins', 500);
        }
    }
}