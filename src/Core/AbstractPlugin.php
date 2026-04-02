<?php

namespace Core;

abstract class AbstractPlugin implements PluginInterface
{
    protected function safeLog(string $level, string $message, array $context = []): void
    {
        if (!class_exists('\AuthGroups\Services\LogService')) return;
        try {
            match($level) {
                'info'    => \AuthGroups\Services\LogService::info($message, $context),
                'warning' => \AuthGroups\Services\LogService::warning($message, $context),
                'error'   => \AuthGroups\Services\LogService::error($message, $context),
                default   => null,
            };
        } catch (\Exception $e) {}
    }

    public function deactivate(): void {}
    public function getDependencies(): array { return []; }

    protected function runMigrations(string $migrationsPath): void
    {
        // Hook — override dans les plugins qui ont des tables
    }
}
