<?php

namespace AuthGroups\Controllers;

use AuthGroups\Services\UserSessionService;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur pour la gestion des sessions utilisateurs
 * Remplace OnlineUsersController avec le système simplifié
 */
class UserSessionController {
    
    /**
     * Obtenir les sessions actives d'un utilisateur
     * GET /users/{userId}/sessions
     */
    public function getUserSessions($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Vérifier les permissions
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') && $userId != $currentUserId) {
                LogService::warning("Accès refusé pour consultation des sessions", [
                    'requested_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'current_role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return false;
            }
            
            $sessions = UserSessionService::getUserActiveSessions($userId);
            
            LogService::info("Sessions utilisateur récupérées", [
                'user_id' => $userId,
                'sessions_count' => count($sessions),
                'requested_by' => $currentUserId
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Sessions actives', [
                'sessions' => $sessions,
                'total_sessions' => count($sessions)
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des sessions", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
            return false;
        }
    }
    
    /**
     * Terminer toutes les sessions d'un utilisateur
     * DELETE /users/{userId}/sessions
     */
    public function endAllUserSessions($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Vérifier les permissions
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') && $userId != $currentUserId) {
                LogService::warning("Accès refusé pour terminer les sessions", [
                    'requested_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'current_role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return false;
            }
            
            $sessionsEnded = UserSessionService::endAllUserSessions($userId);
            
            LogService::info("Toutes les sessions utilisateur terminées", [
                'user_id' => $userId,
                'sessions_ended' => $sessionsEnded,
                'terminated_by' => $currentUserId
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Sessions terminées', [
                'sessions_ended' => $sessionsEnded,
                'message' => $sessionsEnded > 0 
                    ? "Toutes les sessions actives ont été terminées"
                    : "Aucune session active trouvée"
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la terminaison des sessions", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
            return false;
        }
    }
    
    /**
     * Obtenir les statistiques des utilisateurs en ligne
     * GET /stats/online
     */
    public function getOnlineStats($currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Seuls les admins peuvent voir ces stats
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR')) {
                LogService::warning("Accès refusé pour les statistiques en ligne", [
                    'current_user_id' => $currentUserId,
                    'current_role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé - Administrateur requis', null, 403);
                return false;
            }
            
            $stats = UserSessionService::getOnlineUsersStats();
            
            LogService::info("Statistiques en ligne récupérées", [
                'requested_by' => $currentUserId,
                'total_active_sessions' => $stats['total_active_sessions'] ?? 0
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Statistiques utilisateurs en ligne', $stats);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des statistiques", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
            return false;
        }
    }
    
    /**
     * Nettoyer les sessions expirées
     * POST /stats/cleanup-sessions
     */
    public function cleanupExpiredSessions($currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Seuls les admins peuvent faire le nettoyage
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR')) {
                LogService::warning("Accès refusé pour le nettoyage des sessions", [
                    'current_user_id' => $currentUserId,
                    'current_role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé - Administrateur requis', null, 403);
                return false;
            }
            
            $cleanedSessions = UserSessionService::cleanupAllExpiredSessions();
            
            LogService::info("Nettoyage des sessions expirées effectué", [
                'cleaned_by' => $currentUserId,
                'sessions_cleaned' => $cleanedSessions
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Nettoyage effectué', [
                'sessions_cleaned' => $cleanedSessions,
                'message' => $cleanedSessions > 0 
                    ? "Sessions expirées nettoyées avec succès"
                    : "Aucune session expirée trouvée"
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors du nettoyage", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
            return false;
        }
    }
    
    /**
     * Vérifier si un utilisateur a une session active
     * GET /users/{userId}/session-status
     */
    public function getSessionStatus($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Vérifier les permissions
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') && $userId != $currentUserId) {
                LogService::warning("Accès refusé pour vérification de session", [
                    'requested_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'current_role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return false;
            }
            
            $hasActiveSession = UserSessionService::hasActiveSession($userId);
            $sessions = UserSessionService::getUserActiveSessions($userId);
            
            LoggingMiddleware::logExit(200);
            Response::success('Statut de session', [
                'user_id' => $userId,
                'has_active_session' => $hasActiveSession,
                'active_sessions_count' => count($sessions),
                'last_activity' => !empty($sessions) ? $sessions[0]['last_activity_at'] : null
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la vérification de session", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
            return false;
        }
    }
}