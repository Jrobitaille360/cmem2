<?php

namespace AuthGroups\Services;

/**
 * Purge physique d'un compte après le délai de grâce (Loi 25).
 *
 * Directive : 20260729_220000_cmem_web_vers_cmem2_API__suppression-compte-purge-30-jours
 * Plan      : docs/PLAN_suppression-compte-purge-30-jours.md — Phase 3
 *
 * La quasi-totalité des tables usager ont une FK ON DELETE CASCADE vers users(id) :
 * le DELETE final fait l'essentiel du travail. Ce service traite ce que la cascade
 * ne peut pas faire :
 *
 *   - archiver les registres de facturation avant que la cascade ne les emporte ;
 *   - transférer la propriété des groupes partagés (sinon la cascade les détruirait
 *     pour leurs autres membres) ;
 *   - réattribuer les parties de casse-tête au partenaire survivant ;
 *   - retirer les fichiers du disque et leurs lignes (files.uploaded_by n'a aucune FK) ;
 *   - vider les tables sans FK (otp_codes, login_attempts, device_tokens, …).
 *
 * Idempotent : purger un usager déjà purgé ne produit aucune erreur.
 */
class AccountPurgeService
{
    /** Délai de grâce par défaut, en jours. */
    public const DEFAULT_GRACE_DAYS = 30;

    /**
     * Nombre de jours de grâce configuré.
     */
    public static function graceDays(): int
    {
        if (defined('ACCOUNT_PURGE_GRACE_DAYS')) {
            $days = (int) \ACCOUNT_PURGE_GRACE_DAYS;
            if ($days > 0) {
                return $days;
            }
        }
        return self::DEFAULT_GRACE_DAYS;
    }

    /**
     * Identifiants des comptes dont le délai de grâce est écoulé.
     *
     * @return int[]
     */
    public static function findPurgeable(\PDO $db): array
    {
        $stmt = $db->prepare(
            'SELECT id FROM users
              WHERE deleted_at IS NOT NULL
                AND deleted_at < NOW() - INTERVAL ? DAY'
        );
        $stmt->execute([self::graceDays()]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Purge physique d'un compte.
     *
     * @return array Décompte par domaine, journalisable tel quel.
     */
    public static function purgeUser(\PDO $db, int $userId, bool $dryRun = false): array
    {
        $report = [
            'user_id'             => $userId,
            'dry_run'             => $dryRun,
            'billing_archived'    => 0,
            'groups_transferred'  => 0,
            'groups_deleted'      => 0,
            'puzzles_reassigned'  => 0,
            'files_rows_deleted'  => 0,
            'files_disk_deleted'  => 0,
            'files_disk_missing'  => 0,
            'tokens_deleted'      => 0,
            'otp_deleted'         => 0,
            'login_attempts'      => 0,
            'user_deleted'        => 0,
            'warnings'            => [],
        ];

        $stmt = $db->prepare('SELECT id, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            // Déjà purgé : succès silencieux (idempotence).
            $report['warnings'][] = 'Compte introuvable — déjà purgé';
            return $report;
        }

        $email = (string) $user['email'];

        // Les fichiers disque sont retirés hors transaction : un unlink() n'est pas
        // annulable par un ROLLBACK, et un fichier verrouillé ne doit pas rendre le
        // compte impurgeable indéfiniment.
        $files = self::listFiles($db, $userId);

        try {
            $db->beginTransaction();

            self::archiveBilling($db, $userId, $dryRun, $report);
            self::handleGroups($db, $userId, $dryRun, $report);
            self::reassignPuzzles($db, $userId, $dryRun, $report);
            self::deleteFileRows($db, $userId, $dryRun, $report, count($files));
            self::deleteOrphanTables($db, $userId, $email, $dryRun, $report);

            if (!$dryRun) {
                $del = $db->prepare('DELETE FROM users WHERE id = ?');
                $del->execute([$userId]);
                $report['user_deleted'] = $del->rowCount();
            } else {
                $report['user_deleted'] = 1;
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $report['warnings'][] = 'Purge interrompue : ' . $e->getMessage();
            LogService::error('AccountPurgeService: échec de purge', [
                'user_id'   => $userId,
                'exception' => $e->getMessage(),
            ]);
            return $report;
        }

        // Disque : seulement après le succès de la transaction, sinon on
        // supprimerait des fichiers dont les lignes existent encore.
        if (!$dryRun) {
            foreach ($files as $file) {
                $path = self::absolutePath((string) $file['file_path']);
                if ($path === null || !is_file($path)) {
                    $report['files_disk_missing']++;
                    continue;
                }
                if (@unlink($path)) {
                    $report['files_disk_deleted']++;
                } else {
                    $report['files_disk_missing']++;
                    $report['warnings'][] = 'Fichier non supprimé du disque : ' . $file['file_path'];
                }
            }
        } else {
            $report['files_disk_deleted'] = count($files);
        }

        LogService::info('AccountPurgeService: compte purgé', $report);

        return $report;
    }

    // -------------------------------------------------------------------------

    /** @return array<int, array{id: int, file_path: string}> */
    private static function listFiles(\PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT id, file_path FROM files WHERE uploaded_by = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Chemin absolu d'un fichier téléversé, ou null si le chemin sort de la racine
     * du projet (protection contre une valeur aberrante en base).
     */
    private static function absolutePath(string $relativePath): ?string
    {
        $root = dirname(__DIR__, 3);
        $full = $root . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $real = realpath($full);

        if ($real === false) {
            return null;
        }
        if (strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $root)) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * Registres de facturation conservés anonymisés (obligation fiscale).
     * La ligne d'origine part ensuite avec la cascade sur users.
     */
    private static function archiveBilling(\PDO $db, int $userId, bool $dryRun, array &$report): void
    {
        $stmt = $db->prepare('SELECT * FROM stripe_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($dryRun) {
            $report['billing_archived'] = count($rows);
            return;
        }

        $insert = $db->prepare(
            'INSERT INTO billing_archive
                (app_id, stripe_customer_id, stripe_subscription_id, plan, status,
                 is_trial, trial_end, expires_at, cancel_at_period_end, subscribed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($rows as $row) {
            $insert->execute([
                $row['app_id'],
                $row['stripe_customer_id'],
                $row['stripe_subscription_id'],
                $row['plan'],
                $row['status'],
                $row['is_trial'],
                $row['trial_end'],
                $row['expires_at'],
                $row['cancel_at_period_end'],
                $row['created_at'],
            ]);
            $report['billing_archived']++;
        }
    }

    /**
     * Groupes possédés : transférés s'il reste des membres, supprimés sinon.
     * Sans ce traitement, la FK groups.owner_id ON DELETE CASCADE détruirait
     * un groupe partagé encore vivant.
     */
    private static function handleGroups(\PDO $db, int $userId, bool $dryRun, array &$report): void
    {
        $stmt = $db->prepare('SELECT id FROM `groups` WHERE owner_id = ?');
        $stmt->execute([$userId]);
        $groupIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        foreach ($groupIds as $groupId) {
            $heir = $db->prepare(
                'SELECT user_id FROM group_members
                  WHERE group_id = ? AND user_id IS NOT NULL AND user_id <> ?
                    AND deleted_at IS NULL
                  ORDER BY joined_at ASC, id ASC
                  LIMIT 1'
            );
            $heir->execute([$groupId, $userId]);
            $heirId = $heir->fetchColumn();

            if ($heirId) {
                if (!$dryRun) {
                    $db->prepare('UPDATE `groups` SET owner_id = ? WHERE id = ?')
                       ->execute([(int) $heirId, $groupId]);
                    $db->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')
                       ->execute([$groupId, $userId]);
                }
                $report['groups_transferred']++;
            } else {
                if (!$dryRun) {
                    $db->prepare('DELETE FROM `groups` WHERE id = ?')->execute([$groupId]);
                }
                $report['groups_deleted']++;
            }
        }
    }

    /**
     * Parties de casse-tête partagées : réattribuées au partenaire survivant.
     * creator_id et partner_id sont NOT NULL avec cascade — sans réattribution,
     * la partie disparaîtrait aussi pour le partenaire.
     */
    private static function reassignPuzzles(\PDO $db, int $userId, bool $dryRun, array &$report): void
    {
        try {
            $stmt = $db->prepare(
                'SELECT id, creator_id, partner_id FROM puzzle_shared
                  WHERE creator_id = ? OR partner_id = ?'
            );
            $stmt->execute([$userId, $userId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Module puzzle absent de cette installation : rien à faire.
            return;
        }

        foreach ($rows as $row) {
            $survivor = (int) $row['creator_id'] === $userId
                ? (int) $row['partner_id']
                : (int) $row['creator_id'];

            if ($survivor === $userId || $survivor <= 0) {
                // Les deux côtés sont le même compte : plus personne pour la partie.
                if (!$dryRun) {
                    $db->prepare('DELETE FROM puzzle_shared WHERE id = ?')->execute([(int) $row['id']]);
                }
                continue;
            }

            if (!$dryRun) {
                $db->prepare('UPDATE puzzle_shared SET creator_id = ?, partner_id = ? WHERE id = ?')
                   ->execute([$survivor, $survivor, (int) $row['id']]);
            }
            $report['puzzles_reassigned']++;
        }
    }

    /**
     * files.uploaded_by n'a aucune FK : sans suppression explicite, les lignes
     * survivraient à la purge du compte.
     */
    private static function deleteFileRows(\PDO $db, int $userId, bool $dryRun, array &$report, int $known): void
    {
        if ($dryRun) {
            $report['files_rows_deleted'] = $known;
            return;
        }

        $stmt = $db->prepare('DELETE FROM files WHERE uploaded_by = ?');
        $stmt->execute([$userId]);
        $report['files_rows_deleted'] = $stmt->rowCount();
    }

    /**
     * Tables sans FK vers users : rien ne les nettoie au moment du DELETE.
     */
    private static function deleteOrphanTables(
        \PDO $db,
        int $userId,
        string $email,
        bool $dryRun,
        array &$report
    ): void {
        $byUser = [
            'device_tokens' => 'user_id',
            'jwt_blacklist' => 'user_id',
        ];
        $byEmail = [
            'otp_codes'      => 'email',
            'login_attempts' => 'email',
        ];

        foreach ($byUser as $table => $column) {
            $count = self::deleteWhere($db, $table, $column, $userId, $dryRun);
            $report['tokens_deleted'] += $count;
        }

        foreach ($byEmail as $table => $column) {
            $count = self::deleteWhere($db, $table, $column, $email, $dryRun);
            if ($table === 'otp_codes') {
                $report['otp_deleted'] += $count;
            } else {
                $report['login_attempts'] += $count;
            }
        }

        // pomo_engagements : courriel facultatif, aucun lien de clé étrangère.
        $report['tokens_deleted'] += self::deleteWhere($db, 'pomo_engagements', 'email', $email, $dryRun);
    }

    /**
     * @param int|string $value
     */
    private static function deleteWhere(\PDO $db, string $table, string $column, $value, bool $dryRun): int
    {
        try {
            if ($dryRun) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
                $stmt->execute([$value]);
                return (int) $stmt->fetchColumn();
            }

            $stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ?");
            $stmt->execute([$value]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            // Table absente de cette installation : sans effet.
            return 0;
        }
    }
}
