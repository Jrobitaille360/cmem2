<?php

namespace Pomo\Services;

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
        return 'pomo';
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

        // pomo_engagements est immuable (données analytiques).
        // On compte uniquement pour le rapport.
        $this->countEngagements($db, $result);

        return $result;
    }

    // -------------------------------------------------------------------------

    private function countEngagements(\PDO $db, array &$result): void
    {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM pomo_engagements");
            $result['rows_counted']['pomo_engagements (total)'] = (int) $stmt->fetchColumn();

            $stmt = $db->query("SELECT COUNT(*) FROM pomo_engagements WHERE type = 'waitlist'");
            $result['rows_counted']['pomo_engagements (waitlist)'] = (int) $stmt->fetchColumn();

            $stmt = $db->query("SELECT COUNT(*) FROM pomo_engagements WHERE type = 'survey'");
            $result['rows_counted']['pomo_engagements (survey)'] = (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $result['errors'][] = 'countEngagements: ' . $e->getMessage();
            LogService::error('Maintenance[pomo] countEngagements', ['exception' => $e->getMessage()]);
        }
    }
}
