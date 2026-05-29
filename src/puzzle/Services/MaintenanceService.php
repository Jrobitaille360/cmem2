<?php

namespace Puzzle\Services;

use Core\Maintenance\MaintenanceTaskInterface;
use AuthGroups\Services\LogService;

class MaintenanceService implements MaintenanceTaskInterface
{
    // Taille des lots pour DELETE sur puzzle_shared_events (table à forte vélocité)
    private const EVENT_BATCH_SIZE = 10000;

    private bool $dryRun;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'puzzle';
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

        // 1. Événements de polling anciens (fenêtre glissante 7 jours)
        //    Traités en lots pour éviter un long lock de table.
        $this->purgeOldEvents($db, $result);

        // 2. Parties archivées depuis plus de 180 jours
        //    ON DELETE CASCADE → puzzle_shared_pieces + puzzle_shared_events restants
        $this->deleteArchivedShared($db, $result);

        // 3. Appareils dont le token est expiré ET inactifs depuis 90+ jours
        //    Seulement si aucune puzzle_shared active ne les référence.
        $this->deleteExpiredDevices($db, $result);

        // 4. Appareils inactifs depuis plus d'un an (quel que soit le token)
        $this->deleteInactiveDevices($db, $result);

        return $result;
    }

    // -------------------------------------------------------------------------

    private function purgeOldEvents(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM puzzle_shared_events
                    WHERE created_at < NOW() - INTERVAL 7 DAY
                ");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
                LogService::info('Maintenance[puzzle] dry-run: événements à purger', ['count' => $count]);
                $result['rows_deleted']['puzzle_shared_events (>7d)'] = $count;
                return;
            }

            $total = 0;
            do {
                $stmt = $db->prepare("
                    DELETE FROM puzzle_shared_events
                    WHERE created_at < NOW() - INTERVAL 7 DAY
                    LIMIT " . self::EVENT_BATCH_SIZE
                );
                $stmt->execute();
                $deleted = $stmt->rowCount();
                $total  += $deleted;
            } while ($deleted === self::EVENT_BATCH_SIZE);

            $result['rows_deleted']['puzzle_shared_events (>7d)'] = $total;

            if ($total > 500000) {
                $result['warnings'][] = "puzzle_shared_events : {$total} lignes supprimées — surveiller la taille de la table.";
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeOldEvents: ' . $e->getMessage();
            LogService::error('Maintenance[puzzle] purgeOldEvents', ['exception' => $e->getMessage()]);
        }
    }

    private function deleteArchivedShared(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM puzzle_shared
                    WHERE status = 'archived'
                      AND last_activity_at < NOW() - INTERVAL 180 DAY
                ");
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
                LogService::info('Maintenance[puzzle] dry-run: parties archivées à purger', ['count' => $count]);
                $result['rows_deleted']['puzzle_shared (archived >180d)'] = $count;
                return;
            }

            $stmt = $db->prepare("
                DELETE FROM puzzle_shared
                WHERE status = 'archived'
                  AND last_activity_at < NOW() - INTERVAL 180 DAY
            ");
            $stmt->execute();
            $result['rows_deleted']['puzzle_shared (archived >180d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'deleteArchivedShared: ' . $e->getMessage();
            LogService::error('Maintenance[puzzle] deleteArchivedShared', ['exception' => $e->getMessage()]);
        }
    }

    private function deleteExpiredDevices(\PDO $db, array &$result): void
    {
        foreach (['android_devices', 'web_devices'] as $table) {
            try {
                $sql = "DELETE FROM `{$table}`
                        WHERE token_expires_at < NOW()
                          AND last_seen_at < NOW() - INTERVAL 90 DAY";

                if ($this->dryRun) {
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM `{$table}`
                         WHERE token_expires_at < NOW()
                           AND last_seen_at < NOW() - INTERVAL 90 DAY"
                    );
                    $stmt->execute();
                    $count = (int) $stmt->fetchColumn();
                    LogService::info("Maintenance[puzzle] dry-run: {$table} expirés", ['count' => $count]);
                    $result['rows_deleted']["{$table} (expired+inactive >90d)"] = $count;
                    continue;
                }

                $stmt = $db->prepare($sql);
                $stmt->execute();
                $result['rows_deleted']["{$table} (expired+inactive >90d)"] = $stmt->rowCount();
            } catch (\Throwable $e) {
                $result['errors'][] = "deleteExpiredDevices[{$table}]: " . $e->getMessage();
                LogService::error("Maintenance[puzzle] deleteExpiredDevices[{$table}]", ['exception' => $e->getMessage()]);
            }
        }
    }

    private function deleteInactiveDevices(\PDO $db, array &$result): void
    {
        foreach (['android_devices', 'web_devices'] as $table) {
            try {
                $sql = "DELETE FROM `{$table}` WHERE last_seen_at < NOW() - INTERVAL 365 DAY";

                if ($this->dryRun) {
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM `{$table}` WHERE last_seen_at < NOW() - INTERVAL 365 DAY"
                    );
                    $stmt->execute();
                    $count = (int) $stmt->fetchColumn();
                    LogService::info("Maintenance[puzzle] dry-run: {$table} inactifs 1 an", ['count' => $count]);
                    $result['rows_deleted']["{$table} (inactive >365d)"] = $count;
                    continue;
                }

                $stmt = $db->prepare($sql);
                $stmt->execute();
                $result['rows_deleted']["{$table} (inactive >365d)"] = $stmt->rowCount();
            } catch (\Throwable $e) {
                $result['errors'][] = "deleteInactiveDevices[{$table}]: " . $e->getMessage();
                LogService::error("Maintenance[puzzle] deleteInactiveDevices[{$table}]", ['exception' => $e->getMessage()]);
            }
        }
    }
}
