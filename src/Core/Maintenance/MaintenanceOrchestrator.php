<?php

namespace Core\Maintenance;

use AuthGroups\Services\LogService;

class MaintenanceOrchestrator
{
    /** @var MaintenanceTaskInterface[] */
    private array $tasks = [];

    public function register(MaintenanceTaskInterface $task): void
    {
        $this->tasks[] = $task;
    }

    /**
     * Exécute toutes les tâches dans l'ordre d'enregistrement.
     * Chaque tâche est isolée dans un try/catch : une erreur n'arrête pas les suivantes.
     *
     * @return array[] Résultats enrichis avec 'name' et 'duration'
     */
    public function run(\PDO $db): array
    {
        $results = [];

        foreach ($this->tasks as $task) {
            $name = $task->getName();
            LogService::info("Maintenance: début tâche [{$name}]");
            $t0 = microtime(true);

            try {
                $result = $task->run($db);
            } catch (\Throwable $e) {
                $result = [
                    'rows_deleted' => [],
                    'rows_updated' => [],
                    'rows_counted' => [],
                    'warnings'     => [],
                    'errors'       => [$e->getMessage()],
                ];
                LogService::error("Maintenance: exception [{$name}]", ['exception' => $e->getMessage()]);
            }

            $duration = round(microtime(true) - $t0, 3);
            $result['name']     = $name;
            $result['duration'] = $duration;

            $this->logTaskResult($name, $duration, $result);

            $results[] = $result;
        }

        return $results;
    }

    // -------------------------------------------------------------------------

    private function logTaskResult(string $name, float $duration, array $r): void
    {
        $deleted  = array_sum($r['rows_deleted'] ?? []);
        $updated  = array_sum($r['rows_updated'] ?? []);
        $counted  = array_sum($r['rows_counted'] ?? []);
        $errCount = count($r['errors'] ?? []);

        $level = $errCount > 0 ? 'error' : 'info';

        LogService::$level("Maintenance: fin tâche [{$name}]", [
            'duration_s'    => $duration,
            'rows_deleted'  => $deleted,
            'rows_updated'  => $updated,
            'rows_counted'  => $counted,
            'errors'        => $errCount,
        ]);
    }
}
