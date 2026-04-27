<?php

namespace Quiz\Services;

use Core\Maintenance\MaintenanceTaskInterface;
use AuthGroups\Services\LogService;

class MaintenanceService implements MaintenanceTaskInterface
{
    private bool $dryRun;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'quiz';
    }

    public function run(\PDO $db): array
    {
        $result = [
            'rows_deleted' => [],
            'rows_updated' => [],
            'rows_counted' => [],
            'errors'       => [],
            'warnings'     => [],
        ];

        // 1. Sessions terminées depuis plus de 90 jours
        //    ON DELETE CASCADE → quiz_participants → quiz_participant_answers
        $this->deleteOldSessions($db, $result);

        // 2. Sessions bloquées en statut non-ended depuis plus de 7 jours (orphelines)
        $this->deleteStuckSessions($db, $result);

        // 3. Quiz archivés depuis plus de 180 jours
        //    ON DELETE CASCADE → quiz_questions → quiz_choices + quiz_sessions restantes
        $this->deleteArchivedQuizzes($db, $result);

        return $result;
    }

    // -------------------------------------------------------------------------

    private function deleteOldSessions(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM quiz_sessions
                    WHERE ended_at IS NOT NULL
                      AND ended_at < NOW() - INTERVAL 90 DAY
                ");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
                LogService::info('Maintenance[quiz] dry-run: sessions terminées à purger', ['count' => $count]);
                $result['rows_deleted']['quiz_sessions (ended >90d)'] = $count;
                return;
            }

            $stmt = $db->prepare("
                DELETE FROM quiz_sessions
                WHERE ended_at IS NOT NULL
                  AND ended_at < NOW() - INTERVAL 90 DAY
            ");
            $stmt->execute();
            $result['rows_deleted']['quiz_sessions (ended >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'deleteOldSessions: ' . $e->getMessage();
            LogService::error('Maintenance[quiz] deleteOldSessions', ['exception' => $e->getMessage()]);
        }
    }

    private function deleteStuckSessions(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM quiz_sessions
                    WHERE status != 'ended'
                      AND created_at < NOW() - INTERVAL 7 DAY
                ");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
                LogService::info('Maintenance[quiz] dry-run: sessions orphelines à purger', ['count' => $count]);
                $result['rows_deleted']['quiz_sessions (stuck >7d)'] = $count;
                return;
            }

            $stmt = $db->prepare("
                DELETE FROM quiz_sessions
                WHERE status != 'ended'
                  AND created_at < NOW() - INTERVAL 7 DAY
            ");
            $stmt->execute();
            $result['rows_deleted']['quiz_sessions (stuck >7d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'deleteStuckSessions: ' . $e->getMessage();
            LogService::error('Maintenance[quiz] deleteStuckSessions', ['exception' => $e->getMessage()]);
        }
    }

    private function deleteArchivedQuizzes(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM quiz_quizzes
                    WHERE status = 'archived'
                      AND updated_at < NOW() - INTERVAL 180 DAY
                ");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
                LogService::info('Maintenance[quiz] dry-run: quiz archivés à purger', ['count' => $count]);
                $result['rows_deleted']['quiz_quizzes (archived >180d)'] = $count;
                return;
            }

            $stmt = $db->prepare("
                DELETE FROM quiz_quizzes
                WHERE status = 'archived'
                  AND updated_at < NOW() - INTERVAL 180 DAY
            ");
            $stmt->execute();
            $result['rows_deleted']['quiz_quizzes (archived >180d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'deleteArchivedQuizzes: ' . $e->getMessage();
            LogService::error('Maintenance[quiz] deleteArchivedQuizzes', ['exception' => $e->getMessage()]);
        }
    }
}
