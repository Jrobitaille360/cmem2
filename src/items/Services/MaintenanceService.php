<?php

namespace Items\Services;

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
        return 'items';
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

        // items soft-deleted > 90 jours
        // ON DELETE CASCADE → item_user_access
        $this->purgeDeletedItems($db, $result);

        return $result;
    }

    // -------------------------------------------------------------------------

    private function purgeDeletedItems(\PDO $db, array &$result): void
    {
        try {
            $sql = "
                DELETE FROM items
                WHERE deleted_at IS NOT NULL
                  AND deleted_at < NOW() - INTERVAL 90 DAY
            ";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM items WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['items (soft-deleted >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['items (soft-deleted >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedItems: ' . $e->getMessage();
            LogService::error('Maintenance[items] purgeDeletedItems', ['exception' => $e->getMessage()]);
        }
    }
}
