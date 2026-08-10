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
     * Envoie un payload à tous les appareils d'un usager.
     *
     * @param array $payload  ['title' => …, 'body' => …, 'data' => ['type' => …, 'id' => …]]
     * @return array{devices:int, delivered:int, purged:int, error:?string}
     */
    public static function sendToOwner(int $ownerId, array $payload): array
    {
        $model = new PushSubscription();
        $subs  = $model->listByOwner($ownerId);

        if (empty($subs)) {
            return ['devices' => 0, 'delivered' => 0, 'purged' => 0, 'error' => 'aucune subscription'];
        }
        if (!self::isConfigured()) {
            return ['devices' => count($subs), 'delivered' => 0, 'purged' => 0, 'error' => 'VAPID non configuré'];
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
            return ['devices' => count($subs), 'delivered' => 0, 'purged' => 0, 'error' => $e->getMessage()];
        }

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
                LogService::warning('WebPushService : subscription illisible', [
                    'subscription_id' => $sub['id'],
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        $delivered = 0;
        $purged    = 0;
        $lastError = null;

        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $subId    = $byEndpoint[$endpoint] ?? null;

                if ($report->isSuccess()) {
                    $delivered++;
                    if ($subId) {
                        $model->touchLastSeen($subId);
                    }
                    continue;
                }

                $lastError = $report->getReason();
                $status    = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;

                // 404 / 410 : endpoint mort → purge immédiate (maintenance principale).
                if ($subId && ($report->isSubscriptionExpired() || in_array($status, [404, 410], true))) {
                    $model->deleteById($subId);
                    $purged++;
                    LogService::info('Push : subscription purgée', [
                        'subscription_id' => $subId,
                        'status'          => $status,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            LogService::error('WebPushService : flush en échec', ['error' => $e->getMessage()]);
        }

        return [
            'devices'   => count($subs),
            'delivered' => $delivered,
            'purged'    => $purged,
            'error'     => $delivered > 0 ? null : $lastError,
        ];
    }
}
