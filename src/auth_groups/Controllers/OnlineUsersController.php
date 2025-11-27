<?php

namespace AuthGroups\Controllers;

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

            $stats = self::getOnlineUsersStats();

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

            $sessions = $this->getActiveSessions2($userId);

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
     * Obtenir les statistiques d'utilisateurs en ligne
     */
    public static function getOnlineUsersStats(): array {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->query("SELECT * FROM v_online_users_stats");
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$stats) {
                return [
                    'users_online' => 0,
                    'total_sessions' => 0,
                    'avg_session_duration_minutes' => 0,
                    'active_last_5min' => 0,
                    'active_last_30min' => 0
                ];
            }
            
            return $stats;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des statistiques", [
                'error' => $e->getMessage()
            ]);
            return [
                'users_online' => 0,
                'total_sessions' => 0,
                'avg_session_duration_minutes' => 0,
                'active_last_5min' => 0,
                'active_last_30min' => 0
            ];
        }
    }
    
    /**
     * Obtenir les sessions actives avec détails
     */
    public static function getActiveSessions2(?int $userId = null): array {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $sql = "SELECT * FROM v_active_sessions";
            $params = [];
            
            if ($userId !== null) {
                $sql .= " WHERE user_id = ?";
                $params[] = $userId;
            }
            
            $sql .= " ORDER BY last_used_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des sessions actives", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    
}