<?php

namespace AuthGroups\Services;

use PDO;
use Exception;

/**
 * Service pour gérer les codes d'invitation et les emails
 */
class InvitationService {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Générer un code d'invitation unique
     */
    public function generateInviteCode($length = 8) {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }
        } while ($this->isInviteCodeExists($code));
        
        return $code;
    }
    
    /**
     * Vérifier si un code d'invitation existe déjà
     */
    private function isInviteCodeExists($code) {
        $query = "SELECT COUNT(*) as count FROM groups WHERE invite_code = :code";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':code', $code);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    /**
     * Créer une invitation pour un groupe
     */
    public function createGroupInvitation($groupId, $email, $invitedBy, $role = 'member') {
        try {
            // Générer un token unique
            $token = $this->generateUniqueToken();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days')); // Expire dans 7 jours
            
            $query = "INSERT INTO group_invitations 
                     (group_id, email, token, invited_by, role, expires_at, created_at) 
                     VALUES (:group_id, :email, :token, :invited_by, :role, :expires_at, NOW())";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':group_id', $groupId);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':token', $token);
            $stmt->bindParam(':invited_by', $invitedBy);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':expires_at', $expiresAt);
            
            if ($stmt->execute()) {
                return $token;
            }
            
            return false;
        } catch (Exception $e) {
            error_log('Erreur createGroupInvitation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Générer un token unique pour les invitations
     */
    private function generateUniqueToken($length = 32) {
        do {
            $token = bin2hex(random_bytes($length / 2));
        } while ($this->isTokenExists($token));
        
        return $token;
    }
    
    /**
     * Vérifier si un token existe déjà
     */
    private function isTokenExists($token) {
        $query = "SELECT COUNT(*) as count FROM group_invitations WHERE token = :token";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    /**
     * Valider une invitation par token
     */
    public function validateInvitation($token) {
        $query = "SELECT gi.*, g.name as group_name, g.owner_id, u.username as invited_by_username
                 FROM group_invitations gi 
                 INNER JOIN groups g ON gi.group_id = g.id 
                 INNER JOIN users u ON gi.invited_by = u.id 
                 WHERE gi.token = :token 
                 AND gi.expires_at > NOW() 
                 AND gi.used_at IS NULL 
                 AND g.deleted_at IS NULL";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Marquer une invitation comme utilisée
     */
    public function markInvitationAsUsed($token, $userId) {
        $query = "UPDATE group_invitations 
                 SET used_at = NOW(), used_by = :user_id 
                 WHERE token = :token";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':user_id', $userId);
        
        return $stmt->execute();
    }
    
    /**
     * Obtenir les invitations en attente pour un groupe
     */
    public function getPendingInvitations($groupId) {
        $query = "SELECT gi.*, u.username as invited_by_username 
                 FROM group_invitations gi 
                 INNER JOIN users u ON gi.invited_by = u.id 
                 WHERE gi.group_id = :group_id 
                 AND gi.used_at IS NULL 
                 AND gi.expires_at > NOW() 
                 ORDER BY gi.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':group_id', $groupId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Supprimer/invalider une invitation
     */
    public function cancelInvitation($token) {
        $query = "UPDATE group_invitations 
                 SET expires_at = NOW() 
                 WHERE token = :token";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':token', $token);
        
        return $stmt->execute();
    }
    
    /**
     * Nettoyer les invitations expirées (à appeler périodiquement)
     */
    public function cleanupExpiredInvitations() {
        $query = "DELETE FROM group_invitations 
                 WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute();
    }
    
    /**
     * Envoyer un email d'invitation
     * Note: Utilise EmailService pour les templates avancés ou mail() pour simple envoi
     */
    public function sendInvitationEmail($email, $groupName, $inviterName, $token) {
        // Méthode simple d'envoi d'email, pour templates avancés utiliser EmailService
        
        $inviteUrl = $_ENV['APP_URL'] . "/invite/accept?token=" . $token;
        
        $subject = "Invitation à rejoindre le groupe: " . $groupName;
        $message = "
        <html>
        <head>
            <title>Invitation au groupe</title>
        </head>
        <body>
            <h2>Vous êtes invité(e) à rejoindre un groupe !</h2>
            <p><strong>{$inviterName}</strong> vous invite à rejoindre le groupe <strong>{$groupName}</strong>.</p>
            <p>
                <a href='{$inviteUrl}' style='background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                    Accepter l'invitation
                </a>
            </p>
            <p>Ou copiez ce lien dans votre navigateur :</p>
            <p>{$inviteUrl}</p>
            <hr>
            <p><small>Cette invitation expire dans 7 jours.</small></p>
        </body>
        </html>
        ";
        
        // Headers pour HTML
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . ($_ENV['MAIL_FROM'] ?? 'noreply@authgroups.local'),
            'Reply-To: ' . ($_ENV['MAIL_REPLY_TO'] ?? 'support@authgroups.local'),
        ];
        
        // Pour l'instant, log l'email (en développement)
        if ($_ENV['APP_ENV'] === 'development') {
            error_log("EMAIL D'INVITATION:\nTo: {$email}\nSubject: {$subject}\nBody: {$message}");
            return true;
        }
        
        // En production, utiliser mail() ou un service externe
        return mail($email, $subject, $message, implode("\r\n", $headers));
    }
    
    /**
     * Statistiques des invitations pour un groupe
     */
    public function getInvitationStats($groupId) {
        $query = "SELECT 
                    COUNT(*) as total_invitations,
                    COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_invitations,
                    COUNT(CASE WHEN expires_at > NOW() AND used_at IS NULL THEN 1 END) as pending_invitations,
                    COUNT(CASE WHEN expires_at <= NOW() AND used_at IS NULL THEN 1 END) as expired_invitations
                 FROM group_invitations 
                 WHERE group_id = :group_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':group_id', $groupId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Méthode publique pour tester la génération de token (développement)
     */
    public function testGenerateToken() {
        return $this->generateUniqueToken();
    }
}
