<?php

namespace AuthGroups\Services;

use AuthGroups\Models\User;
use AuthGroups\Services\LogService;
use AuthGroups\Services\UserSessionService;
use AuthGroups\Middleware\ApiKeyAuthMiddleware;
use Exception;

/**
 * Service d'authentification
 * Gère l'authentification par API Key et validation de sessions
 */
class AuthService 
{
    /**
     * Authentifier l'utilisateur à partir de l'API Key et vérifier la session active
     * @return array|null Données utilisateur ou null si non authentifié
     */
    public function authenticate(): ?array {
        // Vérifier s'il y a une API Key
        if (!ApiKeyAuthMiddleware::hasApiKey()) {
            return null;
        }
        
        // Utiliser l'authentification flexible qui gère les API Keys
        $authData = ApiKeyAuthMiddleware::authenticateFlexible();
        
        if (!$authData) {
            return null;
        }
        
        // Vérifier qu'une session active existe
        $sessionActive = UserSessionService::hasActiveSession(
            $authData['user_id'], 
            $authData['api_key_id'] ?? null
        );
        
        if (!$sessionActive) {
            LogService::warning('Tentative d\'accès sans session active', [
                'user_id' => $authData['user_id'],
                'api_key_id' => $authData['api_key_id'] ?? null
            ]);
            return null;
        }
        
        // Récupérer les données utilisateur complètes depuis la base de données
        $user = new User();
        $userData = $user->findById($authData['user_id']);
        
        if (!$userData) {
            return null;
        }
        
        // Retourner les données utilisateur avec le rôle
        return [
            'user_id' => $userData['id'],
            'email' => $userData['email'],
            'role' => $userData['role'] ?? 'UTILISATEUR',
            'username' => $userData['username'] ?? $userData['email'],
            'auth_type' => 'api_key'
        ];
    }
    
    /**
     * Extraire le token depuis l'en-tête Authorization (conservé pour compatibilité)
     */
    public static function extractTokenFromHeader(): ?string {
        $authHeader = null;

        // 1. Standard
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        // 2. Apache mod_rewrite
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        // 3. Fallback: apache_request_headers (fonctionne seulement si Apache)
        elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            } elseif (isset($headers['authorization'])) {
                $authHeader = $headers['authorization'];
            }
        }

        if (!$authHeader) {
            return null;
        }

        if (stripos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        return null;
    }
}