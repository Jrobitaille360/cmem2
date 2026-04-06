<?php

namespace Puzzle\Services;

/**
 * GooglePlayService — valide un purchase_token via Google Play Developer API.
 *
 * Prérequis : PUZZLE_GOOGLE_PLAY_PACKAGE et PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON
 * doivent être définis (constantes ou .env chargé).
 *
 * Utilise une requête HTTP native (pas de SDK) pour éviter les dépendances.
 * L'authentification se fait via un Bearer token obtenu avec le service account JWT.
 */
class GooglePlayService
{
    private string $package;
    private string $serviceAccountJson;

    public function __construct()
    {
        $this->package            = defined('PUZZLE_GOOGLE_PLAY_PACKAGE')
            ? PUZZLE_GOOGLE_PLAY_PACKAGE
            : '';
        $this->serviceAccountJson = defined('PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON')
            ? PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON
            : '';
    }

    /**
     * Valide un achat d'abonnement auprès de Google Play.
     *
     * @return array|null {is_premium, product_id, purchase_token, expires_at (Y-m-d H:i:s)}
     *                    null si le reçu est invalide ou l'abonnement expiré.
     */
    public function validateSubscription(string $purchaseToken, string $productId): ?array
    {
        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return null;
        }

        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s',
            urlencode($this->package),
            urlencode($productId),
            urlencode($purchaseToken)
        );

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['expiryTimeMillis'])) {
            return null;
        }

        $expiryMs = (int) $data['expiryTimeMillis'];
        $expiresAt = date('Y-m-d H:i:s', intdiv($expiryMs, 1000));
        $isPremium = $expiryMs > (time() * 1000);

        return [
            'is_premium'     => (int) $isPremium,
            'product_id'     => $productId,
            'purchase_token' => $purchaseToken,
            'expires_at'     => $expiresAt,
        ];
    }

    /**
     * Obtient un Bearer token OAuth2 depuis le service account JSON.
     * Génère un JWT signé avec la clé privée du service account.
     */
    private function getAccessToken(): ?string
    {
        if (!file_exists($this->serviceAccountJson)) {
            return null;
        }

        $sa = json_decode(file_get_contents($this->serviceAccountJson), true);
        if (!$sa || !isset($sa['private_key'], $sa['client_email'])) {
            return null;
        }

        $now    = time();
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim  = base64_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $unsignedJwt = "{$header}.{$claim}";
        $privateKey  = openssl_pkey_get_private($sa['private_key']);
        if ($privateKey === false) {
            return null;
        }

        openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $unsignedJwt . '.' . base64_encode($signature);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]),
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
        if ($response === false) {
            return null;
        }

        $token = json_decode($response, true);
        return $token['access_token'] ?? null;
    }
}
