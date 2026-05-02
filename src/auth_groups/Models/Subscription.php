<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Modèle Subscription — abonnements Premium par utilisateur et par application.
 *
 * Le statut Premium est stocké par couple (user_id, app_id).
 * La table users n'est pas modifiée.
 */
class Subscription extends BaseModel
{
    protected $table = 'subscriptions';

    public $id;
    public $user_id;
    public $device_token;
    public $app_id;
    public $provider;
    public $product_id;
    public $purchase_token;
    public $stripe_sub_id;
    public $stripe_customer;
    public $status;
    public $plan;
    public $is_premium;
    public $show_ads;
    public $is_trial;
    public $trial_end;
    public $started_at;
    public $expires_at;
    public $cancelled_at;
    public $created_at;
    public $updated_at;

    public function create() { throw new \LogicException('Utiliser upsert()'); }
    public function update() { throw new \LogicException('Utiliser les méthodes spécifiques'); }

    // -----------------------------------------------------------------------
    // Lecture
    // -----------------------------------------------------------------------

    /**
     * Retourne l'abonnement actif pour un couple (user_id, app_id), ou null.
     */
    public function findActive(int $userId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table}
            WHERE user_id = ? AND app_id = ? AND status = 'active' AND expires_at > NOW()
            ORDER BY expires_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retourne tous les abonnements actifs d'un utilisateur (toutes apps).
     * @return array<string, array>  indexé par app_id
     */
    public function findAllActive(int $userId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table}
            WHERE user_id = ? AND status = 'active' AND expires_at > NOW()
            ORDER BY app_id, expires_at DESC
        ");
        $stmt->execute([$userId]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            // Un seul enregistrement actif par app (UNIQUE KEY + ORDER BY)
            if (!isset($result[$row['app_id']])) {
                $result[$row['app_id']] = $row;
            }
        }
        return $result;
    }

    /**
     * Retourne tous les abonnements actifs dont expires_at < NOW() (pour le CRON).
     */
    public function findExpired(): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table}
            WHERE status = 'active' AND expires_at <= NOW()
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------------
    // Écriture
    // -----------------------------------------------------------------------

    /**
     * Crée ou met à jour un abonnement.
     * Utilise INSERT … ON DUPLICATE KEY UPDATE sur uq_user_app (user_id, app_id).
     */
    public function upsert(array $data): void
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO {$this->table}
                (user_id, device_token, app_id, provider, product_id, purchase_token,
                 stripe_sub_id, stripe_customer, status, plan,
                 is_premium, show_ads, is_trial, trial_end,
                 started_at, expires_at)
            VALUES
                (:user_id, :device_token, :app_id, :provider, :product_id, :purchase_token,
                 :stripe_sub_id, :stripe_customer, 'active', :plan,
                 :is_premium, :show_ads, :is_trial, :trial_end,
                 :started_at, :expires_at)
            ON DUPLICATE KEY UPDATE
                device_token    = VALUES(device_token),
                provider        = VALUES(provider),
                product_id      = VALUES(product_id),
                purchase_token  = VALUES(purchase_token),
                stripe_sub_id   = VALUES(stripe_sub_id),
                stripe_customer = COALESCE(VALUES(stripe_customer), stripe_customer),
                status          = 'active',
                plan            = VALUES(plan),
                is_premium      = VALUES(is_premium),
                show_ads        = VALUES(show_ads),
                is_trial        = VALUES(is_trial),
                trial_end       = VALUES(trial_end),
                started_at      = VALUES(started_at),
                expires_at      = VALUES(expires_at),
                cancelled_at    = NULL,
                updated_at      = NOW()
        ");
        $stmt->execute([
            ':user_id'         => $data['user_id']         ?? null,
            ':device_token'    => $data['device_token']    ?? null,
            ':app_id'          => $data['app_id'],
            ':provider'        => $data['provider'],
            ':product_id'      => $data['product_id'],
            ':purchase_token'  => $data['purchase_token']  ?? null,
            ':stripe_sub_id'   => $data['stripe_sub_id']   ?? null,
            ':stripe_customer' => $data['stripe_customer']  ?? null,
            ':plan'            => $data['plan'],
            ':is_premium'      => $data['is_premium']       ?? 0,
            ':show_ads'        => $data['show_ads']         ?? 1,
            ':is_trial'        => $data['is_trial']         ?? 0,
            ':trial_end'       => $data['trial_end']        ?? null,
            ':started_at'      => $data['started_at'],
            ':expires_at'      => $data['expires_at'],
        ]);
    }

    /**
     * Passe un abonnement au statut 'expired' et révoque le premium.
     */
    public function markExpired(int $id): void
    {
        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table}
            SET status = 'expired', is_premium = 0, show_ads = 1, is_trial = 0,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /**
     * Passe un abonnement au statut 'cancelled' et enregistre la date d'annulation.
     */
    public function cancel(int $userId, string $appId): void
    {
        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table}
            SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
            WHERE user_id = ? AND app_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId, $appId]);
    }

    /**
     * Met à jour le stripe_sub_id d'un abonnement existant.
     */
    public function setStripeSubId(int $userId, string $appId, string $stripeSubId): void
    {
        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table}
            SET stripe_sub_id = ?, updated_at = NOW()
            WHERE user_id = ? AND app_id = ? AND status = 'active'
        ");
        $stmt->execute([$stripeSubId, $userId, $appId]);
    }

    /**
     * Met à jour un abonnement par stripe_sub_id (colonnes whitelistées).
     */
    public function updateByStripeSubId(string $stripeSubId, array $data): void
    {
        $allowed = ['status', 'is_premium', 'show_ads', 'is_trial', 'trial_end',
                    'expires_at', 'plan', 'stripe_customer'];
        $sets    = ['updated_at = NOW()'];
        $params  = [':stripe_sub_id' => $stripeSubId];

        foreach ($data as $col => $val) {
            if (in_array($col, $allowed, true)) {
                $sets[]         = "{$col} = :{$col}";
                $params[":{$col}"] = $val;
            }
        }

        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table}
            SET " . implode(', ', $sets) . "
            WHERE stripe_sub_id = :stripe_sub_id
        ");
        $stmt->execute($params);
    }

    /**
     * Retourne le stripe_customer d'un utilisateur (toutes apps confondues), ou null.
     */
    public function findStripeCustomerByUserId(int $userId): ?string
    {
        $stmt = $this->getDb()->prepare("
            SELECT stripe_customer FROM {$this->table}
            WHERE user_id = ? AND stripe_customer IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['stripe_customer'] : null;
    }

    /**
     * Retourne le stripe_customer d'un utilisateur pour une app donnée, ou null.
     */
    public function findStripeCustomerByUserAndApp(int $userId, string $appId): ?string
    {
        $stmt = $this->getDb()->prepare("
            SELECT stripe_customer FROM {$this->table}
            WHERE user_id = ? AND app_id = ? AND stripe_customer IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$userId, $appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['stripe_customer'] : null;
    }
}
