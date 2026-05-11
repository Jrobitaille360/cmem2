<?php

namespace Quiz;

use Core\AbstractPlugin;
use Core\PluginManager;
use Quiz\Routing\QuizRouteHandler;

class QuizPlugin extends AbstractPlugin
{
    public function initialize(): void
    {
        PluginManager::getInstance()->registerPluginRoutes('quiz', $this->getRouteHandlers());

        $this->runMigrations(__DIR__ . '/migrations/');

    }

    public function getRouteHandlers(): array
    {
        return [
            'quiz' => function ($authService) {
                return new QuizRouteHandler($authService);
            }
        ];
    }

    public function getInfo(): array
    {
        return [
            'name'        => 'Quiz',
            'version'     => '1.0.0',
            'description' => 'Module de quiz interactifs en temps réel (type Kahoot)',
            'status'      => 'active'
        ];
    }

    public function deactivate(): void
    {
        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $this->safeLog('info', "Plugin Quiz désactivé");
        }
    }

    public function getDependencies(): array
    {
        return [
            'cmem2_core' => '>=1.3.0'
        ];
    }
}
