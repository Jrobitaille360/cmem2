<?php

namespace AuthGroups\Services;

use AuthGroups\Services\LogService;
use Exception;
use PDOException;

/**
 * Service simplifié pour gérer les sessions utilisateurs avec API Keys
 * Remplace ValidTokenService et la complexité JWT
 */
class UserSessionService 
{
    /**
     * Créer une nouvelle session lors du login avec API Key
     */
    public static function createSession(int $userId, int $apiKeyId, ?string $userAgent = null, ?string $ipAddress = null, int $durationHours = 24): ?int {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            // Nettoyer les sessions expirées de l'utilisateur
            self::cleanupUserExpiredSessions($userId);
            
            // Obtenir l'IP et User-Agent si non fournis
            if ($ipAddress === null) {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
            }
            if ($userAgent === null) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            }
            
            // Calculer l'expiration
            $expiresAt = date('Y-m-d H:i:s', time() + ($durationHours * 3600));
            
            $stmt = $pdo->prepare("
                INSERT INTO user_sessions 
                (user_id, api_key_id, expires_at, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $userId,
                $apiKeyId,
                $expiresAt,
                $ipAddress,
                $userAgent
            ]);
            
            if ($result) {
                $sessionId = $pdo->lastInsertId();
                
                LogService::info("Session utilisateur créée", [
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'api_key_id' => $apiKeyId,
                    'ip_address' => $ipAddress,
                    'expires_at' => $expiresAt
                ]);
                
                return $sessionId;
            }
            
            return null;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la création de session", [
                'user_id' => $userId,
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'sql_state' => $e instanceof PDOException ? $e->errorInfo[0] ?? 'unknown' : 'not_pdo'
            ]);
            return null;
        }
    }
    
    /**
     * Mettre à jour l'activité d'une session
     */
    public static function updateActivity(int $userId, int $apiKeyId): bool {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("
                UPDATE user_sessions 
                SET last_activity_at = CURRENT_TIMESTAMP 
                WHERE user_id = ? 
                  AND api_key_id = ? 
                  AND is_active = 1 
                  AND expires_at > NOW()
                ORDER BY login_at DESC 
                LIMIT 1
            ");
            
            return $stmt->execute([$userId, $apiKeyId]);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour d'activité", [
                'user_id' => $userId,
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Terminer une session (logout)
     */
    public static function endSession(int $userId, ?int $apiKeyId = null): int {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            if ($apiKeyId) {
                // Terminer la session spécifique pour cette API Key
                $stmt = $pdo->prepare("
                    UPDATE user_sessions 
                    SET is_active = 0, logout_at = CURRENT_TIMESTAMP
                    WHERE user_id = ? 
                      AND api_key_id = ? 
                      AND is_active = 1
                ");
                $stmt->execute([$userId, $apiKeyId]);
            } else {
                // Terminer toutes les sessions actives de l'utilisateur
                $stmt = $pdo->prepare("
                    UPDATE user_sessions 
                    SET is_active = 0, logout_at = CURRENT_TIMESTAMP
                    WHERE user_id = ? 
                      AND is_active = 1
                ");
                $stmt->execute([$userId]);
            }
            
            $endedSessions = $stmt->rowCount();
            
            LogService::info("Sessions terminées", [
                'user_id' => $userId,
                'api_key_id' => $apiKeyId,
                'sessions_ended' => $endedSessions
            ]);
            
            return $endedSessions;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la fin de session", [
                'user_id' => $userId,
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    public static function getSessionByApiKey(int $userId): ?array {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("
                SELECT * FROM user_sessions 
                WHERE user_id = ? 
                  AND is_active = 1 
                  AND expires_at > NOW()
                ORDER BY login_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $session ?: null;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération de session", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Vérifier si un utilisateur a une session active
     */
    public static function hasActiveSession(int $userId, ?int $apiKeyId = null): bool {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $sql = "
                SELECT COUNT(*) as count 
                FROM user_sessions 
                WHERE user_id = ? 
                  AND is_active = 1 
                  AND expires_at > NOW()
            ";
            $params = [$userId];
            
            if ($apiKeyId) {
                $sql .= " AND api_key_id = ?";
                $params[] = $apiKeyId;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la vérification de session", [
                'user_id' => $userId,
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Obtenir les sessions actives d'un utilisateur
     */
    public static function getUserActiveSessions(int $userId): array {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("
                SELECT * FROM active_user_sessions 
                WHERE user_id = ? 
                ORDER BY last_activity_at DESC
            ");
            $stmt->execute([$userId]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des sessions", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Obtenir les statistiques d'utilisateurs en ligne
     */
    public static function getOnlineUsersStats(): array {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->query("SELECT * FROM user_sessions_stats");
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$stats) {
                return [
                    'total_active_sessions' => 0,
                    'unique_users_online' => 0,
                    'avg_session_duration_minutes' => 0,
                    'active_last_5min' => 0,
                    'active_last_30min' => 0,
                    'sessions_today' => 0
                ];
            }
            
            return $stats;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des stats", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Nettoyer les sessions expirées d'un utilisateur
     */
    public static function cleanupUserExpiredSessions(int $userId): int {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->prepare("
                UPDATE user_sessions 
                SET is_active = 0, logout_at = CURRENT_TIMESTAMP
                WHERE user_id = ? 
                  AND is_active = 1 
                  AND expires_at < NOW()
            ");
            $stmt->execute([$userId]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            LogService::error("Erreur lors du nettoyage des sessions", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Nettoyer toutes les sessions expirées
     */
    public static function cleanupAllExpiredSessions(): int {
        try {
            $pdo = \Database::getInstance()->getConnection();
            
            $stmt = $pdo->query("CALL CleanupExpiredSessions()");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $result['cleaned_sessions'] ?? 0;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors du nettoyage global", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Terminer toutes les sessions d'un utilisateur (déconnexion globale)
     */
    public static function endAllUserSessions(int $userId): int {
        return self::endSession($userId, null);
    }
}