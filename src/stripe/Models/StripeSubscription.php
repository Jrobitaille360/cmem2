<?php

namespace Stripe\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class StripeSubscription extends BaseModel
{
    protected $table = 'stripe_subscriptions';

    public function create() { throw new \LogicException('Utiliser upsert()'); }
    public function update() { throw new \LogicException('Utiliser updateByStripeSubId()'); }

    public function upsert(array $data): void
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO stripe_subscriptions
                (user_id, app_id, stripe_customer_id, stripe_subscription_id, plan, status,
                 is_trial, trial_end, expires_at, cancel_at_period_end)
            VALUES
                (:user_id, :app_id, :stripe_customer_id, :stripe_subscription_id, :plan, :status,
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
            ':user_id'                => $data['user_id'],
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
}
