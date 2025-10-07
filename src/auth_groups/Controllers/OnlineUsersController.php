<?php

namespace AuthGroups\Controllers;

use AuthGroups\Services\ValidTokenService;
use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur pour les statistiques d'utilisateurs en ligne
 * Utilise ValidTokenService pour obtenir les statistiques des sessions actives
 */
class OnlineUsersController {

    /**
     * Obtenir les statistiques d'utilisateurs en ligne
     * GET /stats/online-users
     */
    public function getOnlineStats($currentUserRole) {
        try {
            LoggingMiddleware::logEntry();

            // Seuls les admins peuvent voir ces statistiques détaillées
            if ($currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Accès refusé aux statistiques d'utilisateurs en ligne", [
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return false;
            }

            $stats = ValidTokenService::getOnlineUsersStats();

            LogService::info("Statistiques d'utilisateurs en ligne récupérées", [
                'users_online' => $stats['users_online']
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Statistiques d\'utilisateurs en ligne récupérées', $stats);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des statistiques", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des statistiques', null, 500);
            return false;
        }
    }

    /**
     * Obtenir la liste des sessions actives
     * GET /stats/active-sessions
     */
    public function getActiveSessions($currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();

            // Les utilisateurs peuvent voir leurs propres sessions
            // Les admins peuvent voir toutes les sessions
            $userId = null;
            if ($currentUserRole !== 'ADMINISTRATEUR') {
                $userId = $currentUserId;
            }

            $sessions = ValidTokenService::getActiveSessions($userId);

            LogService::info("Sessions actives récupérées", [
                'user_id' => $userId,
                'role' => $currentUserRole,
                'sessions_count' => count($sessions)
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Sessions actives récupérées', $sessions);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des sessions actives", [
                'user_id' => $currentUserId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des sessions', null, 500);
            return false;
        }
    }

    /**
     * Supprimer une session spécifique (déconnexion d'un appareil)
     * DELETE /users/{userId}/sessions/{sessionId}
     */
    public function removeSession($sessionId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();

            // TODO: Implémenter la suppression d'une session spécifique
            // Cela nécessiterait de stocker l'ID de session dans la table valid_tokens
            // et de permettre la suppression par cet ID

            LogService::warning("Fonctionnalité de suppression de session non implémentée", [
                'session_id' => $sessionId,
                'current_user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(501);
            Response::error('Fonctionnalité non implémentée', null, 501);
            return false;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression de session", [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la suppression de session', null, 500);
            return false;
        }
    }

    /**
     * Nettoyer les tokens expirés manuellement
     * POST /admin/cleanup-tokens
     */
    public function cleanupExpiredTokens($currentUserRole) {
        try {
            LoggingMiddleware::logEntry();

            // Seuls les admins peuvent déclencher le nettoyage
            if ($currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Accès refusé au nettoyage des tokens", [
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return false;
            }

            $deletedCount = ValidTokenService::cleanupExpiredTokens();

            LogService::info("Nettoyage des tokens expirés déclenché manuellement", [
                'deleted_count' => $deletedCount
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Nettoyage effectué', [
                'tokens_removed' => $deletedCount
            ]);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors du nettoyage des tokens", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors du nettoyage', null, 500);
            return false;
        }
    }

    /**
     * Déconnecter tous les appareils d'un utilisateur
     * POST /users/{userId}/logout-all
     */
    public function logoutAllDevices($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();

            // Un utilisateur peut déconnecter tous ses appareils
            // Un admin peut déconnecter tous les appareils de n'importe qui
            if ($currentUserRole !== 'ADMINISTRATEUR' && $userId != $currentUserId) {
                LogService::warning("Accès refusé pour déconnecter tous les appareils", [
                    'target_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return false;
            }

            $tokensRemoved = ValidTokenService::removeAllUserTokens($userId);

            LogService::info("Déconnexion de tous les appareils", [
                'target_user_id' => $userId,
                'current_user_id' => $currentUserId,
                'tokens_removed' => $tokensRemoved
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Tous les appareils déconnectés', [
                'tokens_removed' => $tokensRemoved
            ]);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la déconnexion de tous les appareils", [
                'target_user_id' => $userId,
                'current_user_id' => $currentUserId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la déconnexion', null, 500);
            return false;
        }
    }
}