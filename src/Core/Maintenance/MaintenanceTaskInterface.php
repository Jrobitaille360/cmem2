<?php

namespace Core\Maintenance;

interface MaintenanceTaskInterface
{
    public function getName(): string;

    /**
     * Exécute les tâches de nettoyage du module.
     *
     * Retourne un tableau structuré :
     * [
     *   'rows_deleted'  => ['table' => count, ...],
     *   'rows_updated'  => ['table' => count, ...],
     *   'rows_counted'  => ['table' => count, ...],
     *   'errors'        => ['message', ...],
     *   'warnings'      => ['message', ...],
     * ]
     */
    public function run(\PDO $db): array;
}
