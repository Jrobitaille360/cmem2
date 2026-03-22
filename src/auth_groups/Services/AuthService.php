<?php

namespace AuthGroups\Services;

use AuthGroups\Models\User;
use AuthGroups\Middleware\JwtAuthMiddleware;

/**
 * Service d'authentification — JWT.
 *
 * Valide le token JWT de la requête et retourne les données utilisateur
 * prêtes à être injectées dans $request['user'] par BaseRouteHandler.
 */
class AuthService
{
    /**
     * Authentifie la requête courante via JWT.
     *
     * @return array|null  ['user_id', 'email', 'role', 'name', 'auth_type'] ou null
     */
    public function authenticate(): ?array
    {
        if (!JwtAuthMiddleware::hasToken()) {
            return null;
        }

        $authData = JwtAuthMiddleware::authenticate();

        if (!$authData) {
            return null; // Réponse d'erreur déjà envoyée par le middleware
        }

        // Vérifier que l'utilisateur existe toujours en base (non supprimé)
        $userModel = new User();
        $userData  = $userModel->findById($authData['user_id']);

        if (!$userData || !empty($userData['deleted_at'])) {
            LogService::warning('JWT valide mais utilisateur introuvable ou supprimé', [
                'user_id' => $authData['user_id'],
            ]);
            return null;
        }

        return [
            'user_id'   => (int) $userData['id'],
            'email'     => $userData['email'],
            'role'      => $userData['role'] ?? 'UTILISATEUR',
            'name'      => $userData['name'] ?? '',
            'auth_type' => 'jwt',
        ];
    }
}
