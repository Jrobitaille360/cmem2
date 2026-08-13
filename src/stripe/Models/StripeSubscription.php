<?php

namespace Stripe\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class StripeSubscription extends BaseModel
{
    protected $table = 'stripe_subscriptions';

    public function create() { throw new \LogicException('Utiliser upsert()'); }
    public function update() { throw new \LogicException('Utiliser updateByStripeSubId()'); }

    /**
     * XOR : exactement un des deux porteurs par ligne — user_id (perso) OU group_id (groupe).
     */
    public function upsert(array $data): void
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO stripe_subscriptions
                (user_id, group_id, app_id, stripe_customer_id, stripe_subscription_id, plan, status,
                 is_trial, trial_end, expires_at, cancel_at_period_end)
            VALUES
                (:user_id, :group_id, :app_id, :stripe_customer_id, :stripe_subscription_id, :plan, :status,
                 :is_trial, :trial_end, :expires_at, :cancel_at_period_end)
            ON DUPLICATE KEY UPDATE
                status                 = VALUES(status),
                stripe_subscription_id = VALUES(stripe_subscription_id),
                plan                   = VALUES(plan),
                is_trial               = VALUES(is_trial),
                trial_end              = VALUES(trial_end),
                expires_at             = VALUES(expires_at),
                cancel_at_period_end   = VALUES(cancel_at_period_end),
                updated_at             = NOW()
        ");
        $stmt->execute([
            ':user_id'                => $data['user_id'] ?? null,
            ':group_id'               => $data['group_id'] ?? null,
            ':app_id'                 => $data['app_id'],
            ':stripe_customer_id'     => $data['stripe_customer_id'],
            ':stripe_subscription_id' => $data['stripe_subscription_id'] ?? null,
            ':plan'                   => $data['plan'],
            ':status'                 => $data['status'],
            ':is_trial'               => $data['is_trial'] ?? 0,
            ':trial_end'              => $data['trial_end'] ?? null,
            ':expires_at'             => $data['expires_at'] ?? null,
            ':cancel_at_period_end'   => $data['cancel_at_period_end'] ?? 0,
        ]);
    }

    public function updateByStripeSubId(string $stripeSubId, array $fields): void
    {
        $allowed = ['status', 'is_trial', 'trial_end', 'expires_at', 'plan', 'cancel_at_period_end'];
        $parts   = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (\array_key_exists($col, $fields)) {
                $parts[]  = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }

        if (empty($parts)) {
            return;
        }

        $params[] = $stripeSubId;
        $stmt = $this->getDb()->prepare(
            "UPDATE stripe_subscriptions SET " . implode(', ', $parts) . " WHERE stripe_subscription_id = ?"
        );
        $stmt->execute($params);
    }

    public function findByUserAndApp(int $userId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM stripe_subscriptions WHERE user_id = ? AND app_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Trouve l'abonnement d'un user pour une famille d'app_id (ex. tenant cmem : ['cmemweb','cmem']).
     * Priorité à un statut actif, puis au plus récent. Usage : résolution du plan cmem effectif.
     */
    public function findByUserAndApps(int $userId, array $appIds): ?array
    {
        if ($appIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($appIds), '?'));
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM stripe_subscriptions
             WHERE user_id = ? AND app_id IN ({$placeholders})
             ORDER BY FIELD(status, 'active', 'trialing', 'past_due') = 0, updated_at DESC
             LIMIT 1"
        );
        $stmt->execute(array_merge([$userId], array_values($appIds)));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateByUserAndApp(int $userId, string $appId, array $fields): void
    {
        $allowed = ['status', 'is_trial', 'trial_end', 'expires_at', 'plan', 'cancel_at_period_end'];
        $parts   = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (\array_key_exists($col, $fields)) {
                $parts[]  = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }

        if (empty($parts)) {
            return;
        }

        $params[] = $userId;
        $params[] = $appId;
        $stmt = $this->getDb()->prepare(
            "UPDATE stripe_subscriptions SET " . implode(', ', $parts) . " WHERE user_id = ? AND app_id = ?"
        );
        $stmt->execute($params);
    }

    public function findStripeCustomerByUserAndApp(int $userId, string $appId): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT stripe_customer_id FROM stripe_subscriptions WHERE user_id = ? AND app_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $appId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    // -----------------------------------------------------------------------
    // Variantes groupe — plan équipe (directive 20260813_143000)
    // -----------------------------------------------------------------------

    public function findByGroupAndApp(int $groupId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM stripe_subscriptions WHERE group_id = ? AND app_id = ? LIMIT 1"
        );
        $stmt->execute([$groupId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Trouve l'abonnement d'un groupe pour une famille d'app_id (ex. tenant cmem).
     * Même logique que findByUserAndApps() : priorité au statut actif, puis au plus récent.
     */
    public function findByGroupAndApps(int $groupId, array $appIds): ?array
    {
        if ($appIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($appIds), '?'));
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM stripe_subscriptions
             WHERE group_id = ? AND app_id IN ({$placeholders})
             ORDER BY FIELD(status, 'active', 'trialing', 'past_due') = 0, updated_at DESC
             LIMIT 1"
        );
        $stmt->execute(array_merge([$groupId], array_values($appIds)));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateByGroupAndApp(int $groupId, string $appId, array $fields): void
    {
        $allowed = ['status', 'is_trial', 'trial_end', 'expires_at', 'plan', 'cancel_at_period_end'];
        $parts   = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (\array_key_exists($col, $fields)) {
                $parts[]  = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }

        if (empty($parts)) {
            return;
        }

        $params[] = $groupId;
        $params[] = $appId;
        $stmt = $this->getDb()->prepare(
            "UPDATE stripe_subscriptions SET " . implode(', ', $parts) . " WHERE group_id = ? AND app_id = ?"
        );
        $stmt->execute($params);
    }

    public function findStripeCustomerByGroupAndApp(int $groupId, string $appId): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT stripe_customer_id FROM stripe_subscriptions WHERE group_id = ? AND app_id = ? LIMIT 1"
        );
        $stmt->execute([$groupId, $appId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }
}
