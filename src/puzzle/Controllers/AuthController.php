<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\Subscription;
use AuthGroups\Services\SubscriptionService;
use AuthGroups\Utils\Response;
use Puzzle\Models\PuzzleDevice;
use Puzzle\Services\DeviceTokenService;
use Puzzle\Services\GooglePlayService;

/**
 * AuthController — enregistrement appareil, validation abonnement, pseudonyme
 */
class AuthController
{
    // -----------------------------------------------------------------------
    // POST /puzzle/auth/register-device  (sans auth)
    // -----------------------------------------------------------------------

    public function registerDevice(): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $deviceUuid = trim($input['device_uuid'] ?? '');
        if ($deviceUuid === '') {
            LoggingMiddleware::logExit(422);
            Response::error('device_uuid requis', ['field' => 'device_uuid'], 422);
            return;
        }

        $svc        = new DeviceTokenService();
        $token      = $svc->generateToken();
        $expiresAt  = $svc->expiresAt();

        $deviceModel = new PuzzleDevice();
        $deviceModel->upsert($deviceUuid, $token, $expiresAt);

        LoggingMiddleware::logExit(200);
        Response::success('Appareil enregistré', [
            'device_token' => $token,
            'expires_at'   => date('c', strtotime($expiresAt)),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/auth/verify-subscription  (device_token)
    // -----------------------------------------------------------------------

    public function verifySubscription(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $purchaseToken = trim($input['purchase_token'] ?? '');
        $productId     = trim($input['product_id'] ?? '');

        if ($purchaseToken === '' || $productId === '') {
            LoggingMiddleware::logExit(422);
            Response::error('purchase_token et product_id requis', null, 422);
            return;
        }

        $validProducts = ['premium_monthly', 'premium_yearly'];
        if (!in_array($productId, $validProducts, true)) {
            LoggingMiddleware::logExit(422);
            Response::error('product_id invalide', ['field' => 'product_id'], 422);
            return;
        }

        $result = (new GooglePlayService())->validateSubscription($purchaseToken, $productId);

        if ($result === null) {
            LoggingMiddleware::logExit(422);
            Response::error('Reçu Google Play invalide ou abonnement expiré', ['code' => 'SUBSCRIPTION_INVALID'], 422);
            return;
        }

        // Upgrade/downgrade : expirer l'ancien abonnement lié
        if (!empty($result['linked_purchase_token'])) {
            (new Subscription())->expireByPurchaseToken($result['linked_purchase_token']);
        }

        // user_id disponible si Flutter a transmis obfuscatedExternalAccountId à l'achat
        $userId = !empty($result['user_id']) ? (int) $result['user_id'] : null;

        $subModel = new Subscription();
        $existing = $subModel->findByPurchaseToken($result['purchase_token'], 'puzzle');

        if ($existing !== null) {
            // Re-verify : mettre à jour expires_at et status directement par purchase_token
            $subModel->renewByPurchaseToken(
                $result['purchase_token'],
                'puzzle',
                $result['expires_at'],
                (bool) $result['is_premium']
            );
        } else {
            SubscriptionService::activatePremium($userId, 'puzzle', [
                'purchase_token' => $result['purchase_token'],
                'provider'       => 'google_play',
                'product_id'     => $result['product_id'],
                'plan'           => str_contains($result['product_id'], 'yearly') ? 'yearly' : 'monthly',
                'is_trial'       => $result['is_trial'],
                'trial_end'      => $result['trial_end'],
                'started_at'     => date('Y-m-d H:i:s'),
                'expires_at'     => $result['expires_at'],
            ]);
        }

        // Synchroniser la table puzzle_devices pour que requireDeviceToken trouve l'abonnement
        (new PuzzleDevice())->updateSubscription((int) $device['id'], [
            'is_premium'         => $result['is_premium'],
            'purchase_token'     => $result['purchase_token'],
            'product_id'         => $result['product_id'],
            'premium_expires_at' => $result['expires_at'],
        ]);

        LoggingMiddleware::logExit(200);
        Response::success(
            $result['is_premium'] ? 'Abonnement actif' : 'Abonnement expiré',
            [
                'is_premium' => (bool) $result['is_premium'],
                'product_id' => $result['product_id'],
                'expires_at' => date('c', strtotime($result['expires_at'])),
            ]
        );
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/auth/subscription-status  (device_token)
    // -----------------------------------------------------------------------

    public function getSubscriptionStatus(array $device): void
    {
        LoggingMiddleware::logEntry();

        $purchaseToken = $device['purchase_token'] ?? null;
        $productId     = $device['product_id']     ?? null;

        if (empty($purchaseToken) || empty($productId)) {
            LoggingMiddleware::logExit(200);
            Response::success('Aucun abonnement enregistré', [
                'is_premium' => false,
                'expires_at' => null,
                'provider'   => null,
                'stale'      => false,
            ]);
            return;
        }

        $result = (new GooglePlayService())->validateSubscription($purchaseToken, $productId);

        if ($result === null) {
            // Fail-safe : Google Play inaccessible — utiliser la DB
            $sub       = (new Subscription())->findActiveByPurchaseToken($purchaseToken, 'puzzle');
            $isPremium = $sub !== null;

            LoggingMiddleware::logExit(200);
            Response::success('Statut abonnement (cache DB)', [
                'is_premium' => $isPremium,
                'expires_at' => $isPremium ? date('c', strtotime($sub['expires_at'])) : null,
                'provider'   => 'google_play',
                'stale'      => true,
            ]);
            return;
        }

        $isPremium = (bool) $result['is_premium'];
        $expiresAt = $result['expires_at'];

        // Mettre à jour la table subscriptions
        $subModel = new Subscription();
        $existing = $subModel->findByPurchaseToken($result['purchase_token'], 'puzzle');
        if ($existing !== null) {
            $subModel->renewByPurchaseToken(
                $result['purchase_token'],
                'puzzle',
                $expiresAt,
                $isPremium
            );
        }

        // Mettre à jour la table puzzle_devices
        (new PuzzleDevice())->updateSubscription((int) $device['id'], [
            'is_premium'         => $isPremium ? 1 : 0,
            'purchase_token'     => $purchaseToken,
            'product_id'         => $productId,
            'premium_expires_at' => $expiresAt,
        ]);

        LoggingMiddleware::logExit(200);
        Response::success(
            $isPremium ? 'Abonnement actif' : 'Abonnement expiré ou annulé',
            [
                'is_premium' => $isPremium,
                'expires_at' => date('c', strtotime($expiresAt)),
                'provider'   => 'google_play',
                'stale'      => false,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/auth/link-device  (JWT cmem2)
    // -----------------------------------------------------------------------

    public function linkDevice(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $deviceToken = trim($input['device_token'] ?? '');
        if ($deviceToken === '') {
            LoggingMiddleware::logExit(422);
            Response::error('device_token requis', ['field' => 'device_token'], 422);
            return;
        }

        $device = (new PuzzleDevice())->findByValidToken($deviceToken);
        if ($device === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Token d\'appareil inconnu ou expiré', ['code' => 'DEVICE_NOT_FOUND'], 404);
            return;
        }

        (new PuzzleDevice())->setUserId((int) $device['id'], (int) $user['user_id']);

        LoggingMiddleware::logExit(200);
        Response::success('Appareil lié au compte', ['device_id' => (int) $device['id']]);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/auth/pseudonym  (device_token)
    // -----------------------------------------------------------------------

    public function getPseudonym(array $device): void
    {
        LoggingMiddleware::logEntry();

        $pseudonym = $device['pseudonym'] ?? null;

        LoggingMiddleware::logExit(200);
        if ($pseudonym !== null) {
            Response::success('Pseudonyme récupéré', ['pseudonym' => $pseudonym]);
        } else {
            Response::success('Aucun pseudonyme défini', ['pseudonym' => null]);
        }
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/auth/check-pseudonym/{pseudonym}  (device_token)
    // -----------------------------------------------------------------------

    public function checkPseudonym(string $pseudonym, array $device): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->isValidPseudonym($pseudonym)) {
            LoggingMiddleware::logExit(422);
            Response::error('Pseudonyme invalide', ['code' => 'PSEUDONYM_INVALID'], 422);
            return;
        }

        $existing = (new PuzzleDevice())->findByPseudonymCI($pseudonym);
        $available = !$existing || (int) $existing['id'] === (int) $device['id'];

        LoggingMiddleware::logExit(200);
        Response::success(
            $available ? 'Pseudonyme disponible' : 'Pseudonyme déjà utilisé',
            ['available' => $available]
        );
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/auth/pseudonym  (device_token)
    // -----------------------------------------------------------------------

    public function setPseudonym(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $pseudonym = trim($input['pseudonym'] ?? '');

        if (!$this->isValidPseudonym($pseudonym)) {
            LoggingMiddleware::logExit(422);
            Response::error('Pseudonyme invalide', ['code' => 'PSEUDONYM_INVALID'], 422);
            return;
        }

        $deviceModel = new PuzzleDevice();

        $existing = $deviceModel->findByPseudonymCI($pseudonym);
        if ($existing && (int) $existing['id'] !== (int) $device['id']) {
            LoggingMiddleware::logExit(409);
            Response::error('Pseudonyme déjà utilisé', ['code' => 'PSEUDONYM_TAKEN'], 409);
            return;
        }

        $deviceModel->setPseudonym((int) $device['id'], $pseudonym);

        LoggingMiddleware::logExit(200);
        Response::success('Pseudonyme enregistré', ['pseudonym' => $pseudonym]);
    }

    // -----------------------------------------------------------------------
    // DELETE /puzzle/auth/pseudonym  (device_token)
    // -----------------------------------------------------------------------

    public function deletePseudonym(array $device): void
    {
        LoggingMiddleware::logEntry();

        (new PuzzleDevice())->clearPseudonym((int) $device['id']);

        LoggingMiddleware::logExit(200);
        Response::success('Pseudonyme libéré', []);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function isValidPseudonym(string $pseudonym): bool
    {
        if ($pseudonym === '') return false;
        $len = mb_strlen($pseudonym);
        if ($len < 3 || $len > 20) return false;
        // Lettres (avec accents), chiffres, tirets, tirets bas — pas d'espaces
        return (bool) preg_match('/^[\p{L}\p{N}_-]+$/u', $pseudonym);
    }
}
