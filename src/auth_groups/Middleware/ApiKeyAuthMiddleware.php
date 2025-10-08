<?php
/**
 * Middleware d'authentification par API Key
 * Alternative au JWT pour les intégrations machine-to-machine
 */

namespace AuthGroups\Middleware;

use AuthGroups\Models\ApiKey;
use AuthGroups\Utils\Response;

class ApiKeyAuthMiddleware
{
    /**
     * Vérifier l'authentification par API key
     * 
     * @param string|null $requiredScope Scope requis (optionnel)
     * @return array|null Données de la clé si valide, null sinon (envoie une réponse d'erreur)
     */
    public static function authenticate(?string $requiredScope = null): ?array
    {
        // Récupérer la clé API depuis les headers
        $apiKey = self::getApiKeyFromRequest();
        
        if (!$apiKey) {
            Response::error('API key manquante', [
                'error' => 'MISSING_API_KEY',
                'message' => 'Utilisez le header X-API-Key ou Authorization: Bearer <key>'
            ], 401);
            return null;
        }
        
        // Valider la clé
        $keyData = ApiKey::validate($apiKey);
        
        // null = clé inexistante
        if ($keyData === null) {
            Response::error('Clé API invalide', [
                'error' => 'INVALID_API_KEY',
                'message' => 'Clé API inexistante ou invalide'
            ], 401);
            return null;
        }
        
        // false = clé révoquée ou expirée
        if ($keyData === false) {
            Response::error('Clé API révoquée ou expirée', [
                'error' => 'REVOKED_OR_EXPIRED_API_KEY',
                'message' => 'Cette clé API a été révoquée ou est expirée'
            ], 401);
            return null;
        }
        
        // Vérifier le scope si requis
        if ($requiredScope && !ApiKey::hasScope($keyData, $requiredScope)) {
            Response::error('Permissions insuffisantes', [
                'error' => 'INSUFFICIENT_PERMISSIONS',
                'message' => "Cette clé API ne dispose pas du scope requis: {$requiredScope}",
                'required_scope' => $requiredScope,
                'available_scopes' => $keyData['scopes']
            ], 403);
            return null;
        }
        
        // Vérifier le rate limiting
        $rateLimit = ApiKey::checkRateLimit($keyData['id']);
        
        if (!$rateLimit['allowed']) {
            Response::error([
                'error' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Limite de taux dépassée pour cette clé API',
                'limit' => $keyData['rate_limit_per_minute'],
                'reset_at' => $rateLimit['reset_at']
            ], 429); // Too Many Requests
            return null;
        }
        
        // Ajouter les informations de rate limiting aux headers (pour info client)
        header("X-RateLimit-Remaining: " . $rateLimit['remaining']);
        header("X-RateLimit-Reset: " . $rateLimit['reset_at']);
        
        return $keyData;
    }
    
    /**
     * Récupérer la clé API depuis la requête
     * Supporte plusieurs méthodes :
     * - Header X-API-Key
     * - Header Authorization: Bearer <key>
     * - Query parameter ?api_key=<key> (déconseillé en production)
     */
    private static function getApiKeyFromRequest(): ?string
    {
        // 1. Header X-API-Key (recommandé)
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        // 2. Header Authorization: Bearer
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            
            // Support pour Authorization: Bearer <api_key>
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = trim($matches[1]);
                
                // Vérifier si c'est une API key (commence par ag_)
                if (str_starts_with($token, 'ag_live_') || str_starts_with($token, 'ag_test_')) {
                    return $token;
                }
            }
        }
        
        // 3. Query parameter (déconseillé mais supporté pour dev/test)
        if (isset($_GET['api_key']) && defined('APP_DEBUG') && APP_DEBUG) {
            return trim($_GET['api_key']);
        }
        
        return null;
    }
    
    /**
     * Vérifier si la requête utilise une API key (vs JWT)
     */
    public static function hasApiKey(): bool
    {
        return self::getApiKeyFromRequest() !== null;
    }
    
    /**
     * Middleware combiné : accepte JWT OU API key
     * Retourne les données user (depuis JWT ou API key)
     */
    public static function authenticateFlexible(?string $requiredScope = null): ?array
    {
        // Si une API key est fournie, utiliser l'authentification API key
        if (self::hasApiKey()) {
            $keyData = self::authenticate($requiredScope);
            
            if (!$keyData) {
                return null; // Erreur déjà envoyée par authenticate()
            }
            
            // Retourner un format compatible avec l'authentification JWT
            return [
                'auth_type' => 'api_key',
                'user_id' => $keyData['user_id'],
                'api_key_id' => $keyData['id'],
                'api_key_name' => $keyData['name'],
                'scopes' => $keyData['scopes'],
                'environment' => $keyData['environment']
            ];
        }
        
        // Sinon, utiliser l'authentification JWT classique
        // (déléguer à AuthService ou autre middleware JWT existant)
        require_once __DIR__ . '/../Services/AuthService.php';
        $authService = new \AuthGroups\Services\AuthService();
        
        // Récupérer le token depuis le header
        $token = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = trim($matches[1]);
            }
        }
        
        if (!$token) {
            Response::error('Authentification requise', [
                'error' => 'MISSING_TOKEN',
                'message' => 'Token JWT manquant'
            ], 401);
            return null;
        }
        
        $userData = $authService->validateToken($token);
        
        if (!$userData) {
            Response::error('Authentification invalide', [
                'error' => 'INVALID_TOKEN',
                'message' => 'Token JWT invalide ou expiré'
            ], 401);
            return null;
        }
        
        return [
            'auth_type' => 'jwt',
            'user_id' => $userData['user_id'],
            'email' => $userData['email'] ?? null
        ];
    }
    
    /**
     * Extraire l'ID utilisateur depuis les données d'authentification
     */
    public static function getUserId(array $authData): int
    {
        return $authData['user_id'];
    }
    
    /**
     * Vérifier si l'environnement de la clé est "test"
     */
    public static function isTestEnvironment(?array $keyData): bool
    {
        if (!$keyData || !isset($keyData['environment'])) {
            return false;
        }
        
        return $keyData['environment'] === ApiKey::ENV_TEST;
    }
}
