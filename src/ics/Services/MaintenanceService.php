<?php

namespace ICS\Services;

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
        return 'ics';
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

        // Ordre FK-safe :
        // 1. caldav_locks expirés (FK CASCADE depuis calendars/events — préventif)
        // 2. caldav_sync_log anciens (FK CASCADE depuis calendars/events)
        // 3. calendar_events soft-deleted → cascade event_occurrences, caldav_locks
        // 4. calendar_todos soft-deleted (pas de FK sur calendar_id)
        // 5. calendar_journals soft-deleted (pas de FK sur calendar_id)
        // 6. calendar_shares soft-deleted → cascade via FK
        // 7. calendars soft-deleted → cascade tout ce qui reste
        // 8. Régénération incrémentale des occurrences récurrentes
        // (email_notification_queue retirée en #497.)

        $this->purgeExpiredLocks($db, $result);
        $this->purgeOldSyncLog($db, $result);
        $this->purgeDeletedEvents($db, $result);
        $this->purgeDeletedTodos($db, $result);
        $this->purgeDeletedJournals($db, $result);
        $this->purgeDeletedShares($db, $result);
        $this->purgeDeletedCalendars($db, $result);
        $this->regenerateOccurrences($result);

        return $result;
    }

    // -------------------------------------------------------------------------

    private function purgeExpiredLocks(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM caldav_locks WHERE expires_at < NOW()";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM caldav_locks WHERE expires_at < NOW()");
                $stmt->execute();
                $result['rows_deleted']['caldav_locks (expired)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['caldav_locks (expired)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeExpiredLocks: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeExpiredLocks', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeOldSyncLog(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM caldav_sync_log WHERE changed_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM caldav_sync_log WHERE changed_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['caldav_sync_log (>90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['caldav_sync_log (>90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeOldSyncLog: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeOldSyncLog', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeDeletedEvents(\PDO $db, array &$result): void
    {
        try {
            // CASCADE → event_occurrences, caldav_locks (restants)
            $sql = "DELETE FROM calendar_events WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['calendar_events (soft-deleted >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['calendar_events (soft-deleted >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedEvents: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeDeletedEvents', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeDeletedTodos(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM calendar_todos WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_todos WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['calendar_todos (soft-deleted >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['calendar_todos (soft-deleted >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedTodos: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeDeletedTodos', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeDeletedJournals(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM calendar_journals WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_journals WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['calendar_journals (soft-deleted >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['calendar_journals (soft-deleted >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedJournals: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeDeletedJournals', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeDeletedShares(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM calendar_shares WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_shares WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['calendar_shares (soft-deleted >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['calendar_shares (soft-deleted >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedShares: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeDeletedShares', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeDeletedCalendars(\PDO $db, array &$result): void
    {
        try {
            // CASCADE → calendar_events restants, calendar_shares, caldav_locks, caldav_sync_log, event_occurrences
            $sql = "DELETE FROM calendars WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendars WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['calendars (soft-deleted >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['calendars (soft-deleted >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedCalendars: ' . $e->getMessage();
            LogService::error('Maintenance[ics] purgeDeletedCalendars', ['exception' => $e->getMessage()]);
        }
    }

    private function regenerateOccurrences(array &$result): void
    {
        try {
            // Chargement différé de la config ICS (requise par OccurrenceMaintenanceService)
            $configFile = __DIR__ . '/../config/ics_config.php';
            if (file_exists($configFile)) {
                require_once $configFile;
            }

            if ($this->dryRun) {
                $stats = OccurrenceMaintenanceService::getStatistics();
                $result['rows_counted']['event_occurrences (total)'] = $stats['total_occurrences'] ?? 0;
                $result['rows_counted']['event_occurrences (recurring events)'] = $stats['recurring_events'] ?? 0;
                return;
            }

            $stats = OccurrenceMaintenanceService::performMaintenance(false);

            $result['rows_counted']['occurrences régénérées'] = $stats['regenerated_events'] ?? 0;
            $result['rows_counted']['occurrences ignorées (non modifiées)'] = $stats['skipped_events'] ?? 0;

            if (!empty($stats['errors'])) {
                foreach ($stats['errors'] as $err) {
                    $result['warnings'][] = 'OccurrenceMaintenance: ' . $err;
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'regenerateOccurrences: ' . $e->getMessage();
            LogService::error('Maintenance[ics] regenerateOccurrences', ['exception' => $e->getMessage()]);
        }
    }
}
