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
            ? \PUZZLE_GOOGLE_PLAY_PACKAGE
            : '';
        $this->serviceAccountJson = defined('PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON')
            ? \PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON
            : '';
    }

    /**
     * Valide un achat d'abonnement auprès de Google Play (API subscriptionsv2).
     *
     * @return array|null {
     *   is_premium, show_ads, is_trial, trial_end,
     *   product_id, purchase_token, expires_at, user_id
     * }
     * Retourne null si le token est invalide ou si Google est inaccessible.
     * En cas d'erreur réseau, préférer retourner l'état en base avec stale=true
     * plutôt que null — géré par l'appelant (SubscriptionController).
     */
    public function validateSubscription(string $purchaseToken, string $productId): ?array
    {
        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return null;
        }

        // subscriptionsv2 : productId absent de l'URL, expiryTime en RFC 3339
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptionsv2/tokens/%s',
            urlencode($this->package),
            urlencode($purchaseToken)
        );

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }

        // Mapper subscriptionState → is_premium / show_ads
        $state     = $data['subscriptionState'] ?? '';
        $isPremium = \in_array($state, [
            'SUBSCRIPTION_STATE_ACTIVE',
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
            'SUBSCRIPTION_STATE_CANCELED',
        ], true);

        // Lire expiryTime depuis lineItems[0]
        $lineItem  = $data['lineItems'][0] ?? null;
        $expiryStr = $lineItem['expiryTime'] ?? null;
        if (!$expiryStr) {
            return null;
        }
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiryStr));

        // Détecter l'essai via offerTags
        $offerTags = $lineItem['offerDetails']['offerTags'] ?? [];
        $isTrial   = \in_array('free-trial', $offerTags, true);
        $trialEnd  = $isTrial ? $expiresAt : null;

        // Lire user_id transmis par Flutter à l'achat
        $obfuscatedId = $data['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null;
        $userId       = $obfuscatedId !== null ? (int) $obfuscatedId : null;

        return [
            'is_premium'     => $isPremium ? 1 : 0,
            'show_ads'       => $isPremium ? 0 : 1,
            'is_trial'       => $isTrial   ? 1 : 0,
            'trial_end'      => $trialEnd,
            'product_id'     => $lineItem['productId'] ?? $productId,
            'purchase_token' => $purchaseToken,
            'expires_at'     => $expiresAt,
            'user_id'        => $userId,
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
