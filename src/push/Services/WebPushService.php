<?php

namespace Push\Services;

use AuthGroups\Services\LogService;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Push\Models\PushSubscription;

/**
 * Envoi Web Push (RFC 8030 + VAPID RFC 8292) via minishlink/web-push.
 *
 * Confidentialité : le corps transporté ne contient jamais le titre d'une entité.
 * Le payload se limite à un libellé générique + { type, id, occurrence } pour que le
 * clic ouvre la bonne fiche dans l'application.
 *
 * Maintenance : toute subscription rejetée en 404/410 par le service de push est
 * supprimée immédiatement (endpoint mort — appareil désinstallé, permission révoquée).
 */
class WebPushService
{
    public static function publicKey(): string
    {
        return trim((string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''));
    }

    private static function privateKey(): string
    {
        return trim((string) ($_ENV['VAPID_PRIVATE_KEY'] ?? ''));
    }

    private static function subject(): string
    {
        $subject = trim((string) ($_ENV['VAPID_SUBJECT'] ?? ''));
        return $subject !== '' ? $subject : 'mailto:support@journauxdebord.com';
    }

    public static function isConfigured(): bool
    {
        return self::publicKey() !== '' && self::privateKey() !== '';
    }

    public static function genericTitle(): string
    {
        $title = trim((string) ($_ENV['PUSH_GENERIC_TITLE'] ?? ''));
        return $title !== '' ? $title : 'Rappel';
    }

    private static function ttl(): int
    {
        return max(60, (int) ($_ENV['PUSH_TTL_SECONDS'] ?? 86400));
    }

    /**
     * Poste de développement Windows (XAMPP) : sans la variable d'environnement
     * OPENSSL_CONF, openssl_pkey_new() échoue (« Unable to create the local key ») et
     * aucun chiffrement n'est possible. putenv() arrive trop tard — la variable doit être
     * posée AVANT le lancement du processus PHP :
     *
     *   set OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf
     *
     * Sans objet sur le serveur Linux, où la configuration OpenSSL est trouvée seule.
     */
    private static function warnIfOpensslUnconfigured(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF')) {
            LogService::warning('WebPushService : OPENSSL_CONF absente — le chiffrement du payload va échouer sur ce poste Windows');
        }
    }

    /** Options du client HTTP — PUSH_CA_BUNDLE sert aux postes sans magasin de CA (XAMPP). */
    private static function clientOptions(): array
    {
        $caBundle = trim((string) ($_ENV['PUSH_CA_BUNDLE'] ?? ''));
        return $caBundle !== '' && is_file($caBundle) ? ['verify' => $caBundle] : [];
    }

    /**
     * Envoie un payload à tous les appareils d'un usager (compatibilité — le cron unifié
     * #487 passe par sendToSubscriptions() pour journaliser appareil par appareil).
     *
     * @param array $payload  ['title' => …, 'body' => …, 'data' => ['type' => …, 'id' => …]]
     * @return array{devices:int, delivered:int, purged:int, error:?string}
     */
    public static function sendToOwner(int $ownerId, array $payload): array
    {
        $subs = (new PushSubscription())->listByOwner($ownerId);
        if (empty($subs)) {
            return ['devices' => 0, 'delivered' => 0, 'purged' => 0, 'error' => 'aucune subscription'];
        }

        $results   = self::sendToSubscriptions($subs, $payload);
        $delivered = count(array_filter($results, fn($r) => $r['ok']));
        $purged    = count(array_filter($results, fn($r) => $r['purged']));
        $lastError = null;
        foreach ($results as $r) {
            if (!$r['ok'] && $r['error'] !== null) {
                $lastError = $r['error'];
            }
        }

        return [
            'devices'   => count($subs),
            'delivered' => $delivered,
            'purged'    => $purged,
            'error'     => $delivered > 0 ? null : $lastError,
        ];
    }

    /**
     * Envoie un payload à une liste d'abonnements (lignes push_subscriptions) et rend le
     * résultat appareil par appareil. Un endpoint mort (404 / 410) est purgé sur-le-champ ;
     * un envoi réussi met à jour last_seen_at (= last_success_at du contrat #236).
     *
     * @param array<int, array> $subs lignes push_subscriptions (id, endpoint, p256dh, auth)
     * @return array<int, array{ok:bool, purged:bool, error:?string}> indexé par id d'abonnement
     */
    public static function sendToSubscriptions(array $subs, array $payload): array
    {
        $results = [];
        foreach ($subs as $sub) {
            $results[(int) $sub['id']] = ['ok' => false, 'purged' => false, 'error' => null];
        }
        if (empty($subs)) {
            return $results;
        }
        if (!self::isConfigured()) {
            foreach ($results as &$r) {
                $r['error'] = 'VAPID non configuré';
            }
            return $results;
        }

        self::warnIfOpensslUnconfigured();

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => self::subject(),
                    'publicKey'  => self::publicKey(),
                    'privateKey' => self::privateKey(),
                ],
            ], ['TTL' => self::ttl(), 'urgency' => 'high'], null, self::clientOptions());
            $webPush->setReuseVAPIDHeaders(true);
        } catch (\Throwable $e) {
            LogService::error('WebPushService : initialisation impossible', ['error' => $e->getMessage()]);
            foreach ($results as &$r) {
                $r['error'] = mb_substr($e->getMessage(), 0, 255);
            }
            return $results;
        }

        $model      = new PushSubscription();
        $byEndpoint = [];
        $json       = json_encode($payload, JSON_UNESCAPED_UNICODE);

        foreach ($subs as $sub) {
            $byEndpoint[$sub['endpoint']] = (int) $sub['id'];
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint'        => $sub['endpoint'],
                        'publicKey'       => $sub['p256dh'],
                        'authToken'       => $sub['auth'],
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    $json
                );
            } catch (\Throwable $e) {
                $results[(int) $sub['id']]['error'] = 'subscription illisible';
                LogService::warning('WebPushService : subscription illisible', [
                    'subscription_id' => $sub['id'],
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        try {
            foreach ($webPush->flush() as $report) {
                $subId = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($subId === null) {
                    continue;
                }

                if ($report->isSuccess()) {
                    $results[$subId]['ok'] = true;
                    $model->touchLastSeen($subId);
                    continue;
                }

                $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
                $results[$subId]['error'] = mb_substr((string) $report->getReason(), 0, 255);

                // 404 / 410 : endpoint mort → purge immédiate (maintenance principale).
                if ($report->isSubscriptionExpired() || in_array($status, [404, 410], true)) {
                    $model->deleteById($subId);
                    $results[$subId]['purged'] = true;
                    LogService::info('Push : subscription purgée', [
                        'subscription_id' => $subId,
                        'status'          => $status,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            LogService::error('WebPushService : flush en échec', ['error' => $e->getMessage()]);
            foreach ($results as &$r) {
                if (!$r['ok'] && $r['error'] === null) {
                    $r['error'] = mb_substr($e->getMessage(), 0, 255);
                }
            }
        }

        return $results;
    }
}
