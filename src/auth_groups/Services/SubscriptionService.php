<?php

namespace AuthGroups\Services;

use AuthGroups\Models\Subscription;

/**
 * Service d'abonnements Premium — par utilisateur et par application.
 *
 * Toute la logique Premium passe ici.
 * La table users n'est jamais modifiée par ce service.
 */
class SubscriptionService
{
    // -----------------------------------------------------------------------
    // Activation / désactivation
    // -----------------------------------------------------------------------

    /**
     * Active (ou renouvelle) un abonnement Premium pour un utilisateur et une application.
     *
     * @param int    $userId  ID de l'utilisateur
     * @param string $appId   Identifiant de l'application (ex : 'puzzle', 'pomo', 'quiz')
     * @param array  $data {
     *   provider       : 'stripe'|'google_play'|'apple'|'microsoft'
     *   product_id     : string
     *   plan           : 'monthly'|'yearly'
     *   started_at     : string (Y-m-d H:i:s)
     *   expires_at     : string (Y-m-d H:i:s)
     *   purchase_token : string|null
     *   stripe_sub_id  : string|null
     * }
     */
    public static function activatePremium(int $userId, string $appId, array $data): void
    {
        $model = new Subscription();
        $model->upsert(array_merge($data, [
            'user_id' => $userId,
            'app_id'  => $appId,
        ]));

        LogService::info('Premium activé', [
            'user_id'  => $userId,
            'app_id'   => $appId,
            'provider' => $data['provider'],
            'expires'  => $data['expires_at'],
        ]);
    }

    /**
     * Annule l'abonnement actif d'un utilisateur pour une application donnée.
     * L'accès Premium reste actif jusqu'à expires_at ; seul le statut devient 'cancelled'.
     */
    public static function deactivatePremium(int $userId, string $appId): void
    {
        $model = new Subscription();
        $model->cancel($userId, $appId);

        LogService::info('Premium annulé', ['user_id' => $userId, 'app_id' => $appId]);
    }

    // -----------------------------------------------------------------------
    // Lecture du statut
    // -----------------------------------------------------------------------

    /**
     * Statut Premium pour un utilisateur et une application.
     *
     * @return array {is_premium: bool, show_ads: bool, expires_at: string|null, provider: string|null, plan: string|null}
     */
    public static function getStatus(int $userId, string $appId): array
    {
        $model  = new Subscription();
        $active = $model->findActive($userId, $appId);

        $isPremium = $active !== null;
        return [
            'is_premium' => $isPremium,
            'show_ads'   => !$isPremium,
            'expires_at' => $active['expires_at'] ?? null,
            'provider'   => $active['provider']   ?? null,
            'plan'       => $active['plan']        ?? null,
        ];
    }

    /**
     * Statut Premium pour toutes les applications d'un utilisateur.
     * Retourne un tableau indexé par app_id, chaque valeur ayant la même structure que getStatus().
     *
     * @return array<string, array>
     */
    public static function getAllStatuses(int $userId): array
    {
        $model  = new Subscription();
        $actives = $model->findAllActive($userId);

        $result = [];
        foreach ($actives as $appId => $row) {
            $result[$appId] = [
                'is_premium' => true,
                'show_ads'   => false,
                'expires_at' => $row['expires_at'],
                'provider'   => $row['provider'],
                'plan'       => $row['plan'],
            ];
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // CRON — expiration automatique
    // -----------------------------------------------------------------------

    /**
     * Expire tous les abonnements actifs dont expires_at est dépassé.
     * Envoie un email de notification pour chaque expiration.
     *
     * @return int Nombre d'abonnements expirés
     */
    public static function checkAndExpireSubscriptions(): int
    {
        $model   = new Subscription();
        $expired = $model->findExpired();
        $count   = 0;

        foreach ($expired as $row) {
            try {
                $model->markExpired((int) $row['id']);
                $count++;

                LogService::info('Abonnement expiré (CRON)', [
                    'subscription_id' => $row['id'],
                    'user_id'         => $row['user_id'],
                    'app_id'          => $row['app_id'],
                    'expired_at'      => $row['expires_at'],
                ]);

                // Notification email (best-effort)
                try {
                    $emailService = new EmailService();
                    $emailService->sendSubscriptionExpired(
                        (int) $row['user_id'],
                        $row['app_id']
                    );
                } catch (\Throwable $e) {
                    LogService::warning('Échec envoi email expiration abonnement', [
                        'user_id' => $row['user_id'],
                        'app_id'  => $row['app_id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                LogService::error('Erreur expiration abonnement', [
                    'subscription_id' => $row['id'],
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
