<?php

namespace AuthGroups\Services;

use AuthGroups\Models\User;
use AuthGroups\Services\LogService;
use AuthGroups\Services\ValidTokenService;
use Exception;

class AuthService 
{
    /**
     * Authentifier l'utilisateur à partir du header Authorization (JWT)
     * @return array|null Données utilisateur ou null si non authentifié
     */
    public function authenticate(): ?array {
        $token = self::extractTokenFromHeader();
        if (!$token) {
            return null;
        }
        return self::validateToken($token);
    }

    /**
     * Valider un token JWT et retourner les données utilisateur
     */
    public static function validateToken(string $token): ?array {
        try {
            if (empty($token) || strlen($token) < 10) {
                return null;
            }
            
            // 1. Vérifier que le token est dans la table des tokens valides
            if (!ValidTokenService::isTokenValid($token)) {
                LogService::warning('Token non trouvé dans les tokens valides ou expiré');
                return null;
            }
            
            // 2. Décoder le token JWT (garde la logique existante)
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            
            // Décoder le payload
            $payload = base64_decode($parts[1]);
            $data = json_decode($payload, true);
            
            if (!$data || !isset($data['user_id'])) {
                return null;
            }
            
            // 3. Vérifier que l'utilisateur existe encore
            $user = new User();
            $userData = $user->findById($data['user_id']);
            
            if (!$userData) {
                // Si l'utilisateur n'existe plus, supprimer le token
                ValidTokenService::removeToken($token);
                return null;
            }
            
            // Retourner les données utilisateur avec le rôle
            return [
                'user_id' => $userData['id'],
                'email' => $userData['email'],
                'role' => $userData['role'] ?? 'UTILISATEUR',
                'username' => $userData['username'] ?? $userData['email']
            ];
            
        } catch (Exception $e) {
            LogService::error('Erreur lors de la validation du token', [
                'error' => $e->getMessage(),
                'token_length' => strlen($token)
            ]);
            return null;
        }
    }
    
    /**
     * Extraire le token depuis l'en-tête Authorization
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
    
    /**
     * Générer un token JWT pour un utilisateur
     */
    public static function generateToken(array $userData): string {
        try {
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                'user_id' => $userData['id'],
                'email' => $userData['email'],
                'role' => $userData['role'] ?? 'UTILISATEUR',
                'exp' => time() + (24 * 60 * 60) // 24 heures
            ]);
            
            $headerEncoded = base64_encode($header);
            $payloadEncoded = base64_encode($payload);
            
            // Signature simplifiée (en production, utiliser une vraie signature HMAC)
            $signature = base64_encode(hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, 'secret_key', true));
            
            return $headerEncoded . '.' . $payloadEncoded . '.' . $signature;
            
        } catch (Exception $e) {
            LogService::error('Erreur lors de la génération du token', [
                'error' => $e->getMessage(),
                'user_id' => $userData['id'] ?? 'unknown'
            ]);
            throw $e;
        }
    }
}