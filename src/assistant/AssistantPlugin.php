<?php

namespace Assistant;

use Core\AbstractPlugin;
use Core\PluginManager;
use Assistant\Routing\AssistantRouteHandler;

/**
 * Module Assistant — tâche racine cmem #515, plan docs/v-2-23-0/PLAN_module-assistant.md.
 * Routes /assistant/* : consentement, chat (JSON ou SSE), historique, actions, usage.
 */
class AssistantPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('assistant', $this->getRouteHandlers());
    }

    public function getRouteHandlers(): array
    {
        return [
            'assistant' => function ($authService) {
                return new AssistantRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Assistant',
            'version'     => '1.0.0',
            'description' => 'Chat avec Claude, outils tâches/rappels, consentement Loi 25',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin Assistant désactivé");
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=2.22.0'
        ];
    }
}
