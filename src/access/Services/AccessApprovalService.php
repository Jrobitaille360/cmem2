<?php

namespace Access\Services;

use Database;
use PDO;

/**
 * Accès sur approbation (plan #269, Phase 2).
 *
 *  - users.access_status : pending / approved / suspended (nouveaux comptes : pending).
 *  - access_invitations  : liste blanche de courriels posée par un admin. Un compte
 *    créé ou connecté avec un courriel invité (invitation non expirée, non acceptée)
 *    passe en approved et reçoit le budget IA de l'invitation. Les modules sont
 *    stockés et seront appliqués en Phase 3 (user_module_grants).
 */
class AccessApprovalService
{
    public const STATUSES = ['pending', 'approved', 'suspended'];
    public const DEFAULT_INVITATION_DAYS = 30;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function setStatus(int $userId, string $status): bool
    {
        $st = $this->pdo->prepare("UPDATE users SET access_status = ? WHERE id = ?");
        $st->execute([$status, $userId]);
        return true;
    }

    /**
     * Applique l'invitation valide correspondant au courriel du compte, s'il y en a une
     * et si le compte est en attente. Retourne true si le compte vient d'être approuvé.
     * Ne lève jamais : une erreur ici ne doit pas bloquer une connexion.
     */
    public function applyInvitationFor(int $userId): bool
    {
        try {
            $st = $this->pdo->prepare("SELECT email, access_status FROM users WHERE id = ?");
            $st->execute([$userId]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user || ($user['access_status'] ?? null) !== 'pending') {
                return false;
            }

            $inv = $this->findValidInvitation((string) $user['email']);
            if (!$inv) {
                return false;
            }

            $this->pdo->beginTransaction();
            $mark = $this->pdo->prepare(
                "UPDATE access_invitations SET accepted_at = NOW() WHERE id = ? AND accepted_at IS NULL"
            );
            $mark->execute([(int) $inv['id']]);
            if ($mark->rowCount() === 0) {
                $this->pdo->rollBack();
                return false; // acceptée en parallèle
            }
            if ($inv['ai_budget_usd'] !== null) {
                $this->pdo->prepare(
                    "UPDATE users SET access_status = 'approved', ai_budget_usd_monthly = ? WHERE id = ?"
                )->execute([$inv['ai_budget_usd'], $userId]);
            } else {
                $this->pdo->prepare("UPDATE users SET access_status = 'approved' WHERE id = ?")
                    ->execute([$userId]);
            }
            // Modules cochés (Phase 3) : ceux de l'invitation, sinon le défaut d'approbation.
            $grants  = new ModuleGrantService($this->pdo);
            $modules = $inv['modules'] !== null ? json_decode((string) $inv['modules'], true) : null;
            if (is_array($modules)) {
                $grants->setGrants($userId, $modules, $inv['invited_by'] !== null ? (int) $inv['invited_by'] : null);
            } else {
                $grants->grantDefaultsIfNone($userId, $inv['invited_by'] !== null ? (int) $inv['invited_by'] : null);
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            \AuthGroups\Services\LogService::error('Application d\'invitation échouée', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Courriel à l'admin pour une inscription en attente.
     * Destinataire : ACCESS_ADMIN_NOTIFY_EMAIL (.env) ; vide = aucun envoi.
     */
    public function notifyAdminPendingSignup(int $userId): void
    {
        try {
            $to = trim((string) ($_ENV['ACCESS_ADMIN_NOTIFY_EMAIL'] ?? ''));
            if ($to === '') {
                return;
            }
            $st = $this->pdo->prepare("SELECT name, email, access_status FROM users WHERE id = ?");
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || $u['access_status'] !== 'pending') {
                return;
            }
            $name  = htmlspecialchars((string) $u['name'], ENT_QUOTES);
            $email = htmlspecialchars((string) $u['email'], ENT_QUOTES);
            $body  = "<p>Nouvelle inscription en attente d'approbation :</p>"
                   . "<ul><li>Nom : {$name}</li><li>Courriel : {$email}</li><li>Id : {$userId}</li></ul>"
                   . "<p>Approuver : <code>PUT /users/{$userId}/access-status</code> "
                   . "<code>{\"status\":\"approved\"}</code></p>";
            (new \AuthGroups\Services\EmailService())->sendEmail($to, 'Inscription en attente d\'approbation', $body, true);
        } catch (\Throwable $e) {
            \AuthGroups\Services\LogService::error('Notification admin inscription échouée', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Courriel à l'usager quand son compte est approuvé. */
    public function notifyUserApproved(array $user): void
    {
        try {
            $name = htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES);
            $body = "<p>Bonjour {$name},</p>"
                  . "<p>Votre compte a été approuvé. Vous pouvez maintenant utiliser l'application.</p>";
            (new \AuthGroups\Services\EmailService())->sendEmail((string) $user['email'], 'Votre compte est approuvé', $body, true);
        } catch (\Throwable $e) {
            \AuthGroups\Services\LogService::error('Notification approbation échouée', [
                'user_id' => $user['id'] ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function findValidInvitation(string $email): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM access_invitations
              WHERE email = ? AND accepted_at IS NULL AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([self::normalizeEmail($email)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createInvitation(string $email, int $invitedBy, ?array $modules, ?float $budget, int $days): array
    {
        $st = $this->pdo->prepare(
            "INSERT INTO access_invitations (email, invited_by, modules, ai_budget_usd, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
        );
        $st->execute([
            self::normalizeEmail($email),
            $invitedBy,
            $modules === null ? null : json_encode(array_values($modules)),
            $budget,
            $days,
        ]);
        return $this->getInvitation((int) $this->pdo->lastInsertId());
    }

    public function getInvitation(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT *, (expires_at <= NOW()) AS is_expired FROM access_invitations WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::format($row) : null;
    }

    public function listInvitations(): array
    {
        $rows = $this->pdo->query("SELECT *, (expires_at <= NOW()) AS is_expired FROM access_invitations ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'format'], $rows);
    }

    public function deleteInvitation(int $id): bool
    {
        $st = $this->pdo->prepare("DELETE FROM access_invitations WHERE id = ?");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    private static function format(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'email'         => $row['email'],
            'invited_by'    => $row['invited_by'] !== null ? (int) $row['invited_by'] : null,
            'modules'       => $row['modules'] !== null ? json_decode($row['modules'], true) : null,
            'ai_budget_usd' => $row['ai_budget_usd'] !== null ? (float) $row['ai_budget_usd'] : null,
            'expires_at'    => $row['expires_at'],
            'accepted_at'   => $row['accepted_at'],
            'created_at'    => $row['created_at'],
            'status'        => $row['accepted_at'] !== null
                ? 'accepted'
                : ((int) ($row['is_expired'] ?? 0) === 1 ? 'expired' : 'pending'),
        ];
    }
}
