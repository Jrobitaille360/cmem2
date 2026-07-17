<?php

namespace AuthGroups\Controllers;

use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use AuthGroups\Services\LogService;
use AuthGroups\Services\ValidTokenService;
use PDO;
use Exception;

/**
 * StatsController simplifié - API v2
 * Contrôleur simplifié pour la gestion des statistiques
 * Architecture sans injection PDO, utilise Database::getInstance()
 */
class StatsController
{
    public function __construct() {
        // Architecture simplifiée - pas d'injection de dépendances
    }

    /**
     * Générer toutes les statistiques de la plateforme (admin uniquement)
     */
    public function buildStats(int $userId, string $role): void {
        try {
            LogService::info('Tentative de génération des statistiques', [
                'user_id' => $userId,
                'role' => $role
            ]);
            
            // Vérification des permissions admin
            if (!RoleHelper::isAtLeast($role, 'ADMINISTRATEUR')) {
                LogService::warning('Tentative d\'accès non autorisée aux statistiques', [
                    'user_id' => $userId,
                    'role' => $role
                ]);
                Response::error('Accès non autorisé', null, 403);
                return;
            }

            $db = \Database::getInstance()->getConnection();
            
            // Générer toutes les statistiques via la procédure stockée
            $stmt = $db->query("CALL GeneratePlatformStats()");
            $stmt = $db->query("CALL GenerateUserStats()");
            $stmt = $db->query("CALL GenerateGroupStats()");
            
            // Nettoyer les anciennes statistiques (garde les 30 derniers jours)
            $stmt = $db->query("CALL CleanupOldStats()");

            LogService::info('Statistiques générées avec succès', [
                'admin_id' => $userId,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            Response::success('Statistiques générées avec succès', [
                'generated_at' => date('Y-m-d H:i:s'),
                'note' => 'Les doublons sont automatiquement évités par la procédure stockée'
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la génération des statistiques', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Response::error('Erreur lors de la génération des statistiques', null, 500);
        }
    }

    /**
     * Obtenir les statistiques globales de la plateforme (admin uniquement)
     */
    public function getPlatformStats(string $role): void {
        try {
            // Vérification des permissions admin
            if (!RoleHelper::isAtLeast($role, 'ADMINISTRATEUR')) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }

            LogService::info('Récupération des statistiques globales');

            $db = \Database::getInstance()->getConnection();

            // Récupérer le dashboard admin
            $stmt = $db->query("SELECT * FROM v_admin_dashboard");
            $dashboard = $stmt->fetch(PDO::FETCH_ASSOC);

            // Récupérer les dernières statistiques
            $stmt = $db->query("
                SELECT * FROM platform_stats 
                ORDER BY generated_at DESC 
                LIMIT 1
            ");
            $latestStats = $stmt->fetch(PDO::FETCH_ASSOC);

            Response::success('Statistiques globales récupérées', [
                'dashboard' => $dashboard,
                'latest_snapshot' => $latestStats,
                'last_generated' => $latestStats['generated_at'] ?? null
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des statistiques globales', [
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des statistiques', null, 500);
        }
    }

    /**
     * Obtenir les statistiques des groupes (admin uniquement)
     */
    public function getGroupsStats(string $role): void {
        try {
            // Vérification des permissions admin
            if (!RoleHelper::isAtLeast($role, 'ADMINISTRATEUR')) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }

            LogService::info('Récupération des statistiques des groupes');

            $params = Response::getRequestParams();
            $limit = $params['limit'] ?? 50;
            $offset = $params['offset'] ?? 0;

            $db = \Database::getInstance()->getConnection();

            $stmt = $db->prepare("
                SELECT * FROM group_stats_snapshot 
                WHERE generated_at = (
                    SELECT MAX(generated_at) FROM group_stats_snapshot
                )
                ORDER BY storage_mb DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $groupStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('Statistiques des groupes récupérées', [
                'groups' => $groupStats,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($groupStats)
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des statistiques de groupe', [
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des statistiques', null, 500);
        }
    }

    /**
     * Obtenir les statistiques des utilisateurs (admin uniquement)
     */
    public function getUsersStats(string $role): void {
        try {
            // Vérification des permissions admin
            if (!RoleHelper::isAtLeast($role, 'ADMINISTRATEUR')) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }

            LogService::info('Récupération des statistiques des utilisateurs');

            $params = Response::getRequestParams();
            $limit = $params['limit'] ?? 50;
            $offset = $params['offset'] ?? 0;

            $db = \Database::getInstance()->getConnection();

            $stmt = $db->prepare("
                SELECT * FROM user_stats_snapshot 
                WHERE generated_at = (
                    SELECT MAX(generated_at) FROM user_stats_snapshot
                )
                ORDER BY storage_used_mb DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $userStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('Statistiques des utilisateurs récupérées', [
                'users' => $userStats,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($userStats)
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des statistiques utilisateur', [
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des statistiques', null, 500);
        }
    }

    /**
     * Obtenir ses propres statistiques (tout utilisateur authentifié)
     */
    public function getMyStats(int $userId): void {
        try {
            LogService::info('Récupération de ses propres statistiques', ['user_id' => $userId]);

            $db = \Database::getInstance()->getConnection();

            $stmt = $db->prepare("
                SELECT * FROM user_stats_snapshot
                WHERE user_id = :user_id
                ORDER BY generated_at DESC
                LIMIT 1
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $userStats = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userStats) {
                Response::error('Aucune statistique trouvée', null, 404);
                return;
            }

            Response::success('Statistiques utilisateur récupérées', $userStats);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des statistiques', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des statistiques', null, 500);
        }
    }

    /**
     * Obtenir les statistiques d'un utilisateur spécifique
     */
    public function getUserStats(int $targetUserId, int $requestingUserId, string $role): void {
        try {
            // Vérification des permissions (admin uniquement)
            if (!RoleHelper::isAtLeast($role, 'ADMINISTRATEUR')) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }

            LogService::info('Récupération des statistiques d\'un utilisateur', [
                'target_user' => $targetUserId,
                'requesting_user' => $requestingUserId
            ]);

            $db = \Database::getInstance()->getConnection();

            // Récupérer les statistiques de l'utilisateur
            $stmt = $db->prepare("
                SELECT * FROM user_stats_snapshot 
                WHERE user_id = :user_id1 
                AND generated_at = (
                    SELECT MAX(generated_at) FROM user_stats_snapshot WHERE user_id = :user_id2
                )
                LIMIT 1
            ");
            $stmt->bindValue(':user_id1', $targetUserId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id2', $targetUserId, PDO::PARAM_INT);
            $stmt->execute();
            
            $userStats = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userStats) {
                LogService::info('Aucune statistique trouvée, génération de statistiques temporaires', [
                    'target_user' => $targetUserId
                ]);
                Response::error('Aucune statistique trouvée pour cet utilisateur', null, 404);
            }

            Response::success('Statistiques utilisateur récupérées', $userStats);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des statistiques utilisateur', [
                'target_user' => $targetUserId,
                'requesting_user' => $requestingUserId,
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération des statistiques', null, 500);
        }
    }

}
