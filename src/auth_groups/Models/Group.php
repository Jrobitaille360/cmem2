<?php

namespace Memories\Models;

use Memories\Services\EmailService;
use PDO;
use Exception;
use InvalidArgumentException;

/**
 * Modèle Group simplifié utilisant Database::getInstance()
 * Version simplifiée sans injection de dépendance
 */
class Group extends BaseModel
{
    protected $table = 'groups';

    // Propriétés basées sur le nouveau schéma
    public $name;
    public $description; // Nouvelle propriété description
    public $owner_id;
    public $max_members;
    public $visibility;

    /**
     * Créer un nouveau groupe
     */
    public function create()
    {
        $query = "INSERT INTO {$this->table} 
         (name, description, owner_id, max_members, visibility) 
         VALUES (:name, :description, :owner_id, :max_members, :visibility)";

        $stmt = $this->getDb()->prepare($query);

        // Nettoyage des données
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->visibility = $this->normalizeVisibility($this->visibility);
        $this->max_members = $this->max_members ?? 50;

        // Liaison des paramètres
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':owner_id', $this->owner_id);
        $stmt->bindParam(':max_members', $this->max_members);
        $stmt->bindParam(':visibility', $this->visibility);
      

        if ($stmt->execute())
        {
            $this->id = $this->getDb()->lastInsertId();
            return true;
        }

        return false;
    }

    /**
     * Mettre à jour l'enregistrement courant (méthode abstraite de BaseModel)
     */
    public function update()
    {
        if (!$this->id) {
            return false;
        }

        $query = "UPDATE {$this->table} 
                 SET name = :name, description = :description, 
                     max_members = :max_members, visibility = :visibility,
                     updated_at = NOW()
                 WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        
        // Nettoyage des données
        $name = htmlspecialchars(strip_tags($this->name));
        $visibility = $this->normalizeVisibility($this->visibility);

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':max_members', $this->max_members);
        $stmt->bindParam(':visibility', $visibility);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    public function create2($input, $currentUserId)
    {

        try {
            // Démarrer une transaction
            $this->getDb()->beginTransaction();

            // Créer le groupe
            $query = "INSERT INTO {$this->table} 
                     (name, description, owner_id, max_members, visibility) 
                     VALUES (:name, :description, :owner_id, :max_members, :visibility)";

            $stmt = $this->getDb()->prepare($query);

            // Valeurs par défaut
            $name = htmlspecialchars(strip_tags($input['name']));
            $description = $input['description'] ?? '';
            $max_members = $input['max_members'] ?? 50;
            $visibility = $this->normalizeVisibility($input['visibility'] ?? 'private');

            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':owner_id', $currentUserId);
            $stmt->bindParam(':max_members', $max_members);
            $stmt->bindParam(':visibility', $visibility);

            if (!$stmt->execute()) {
                $this->getDb()->rollBack();
                return false;
            }
            $groupId = $this->getDb()->lastInsertId();
            //$this->findById($groupId);
            $this->getDb()->commit();
    
            return $groupId;
        } catch (Exception $e) {
            $this->getDb()->rollBack();
            return false;
        }
    }

    /**
     * Mettre à jour un groupe par ID avec données externes
     */
    public function updateGroup($id, $data)
    {
        $updates = [];
        $params = [':id' => $id];

        foreach (['name', 'description', 'max_members', 'visibility'] as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = :{$field}";
                if ($field === 'name') {
                    $params[":{$field}"] = htmlspecialchars(strip_tags($data[$field]));
                } elseif ($field === 'visibility') {
                    $params[":{$field}"] = $this->normalizeVisibility($data[$field]);
                } else {
                    $params[":{$field}"] = $data[$field];
                }
            }
        }

        if (empty($updates)) {
            return true; // Rien à mettre à jour
        }

        $updates[] = "updated_at = NOW()";
        $query = "UPDATE {$this->table} SET " . implode(', ', $updates) . " 
                 WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        return $stmt->execute($params);
    }

    /**
     * Récupérer les groupes publics avec pagination
     */
    public function getPublicGroups($searchString = '', $limit = 10, $offset = 0)
    {
        $whereClause = "WHERE visibility = 'public' AND deleted_at IS NULL";
        $params = [];

        if (!empty($searchString)) {
            $whereClause .= " AND (name LIKE :search )";
        }

        $query = "SELECT 
                    id, name, description, owner_id, max_members, 
                    visibility, created_at, updated_at,
                    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id AND deleted_at IS NULL) as member_count
                  FROM {$this->table} g 
                  {$whereClause}
                  ORDER BY created_at DESC 
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if (!empty($searchString)) {
            $stmt->bindValue(':search', "%{$searchString}%", PDO::PARAM_STR);
        }
        


        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupérer les groupes d'un utilisateur
     */
    public function getByUserId($userId, $limit = 10, $offset = 0)
    {
        $query = "SELECT 
                    g.id, g.name, g.description, g.owner_id, g.max_members, 
                    g.visibility, g.created_at, g.updated_at,
                    gm.role as user_role,
                    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id AND deleted_at IS NULL) as member_count
                  FROM {$this->table} g
                  INNER JOIN group_members gm ON g.id = gm.group_id
                  WHERE gm.user_id = :user_id 
                    AND g.deleted_at IS NULL 
                    AND gm.deleted_at IS NULL
                  ORDER BY g.updated_at DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rechercher des groupes
     */
    public function search($query, $visibility = 'public', $limit = 10, $offset = 0)
    {
        $whereClause = "WHERE deleted_at IS NULL";
        switch ($visibility) {
            case 'public':
                $whereClause .= " AND visibility = 'public'";
                break;
            case 'private':
                $whereClause .= " AND visibility = 'private'";
                break;
            case 'all':
                // Pas de filtre sur la visibilité
                break;
            default:
                return null;
        }

        $whereClause .= " AND (name LIKE :search )";

        $sql = "SELECT 
                  id, name, description, owner_id, max_members, 
                  visibility, created_at, updated_at,
                  (SELECT COUNT(*) FROM group_members WHERE group_id = g.id AND deleted_at IS NULL) as member_count,
                  (SELECT GROUP_CONCAT(u.name SEPARATOR ', ')
                      FROM group_members gm2
                      INNER JOIN users u ON gm2.user_id = u.id
                      WHERE gm2.group_id = g.id AND gm2.deleted_at IS NULL AND u.deleted_at IS NULL
                  ) as member_names
                FROM {$this->table} g 
                {$whereClause}
                ORDER BY 
                  created_at DESC,
                  member_count DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->getDb()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':search', "%{$query}%");

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifier si un utilisateur est membre d'un groupe
     */
    public function isMember($groupId, $userId)
    {
        $query = "SELECT COUNT(*) as count FROM group_members 
                 WHERE group_id = :group_id AND user_id = :user_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Obtenir le rôle d'un utilisateur dans un groupe
     */
    public function getMemberRole($groupId, $userId)
    {
        $query = "SELECT role FROM group_members 
                 WHERE group_id = :group_id AND user_id = :user_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['role'] : null;
    }

    /**
     * Vérifier si un utilisateur est admin d'un groupe
     */
    public function isGroupAdmin($groupId, $userId)
    {
        return $this->getMemberRole($groupId, $userId) === 'admin';
    }

    /**
     * Ajouter un membre au groupe
     */
    public function addMember($invitationData, $userId)
    {
        //$invitationData['group_id'], $currentUserId,  $invitationData['invited_by'],$invitationData['invited_role']
        $groupId = $invitationData['group_id'];
        $invitedBy = $invitationData['invited_by'];
        $role = $invitationData['invited_role'];

        $role = strtolower($role);
        $allowedRoles = ['admin', 'moderator', 'member'];
        if (!in_array($role, $allowedRoles, true))
        {
            return ['success' => false, 'code' => 'invalid_role'];
        }

        try
        {
            $this->getDb()->beginTransaction();

            $query = "INSERT INTO group_members (group_id, user_id, role, invited_by, joined_at) 
                     VALUES (:group_id, :user_id, :role, :invited_by, NOW())";

            $stmt = $this->getDb()->prepare($query);
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':invited_by', $invitedBy, PDO::PARAM_INT);

            if (!$stmt->execute())
            {
                $this->getDb()->rollBack();
                return ['success' => false, 'code' => 'insert_failed'];
            }

            // Marquer l'invitation comme utilisée
            $updateInvitation = "UPDATE group_invitations 
                               SET status = 'accepted', responded_at = NOW() 
                               WHERE id = :invitation_id";
            $inviteStmt = $this->getDb()->prepare($updateInvitation);
            $inviteStmt->bindParam(':invitation_id', $invitationData['id'], PDO::PARAM_INT);
            $inviteStmt->execute();

            $this->getDb()->commit();
            return ['success' => true];

        } catch (Exception $e)
        {
            $this->getDb()->rollBack();
            return ['success' => false, 'code' => 'database_error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Retirer un membre du groupe
     */
    public function removeMember($groupId, $userId)
    {
        $query = "UPDATE group_members 
                 SET deleted_at = NOW() 
                 WHERE group_id = :group_id AND user_id = :user_id";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Obtenir les membres d'un groupe
     */
    public function getMembers($groupId, $limit = 10, $offset = 0)
    {
        $query = "SELECT 
                    u.id, u.name, u.email, u.profile_image,
                    gm.role, gm.joined_at, gm.invited_by
                  FROM group_members gm
                  INNER JOIN users u ON gm.user_id = u.id
                  WHERE gm.group_id = :group_id 
                    AND gm.deleted_at IS NULL 
                    AND u.deleted_at IS NULL
                  ORDER BY 
                    FIELD(gm.role, 'admin', 'moderator', 'member'),
                    gm.joined_at ASC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Compter le nombre de membres d'un groupe
     */
    public function countMembers($groupId)
    {
        $query = "SELECT COUNT(*) as total FROM group_members 
                 WHERE group_id = :group_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['total'];
    }

    /**
     * Compter le nombre d'administrateurs d'un groupe
     */
    public function countAdmins($groupId)
    {
        $query = "SELECT COUNT(*) as total FROM group_members 
                 WHERE group_id = :group_id AND role = 'admin' AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['total'];
    }

    /**
     * Générer un code d'invitation unique
     */
    private function generateInviteCode()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Inviter un utilisateur dans un groupe
     */
    public function inviteUser($groupId, $email, $inviterId, $role = 'member')
    {
        try
        {
            $this->getDb()->beginTransaction();

            // Générer un code d'invitation unique
            $invitationToken = $this->generateInviteCode();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

            $query = "INSERT INTO group_invitations 
                     (group_id, invited_email, invited_by, invited_role, invitation_token, expires_at, status) 
                     VALUES (:group_id, :email, :inviter_id, :role, :invitation_token, :expires_at, 'pending')";

            $stmt = $this->getDb()->prepare($query);
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':inviter_id', $inviterId, PDO::PARAM_INT);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':invitation_token', $invitationToken);
            $stmt->bindParam(':expires_at', $expiresAt);

            if (!$stmt->execute())
            {
                $this->getDb()->rollBack();
                return ['success' => false, 'error' => 'Failed to create invitation'];
            }

            // Générer l'URL d'invitation
            $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost/cmem1_API';
            $inviteUrl = "{$baseUrl}/groups/join?email={$email}&role={$role}&code={$invitationToken}";

            // Envoyer l'email d'invitation (optionnel)
            try
            {
                $groupData = $this->findById($groupId);
                $inviterData = (new \Memories\Models\User())->findById($inviterId);

                $emailService = new EmailService();
                $emailService->sendGroupInvitation(
                    $email, 
                    $groupData['name'], 
                    $inviterData['name'], 
                    $role, 
                    $inviteUrl
                );
            } catch (Exception $e)
            {
                // L'erreur d'email n'empêche pas l'invitation
                error_log("Email invitation failed: " . $e->getMessage());
            }

            $this->getDb()->commit();

            return [
                'success' => true,
                'invite_url' => $inviteUrl,
                'invitation_token' => $invitationToken,
                'expires_at' => $expiresAt
            ];

        } catch (Exception $e)
        {
            $this->getDb()->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Trouver une invitation par code
     */
    public function findByInviteCode($invitation_token)
    {
        $query = "SELECT gi.*, g.name, g.max_members 
                 FROM group_invitations gi 
                 INNER JOIN {$this->table} g ON gi.group_id = g.id
                 WHERE gi.invitation_token = :invitation_token 
                   AND gi.status = 'pending' 
                   AND gi.expires_at > NOW()
                   AND g.deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':invitation_token', $invitation_token);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir les invitations d'un utilisateur
     */
    public function getUserInvitations($email)
    {
        $query = "SELECT gi.*, g.name as group_name, g.description as group_description,
                         u.name as inviter_name
                 FROM group_invitations gi 
                 INNER JOIN {$this->table} g ON gi.group_id = g.id
                 INNER JOIN users u ON gi.invited_by = u.id
                 WHERE gi.invited_email = :email 
                   AND gi.status = 'pending' 
                   AND gi.expires_at > NOW()
                   AND g.deleted_at IS NULL
                 ORDER BY gi.created_at DESC";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mettre à jour le rôle d'un utilisateur dans un groupe
     */
    public function updateUserRole($groupId, $userId, $newRole, $updatedBy)
    {
        $allowedRoles = ['admin', 'moderator', 'member'];
        if (!in_array($newRole, $allowedRoles, true))
        {
            return false;
        }

        $query = "UPDATE group_members 
                 SET role = :role, updated_at = NOW() 
                 WHERE group_id = :group_id AND user_id = :user_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':role', $newRole);
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Normaliser la visibilité selon l'énumération
     */
    private function normalizeVisibility($visibility)
    {
        $visibility = strtolower($visibility ?? 'private');
        $allowed = ['private', 'shared', 'public'];
        return in_array($visibility, $allowed, true) ? $visibility : 'private';
    }

    /** @var string|null Dernier code d'erreur interne */
    public $lastErrorCode = null;
}
    