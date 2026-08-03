<?php

namespace AuthGroups\Services;

use Core\Maintenance\MaintenanceTaskInterface;
use AuthGroups\Models\JwtBlacklist;

class MaintenanceService implements MaintenanceTaskInterface
{
    private bool $dryRun;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function getName(): string
    {
        return 'auth_groups';
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

        // Ordre : d'abord les données de volume, ensuite les tokens court-terme,
        // puis les sessions, enfin les statuts métier (abonnements, invitations).

        $this->purgeNotifications($db, $result);
        $this->purgeOldStats($db, $result);
        $this->expireGroupInvitations($db, $result);
        $this->expirePlanInvitations($db, $result);
        $this->purgeLoginAttempts($db, $result);
        $this->purgeOtpCodes($db, $result);
        $this->purgeJwtBlacklist($db, $result);
        $this->purgeDeviceTokens($db, $result);
        $this->purgeEmailVerifications($db, $result);
        $this->purgePasswordResets($db, $result);
        $this->cleanupSessions($db, $result);
        $this->purgeDeletedUsers($db, $result);
        return $result;
    }

    // -------------------------------------------------------------------------

    private function purgeNotifications(\PDO $db, array &$result): void
    {
        try {
            $sqlRead = "DELETE FROM notifications WHERE is_read = 1 AND read_at < NOW() - INTERVAL 30 DAY";
            $sqlOld  = "DELETE FROM notifications WHERE created_at < NOW() - INTERVAL 90 DAY";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 1 AND read_at < NOW() - INTERVAL 30 DAY");
                $stmt->execute();
                $result['rows_deleted']['notifications (read >30d)'] = (int) $stmt->fetchColumn();
                $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE created_at < NOW() - INTERVAL 90 DAY");
                $stmt->execute();
                $result['rows_deleted']['notifications (any >90d)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sqlRead);
            $stmt->execute();
            $result['rows_deleted']['notifications (read >30d)'] = $stmt->rowCount();

            $stmt = $db->prepare($sqlOld);
            $stmt->execute();
            $result['rows_deleted']['notifications (any >90d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeNotifications: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeNotifications', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeOldStats(\PDO $db, array &$result): void
    {
        try {
            $sqls = [
                'group_stats_snapshot (>30d)'   => "DELETE FROM group_stats_snapshot WHERE generated_at < NOW() - INTERVAL 30 DAY",
                'user_stats_snapshot (>30d)'    => "DELETE FROM user_stats_snapshot WHERE generated_at < NOW() - INTERVAL 30 DAY",
            ];

            if ($this->dryRun) {
                foreach ($sqls as $label => $sql) {
                    $count = $db->query("SELECT COUNT(*) FROM " . explode(' ', $sql)[2] . " WHERE " . explode('WHERE ', $sql)[1])->fetchColumn();
                    $result['rows_deleted'][$label] = (int) $count;
                }
                // platform_stats : compter les lignes qui seraient supprimées (tout sauf les 100 dernières)
                $stmt = $db->query("SELECT COUNT(*) FROM platform_stats");
                $total = (int) $stmt->fetchColumn();
                $result['rows_deleted']['platform_stats (excédent >100)'] = max(0, $total - 100);
                return;
            }

            foreach ($sqls as $label => $sql) {
                $stmt = $db->prepare($sql);
                $stmt->execute();
                $result['rows_deleted'][$label] = $stmt->rowCount();
            }

            // platform_stats : garder les 100 derniers enregistrements
            $stmt = $db->prepare("
                DELETE FROM platform_stats
                WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM platform_stats ORDER BY generated_at DESC LIMIT 100
                    ) AS keep
                )
            ");
            $stmt->execute();
            $result['rows_deleted']['platform_stats (excédent >100)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeOldStats: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeOldStats', ['exception' => $e->getMessage()]);
        }
    }

    private function expireGroupInvitations(\PDO $db, array &$result): void
    {
        try {
            $sql = "
                UPDATE group_invitations
                SET status = 'expired'
                WHERE status = 'pending'
                  AND expires_at IS NOT NULL
                  AND expires_at < NOW()
            ";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM group_invitations WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()");
                $stmt->execute();
                $result['rows_updated']['group_invitations (→ expired)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_updated']['group_invitations (→ expired)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'expireGroupInvitations: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] expireGroupInvitations', ['exception' => $e->getMessage()]);
        }
    }

    private function expirePlanInvitations(\PDO $db, array &$result): void
    {
        try {
            $sql = "
                UPDATE plan_invitations
                SET status = 'expired'
                WHERE status = 'pending'
                  AND expires_at < NOW()
            ";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM plan_invitations WHERE status = 'pending' AND expires_at < NOW()");
                $stmt->execute();
                $result['rows_updated']['plan_invitations (→ expired)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_updated']['plan_invitations (→ expired)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'expirePlanInvitations: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] expirePlanInvitations', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeLoginAttempts(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $window = defined('RATE_LIMIT_AUTH_WINDOW_MINUTES') ? (int) RATE_LIMIT_AUTH_WINDOW_MINUTES : 10;
                $stmt   = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $stmt->execute([$window]);
                $result['rows_deleted']['login_attempts (expired window)'] = (int) $stmt->fetchColumn();
                return;
            }

            $deleted = RateLimitService::deleteExpired();
            $result['rows_deleted']['login_attempts (expired window)'] = $deleted;
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeLoginAttempts: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeLoginAttempts', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeOtpCodes(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM otp_codes WHERE expires_at < NOW() OR used_at IS NOT NULL");
                $stmt->execute();
                $result['rows_deleted']['otp_codes (expired/used)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare("DELETE FROM otp_codes WHERE expires_at < NOW() OR used_at IS NOT NULL");
            $stmt->execute();
            $result['rows_deleted']['otp_codes (expired/used)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeOtpCodes: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeOtpCodes', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeJwtBlacklist(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM jwt_blacklist WHERE expires_at <= NOW()");
                $stmt->execute();
                $result['rows_deleted']['jwt_blacklist (expired)'] = (int) $stmt->fetchColumn();
                return;
            }

            $model   = new JwtBlacklist();
            $deleted = $model->deleteExpired();
            $result['rows_deleted']['jwt_blacklist (expired)'] = $deleted;
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeJwtBlacklist: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeJwtBlacklist', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeDeviceTokens(\PDO $db, array &$result): void
    {
        try {
            // Tokens expirés depuis plus de 30 jours OU révoqués
            $sql = "
                DELETE FROM device_tokens
                WHERE expires_at < NOW() - INTERVAL 30 DAY
                   OR revoked_at IS NOT NULL
            ";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM device_tokens WHERE expires_at < NOW() - INTERVAL 30 DAY OR revoked_at IS NOT NULL");
                $stmt->execute();
                $result['rows_deleted']['device_tokens (expired >30d / revoked)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['device_tokens (expired >30d / revoked)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeviceTokens: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeDeviceTokens', ['exception' => $e->getMessage()]);
        }
    }

    private function purgeEmailVerifications(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM email_verifications WHERE expires_at IS NOT NULL AND expires_at < NOW()";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM email_verifications WHERE expires_at IS NOT NULL AND expires_at < NOW()");
                $stmt->execute();
                $result['rows_deleted']['email_verifications (expired)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['email_verifications (expired)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeEmailVerifications: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeEmailVerifications', ['exception' => $e->getMessage()]);
        }
    }

    private function purgePasswordResets(\PDO $db, array &$result): void
    {
        try {
            $sql = "DELETE FROM password_resets WHERE expires_at IS NOT NULL AND expires_at < NOW()";

            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM password_resets WHERE expires_at IS NOT NULL AND expires_at < NOW()");
                $stmt->execute();
                $result['rows_deleted']['password_resets (expired)'] = (int) $stmt->fetchColumn();
                return;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $result['rows_deleted']['password_resets (expired)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgePasswordResets: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgePasswordResets', ['exception' => $e->getMessage()]);
        }
    }

    private function cleanupSessions(\PDO $db, array &$result): void
    {
        try {
            if ($this->dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE expires_at < NOW() AND is_active = 1");
                $stmt->execute();
                $result['rows_updated']['user_sessions (→ is_active=0)'] = (int) $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE is_active = 0 AND login_at < NOW() - INTERVAL 30 DAY");
                $stmt->execute();
                $result['rows_deleted']['user_sessions (inactive >30d)'] = (int) $stmt->fetchColumn();
                return;
            }

            // Marquer inactives les sessions expirées
            $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE expires_at < NOW() AND is_active = 1");
            $stmt->execute();
            $result['rows_updated']['user_sessions (→ is_active=0)'] = $stmt->rowCount();

            // Supprimer les vieilles sessions inactives
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE is_active = 0 AND login_at < NOW() - INTERVAL 30 DAY");
            $stmt->execute();
            $result['rows_deleted']['user_sessions (inactive >30d)'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            $result['errors'][] = 'cleanupSessions: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] cleanupSessions', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Purge Loi 25 — effacement physique des comptes soft-deleted au-delà du délai de grâce.
     *
     * Un simple DELETE FROM users ne suffit pas : la cascade laisserait les fichiers sur
     * disque et leurs lignes (files.uploaded_by n'a aucune FK), détruirait les groupes
     * partagés encore vivants et les parties de casse-tête du partenaire, et emporterait
     * les registres de facturation à conserver. AccountPurgeService traite chaque compte.
     *
     * Directive : 20260729_220000_cmem_web_vers_cmem2_API__suppression-compte-purge-30-jours
     */
    private function purgeDeletedUsers(\PDO $db, array &$result): void
    {
        try {
            $userIds = AccountPurgeService::findPurgeable($db);
            $label   = 'users (soft-deleted >' . AccountPurgeService::graceDays() . 'd, purge physique)';

            $result['rows_deleted'][$label] = 0;

            foreach ($userIds as $userId) {
                $report = AccountPurgeService::purgeUser($db, $userId, $this->dryRun);

                $result['rows_deleted'][$label] += (int) $report['user_deleted'];
                $result['rows_counted']["purge user {$userId}"] = [
                    'fichiers (base)'    => $report['files_rows_deleted'],
                    'fichiers (disque)'  => $report['files_disk_deleted'],
                    'groupes transférés' => $report['groups_transferred'],
                    'groupes supprimés'  => $report['groups_deleted'],
                    'casse-têtes'        => $report['puzzles_reassigned'],
                    'facturation'        => $report['billing_archived'],
                ];

                foreach ($report['warnings'] as $warning) {
                    $result['warnings'][] = "purge user {$userId}: {$warning}";
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'purgeDeletedUsers: ' . $e->getMessage();
            LogService::error('Maintenance[auth_groups] purgeDeletedUsers', ['exception' => $e->getMessage()]);
        }
    }

}
