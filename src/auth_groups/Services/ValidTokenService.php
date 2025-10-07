<?php

namespace AuthGroups\Services;

use AuthGroups\Services\LogService;
use Exception;

/**
 * Service pour gérer les tokens JWT valides (sessions actives)
 * Permet de :
 * - Enregistrer les tokens valides lors du login
 * - Supprimer les tokens lors du logout
 * - Valider l'existence d'un token
 * - Obtenir les statistiques d'utilisateurs en ligne
 * - Gérer les sessions actives
 */
class ValidTokenService 
{
    /**
     * Enregistrer un token valide lors du login
     */
    public static function registerToken(string $token, int $userId, ?string $userAgent = null, ?string $ipAddress = null): bool {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            // Hasher le token pour sécurité (SHA256)
            $tokenHash = hash('sha256', $token);
            
            // Décoder le token pour obtenir l'expiration
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }
            
            $payload = base64_decode($parts[1]);
            $data = json_decode($payload, true);
            
            if (!$data || !isset($data['exp'])) {
                return false;
            }
            
            $expiresAt = date('Y-m-d H:i:s', $data['exp']);
            
            // Obtenir l'IP et User-Agent si non fournis
            if ($ipAddress === null) {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
            }
            if ($userAgent === null) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO valid_tokens 
                (token_hash, user_id, user_agent, ip_address, expires_at) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                last_used_at = CURRENT_TIMESTAMP,
                user_agent = VALUES(user_agent),
                ip_address = VALUES(ip_address)
            ");
            
            $result = $stmt->execute([
                $tokenHash,
                $userId,
                $userAgent,
                $ipAddress,
                $expiresAt
            ]);
            
            if ($result) {
                LogService::info("Token valide enregistré", [
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                    'expires_at' => $expiresAt
                ]);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de l'enregistrement du token", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Vérifier si un token est valide (existe dans la table)
     */
    public static function isTokenValid(string $token): bool {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            // Hasher le token pour la comparaison
            $tokenHash = hash('sha256', $token);
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM valid_tokens 
                WHERE token_hash = ? AND expires_at > NOW()
            ");
            
            $stmt->execute([$tokenHash]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Mettre à jour last_used_at si le token est valide
            if ($result && $result['count'] > 0) {
                self::updateLastUsed($tokenHash);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la vérification du token", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Supprimer un token spécifique (logout)
     */
    public static function removeToken(string $token): bool {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            // Hasher le token
            $tokenHash = hash('sha256', $token);
            
            $stmt = $pdo->prepare("DELETE FROM valid_tokens WHERE token_hash = ?");
            $result = $stmt->execute([$tokenHash]);
            
            $deletedCount = $stmt->rowCount();
            
            LogService::info("Token supprimé", [
                'deleted_count' => $deletedCount
            ]);
            
            return $result && $deletedCount > 0;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression du token", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Supprimer tous les tokens d'un utilisateur (logout complet)
     */
    public static function removeAllUserTokens(int $userId): int {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("DELETE FROM valid_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            $deletedCount = $stmt->rowCount();
            
            LogService::info("Tous les tokens utilisateur supprimés", [
                'user_id' => $userId,
                'deleted_count' => $deletedCount
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression des tokens utilisateur", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
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
    public static function getActiveSessions(?int $userId = null): array {
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
    
    /**
     * Nettoyer les tokens expirés
     */
    public static function cleanupExpiredTokens(): int {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("DELETE FROM valid_tokens WHERE expires_at < NOW()");
            $stmt->execute();
            
            $deletedCount = $stmt->rowCount();
            
            LogService::info("Nettoyage des tokens expirés", [
                'deleted_count' => $deletedCount
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors du nettoyage des tokens", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Mettre à jour la dernière utilisation d'un token
     */
    private static function updateLastUsed(string $tokenHash): bool {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("
                UPDATE valid_tokens 
                SET last_used_at = CURRENT_TIMESTAMP 
                WHERE token_hash = ?
            ");
            
            return $stmt->execute([$tokenHash]);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour last_used_at", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}