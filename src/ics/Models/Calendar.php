<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use ICS\Utils\IcsGenerator;
use ICS\Utils\IcsParser;
use PDO;

class Calendar extends BaseModel
{
    protected $table = 'calendars';
    
    public $id;
    public $userId;
    public $title;
    public $description;
    public $maxMembers;
    public $timezone;
    public $color;
    public $visibility;
    public $shareToken;
    public $createdAt;
    public $updatedAt;
    public $deletedAt;

    public function __construct() {
        parent::__construct();
    }

    public function create():array
    {    
        // Générer un token de partage unique
        $shareToken = bin2hex(random_bytes(32));
        
        // Générer les valeurs CalDAV
        $ctag = md5(uniqid('ctag_', true));
        $syncToken = md5(uniqid('sync_', true));

        $query ="
            INSERT INTO calendars (user_id, title, description, timezone, color, visibility, max_members, share_token, ctag, sync_token)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->getDb()->prepare($query);

        $stmt->execute([
            $this->userId,
            $this->title,
            $this->description ?? null,
            $this->timezone ?? 'America/Montreal',
            $this->color ?? '#3174ad',
            $this->visibility ?? 'private',
            $this->maxMembers ?? 1000,
            $shareToken,
            $ctag,
            $syncToken
        ]);

        $calendarId = $this->getDb()->lastInsertId();

        return [
            'id' => $calendarId,
            'title' => $this->title,
            'share_token' => $shareToken,
            'ics_url' => self::generateIcsUrl($shareToken),
            'ctag' => $ctag,
            'sync_token' => $syncToken
        ];
    }
    
    public function update(): bool
    {
             
        $fields = [];
        $params = [];

        if (isset($this->title)) {
            $fields[] = 'title = ?';
            $params[] = $this->title;
        }
        if (isset($this->description)) {
            $fields[] = 'description = ?';
            $params[] = $this->description;
        }
        if (isset($this->timezone)) {
            $fields[] = 'timezone = ?';
            $params[] = $this->timezone;
        }
        if (isset($this->color)) {
            $fields[] = 'color = ?';
            $params[] = $this->color;
        }
        if (isset($this->visibility)) {
            $fields[] = 'visibility = ?';
            $params[] = $this->visibility;
        }

        if (isset($this->maxMembers)) {
            $fields[] = 'max_members = ?';
            $params[] = $this->maxMembers;
        }

        if (empty($fields)) {
            return false; // Rien à mettre à jour
        }

        $params[] = $this->id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($sql);

        return $stmt->execute($params);
    }

    public function getUserCalendars($userId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT c.*, 
                   COUNT(ce.id) as event_count,
                   CONCAT(?, '/calendar/', c.share_token, '.ics') as ics_url,
                   CASE 
                       WHEN c.user_id = ? THEN 'owner'
                       WHEN c.visibility = 'public' THEN 'public'
                       WHEN cs.permission IS NOT NULL THEN CONCAT('shared_', cs.permission)
                       ELSE NULL
                   END as access_type,
                   cs.permission as share_permission
            FROM calendars c
            LEFT JOIN calendar_events ce ON c.id = ce.calendar_id AND ce.deleted_at IS NULL
            LEFT JOIN calendar_shares cs ON c.id = cs.calendar_id 
                AND cs.shared_with_user_id = ? AND cs.deleted_at IS NULL
            WHERE (c.user_id = ? OR c.visibility = 'public' OR cs.shared_with_user_id = ?) 
                AND c.deleted_at IS NULL
            GROUP BY c.id
            ORDER BY c.user_id = ? DESC, c.created_at DESC
        ");
        
        $stmt->execute([BASE_URL, $userId, $userId, $userId, $userId, $userId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getByShareToken($shareToken): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE share_token = ? AND deleted_at IS NULL
        ");
       
        $stmt->execute([$shareToken]);     
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getById($calendarId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$calendarId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function shareWith($calendarId, $targetUserId, $targetEmail, $permission): array
    {
        // Vérifier si le partage existe déjà
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendar_shares 
            WHERE calendar_id = ? AND shared_with_user_id = ? and deleted_at IS NULL
        ");
        $stmt->execute([$calendarId, $targetUserId]);
        $existingShare = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingShare) {
            // Mettre à jour les permissions si elles ont changé
            if ($existingShare['permission'] !== $permission) {
                $updateStmt = $this->getDb()->prepare("
                    UPDATE calendar_shares 
                    SET permission = ?, email = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$permission, $targetEmail, $existingShare['id']]);
                $existingShare['permission'] = $permission;
                $existingShare['share_with_email'] = $targetEmail;
            }
            return $existingShare;
        }

        // Créer un nouveau partage
        $stmt = $this->getDb()->prepare("
            INSERT INTO calendar_shares (calendar_id, shared_with_user_id, shared_with_email, permission)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([$calendarId, $targetUserId, $targetEmail, $permission]);
        $shareId = $this->getDb()->lastInsertId();

        return [
            'id' => $shareId,
            'calendar_id' => $calendarId,
            'shared_with_user_id' => $targetUserId,
            'shared_with_email' => $targetEmail,
            'permission' => $permission
        ];
    }

    public function getByShareTokenUserId($shareToken, $userId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE share_token = ? AND user_id = ? AND deleted_at IS NULL
        ");

        $stmt->execute([$shareToken, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getEventsForCalendar($calendarId, $startDate = null, $endDate = null): array
    {
       
        
        $sql = "SELECT * FROM calendar_events WHERE calendar_id = ? and deleted_at IS NULL";
        $params = [$calendarId];
        
        if ($startDate && $endDate) {
            $sql .= " AND (start_datetime <= ? AND end_datetime >= ?)";
            $params[] = $endDate;
            $params[] = $startDate;
        }
        
        $sql .= " ORDER BY start_datetime ASC";
        
        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public static function generateIcsContent($calendar, $events): string
    {
        return IcsGenerator::generateCalendar($calendar, $events);
    }
    
    private static function generateIcsUrl($shareToken): string
    {
        return BASE_URL . '/calendar/' . $shareToken . '.ics';
    }

    /**
     * Vérifie si un utilisateur a accès à un calendrier et retourne le niveau de permission
     */
    public function getUserPermissionForCalendar($calendarId, $userId): ?array
    {
        $stmt = $this->getDb()->prepare(
        "SELECT c.*, 
                   CASE 
                       WHEN c.user_id = ? THEN 'owner'
                       WHEN c.visibility = 'public' THEN 'public'
                       WHEN cs.permission IS NOT NULL THEN cs.permission
                       ELSE NULL
                   END as access_level,
                   cs.permission as share_permission
            FROM calendars c
            LEFT JOIN calendar_shares cs ON c.id = cs.calendar_id 
                AND cs.shared_with_user_id = ? AND cs.deleted_at IS NULL
            WHERE c.id = ? AND c.deleted_at IS NULL
                AND (c.user_id = ? OR c.visibility = 'public' OR cs.shared_with_user_id = ?)"
            );
        
        $stmt->execute([$userId, $userId, $calendarId, $userId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Vérifie si un utilisateur est le propriétaire d'un calendrier.
     * $includingDeleted = true pour inclure les calendriers soft-deleted (ex: hard delete).
     */
    public function isOwner($calendarId, $userId, bool $includingDeleted = false): bool
    {
        $sql = "SELECT id FROM {$this->table} WHERE id = ? AND user_id = ?";
        if (!$includingDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }
        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute([$calendarId, $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function isOwnerIncludingDeleted($calendarId, $userId): bool
    {
        return $this->isOwner($calendarId, $userId, true);
    }

    /**
     * Vérifie si un utilisateur peut écrire dans un calendrier
     */
    public function canUserWrite($calendarId, $userId): bool
    {
        $permission = $this->getUserPermissionForCalendar($calendarId, $userId);
        
        if (!$permission) {
            return false;
        }
        
        // Le propriétaire a toujours les droits en écriture
        if ($permission['access_level'] === 'owner') {
            return true;
        }
        
        // Pour les calendriers partagés, vérifier la permission
        return $permission['share_permission'] === 'write';
    }

    /**
     * Trouve un partage de calendrier par user_id ou email
     */
    public function findCalendarShare($calendarId, $userId = null, $email = null): ?array
    {
        if (!$userId && !$email) {
            return null;
        }

        $sql = "SELECT * FROM calendar_shares WHERE calendar_id = ? AND deleted_at IS NULL";
        $params = [$calendarId];

        if ($userId) {
            $sql .= " AND shared_with_user_id = ?";
            $params[] = $userId;
        } else {
            $sql .= " AND shared_with_email = ?";
            $params[] = $email;
        }

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Supprime un partage de calendrier (soft delete)
     */
    public function removeShare($calendarId, $targetUserId = null, $targetEmail = null): bool
    {
        if (!$targetUserId && !$targetEmail) {
            return false;
        }

        $sql = "UPDATE calendar_shares SET deleted_at = NOW(), updated_at = NOW() 
                WHERE calendar_id = ? AND deleted_at IS NULL";
        $params = [$calendarId];

        if ($targetUserId) {
            $sql .= " AND shared_with_user_id = ?";
            $params[] = $targetUserId;
        } else {
            $sql .= " AND shared_with_email = ?";
            $params[] = $targetEmail;
        }

        $stmt = $this->getDb()->prepare($sql);
        return $stmt->execute($params);
    }

    /** récupère les partages d'un calendrier */
    public function getSharesForCalendar($calendarId): array
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM calendar_shares WHERE calendar_id = ? AND deleted_at IS NULL");
        $stmt->execute([$calendarId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }



    /**
     * Vérifie si un utilisateur peut supprimer un partage
     * - Le propriétaire du calendrier peut supprimer n'importe quel partage
     * - Un utilisateur peut supprimer le partage qui le concerne
     */
    public function canUserRemoveShare($calendarId, $currentUserId, $targetUserId = null, $targetEmail = null): bool
    {
        // Récupérer le calendrier
        $calendar = $this->getById($calendarId);
        if (!$calendar) {
            return false;
        }

        // Si l'utilisateur est propriétaire du calendrier, il peut supprimer n'importe quel partage
        if ($calendar['user_id'] == $currentUserId) {
            return true;
        }

        // Si l'utilisateur veut supprimer son propre partage
        if ($targetUserId && $targetUserId == $currentUserId) {
            return true;
        }

        // Pour les partages par email, vérifier si l'utilisateur actuel correspond à l'email
        if ($targetEmail) {
            // Note: Vous pourriez vouloir ajouter une vérification pour s'assurer que 
            // l'email correspond à l'utilisateur actuel dans votre système
            // Pour l'instant, on permet seulement au propriétaire de supprimer les partages par email
            return false;
        }

        return false;
    }

    /**
     * Crée un calendrier complet et ses événements à partir d'un contenu ICS.
     *
     * @param int $userId L'ID de l'utilisateur propriétaire.
     * @param string $icsContent Le contenu du fichier ICS.
     * @return array Les détails du calendrier créé.
     */
    public function createFromIcs(int $userId, string $icsContent): array
    {
        $this->getDb()->beginTransaction();

        try {
            // 1. Parser les informations du calendrier (VCALENDAR)
            $calendarProperties = self::parseIcsCalendarProperties($icsContent);

            // 2. Créer le calendrier dans la base de données
            $this->userId = $userId;
            $this->title = $calendarProperties['X-WR-CALNAME'] ?? 'Calendrier importé';
            $this->description = $calendarProperties['X-WR-CALDESC'] ?? 'Importé depuis un fichier ICS';
            $this->timezone = $calendarProperties['X-WR-TIMEZONE'] ?? 'America/Montreal';
            $this->visibility = 'private';
            $this->color = '#'.substr(md5($this->title), 0, 6); // Couleur pseudo-aléatoire

            $newCalendar = $this->create();
            $calendarId = $newCalendar['id'];

            // 3. Parser et importer les événements (VEVENT)
            $eventModel = new CalendarEvent();
            $importedCount = $eventModel->importEventsFromIcsContent($calendarId, $icsContent, $userId);

            $this->getDb()->commit();

            $newCalendar['imported_events_count'] = $importedCount;
            return $newCalendar;

        } catch (\Exception $e) {
            $this->getDb()->rollBack();
            throw new \Exception("Échec de la création du calendrier depuis ICS: " . $e->getMessage());
        }
    }

    /**
     * Parse les propriétés principales d'un VCALENDAR depuis le contenu ICS.
     */
    private static function parseIcsCalendarProperties(string $icsContent): array
    {
        return IcsParser::parseCalendarProperties($icsContent);
    }

    /**
     * Met à jour un calendrier existant et ses événements depuis un fichier ICS.
     * Les événements sont upsertés par UID : mise à jour si connu, création sinon.
     *
     * @param int    $calendarId  ID du calendrier cible.
     * @param int    $userId      ID de l'utilisateur (doit être propriétaire ou avoir accès en écriture).
     * @param string $icsContent  Contenu du fichier ICS.
     * @return array Calendrier mis à jour + statistiques d'import.
     */
    public function updateFromIcs(int $calendarId, int $userId, string $icsContent): array
    {
        $this->getDb()->beginTransaction();

        try {
            // 1. Vérifier que l'utilisateur a les droits en écriture
            if (!$this->canUserWrite($calendarId, $userId)) {
                throw new \Exception("Accès non autorisé au calendrier.");
            }

            // 2. Parser les propriétés du VCALENDAR et mettre à jour les métadonnées
            $calendarProperties = self::parseIcsCalendarProperties($icsContent);

            $updateFields = [];
            $updateValues = [];

            if (!empty($calendarProperties['X-WR-CALNAME'])) {
                $updateFields[] = 'title = ?';
                $updateValues[] = $calendarProperties['X-WR-CALNAME'];
            }
            if (!empty($calendarProperties['X-WR-CALDESC'])) {
                $updateFields[] = 'description = ?';
                $updateValues[] = $calendarProperties['X-WR-CALDESC'];
            }
            if (!empty($calendarProperties['X-WR-TIMEZONE'])) {
                $updateFields[] = 'timezone = ?';
                $updateValues[] = $calendarProperties['X-WR-TIMEZONE'];
            }

            if (!empty($updateFields)) {
                $updateFields[] = 'updated_at = CURRENT_TIMESTAMP';
                $updateValues[] = $calendarId;
                $stmt = $this->getDb()->prepare(
                    "UPDATE calendars SET " . implode(', ', $updateFields) . " WHERE id = ?"
                );
                $stmt->execute($updateValues);
            }

            // 3. Upsert des événements par UID
            $eventModel = new CalendarEvent();
            $stats = $eventModel->upsertEventsFromIcsContent($calendarId, $icsContent, $userId);

            $this->getDb()->commit();

            $calendar = $this->getById($calendarId);
            $calendar['events_created'] = $stats['created'];
            $calendar['events_updated'] = $stats['updated'];

            return $calendar;

        } catch (\Exception $e) {
            $this->getDb()->rollBack();
            throw new \Exception("Échec de la mise à jour du calendrier depuis ICS: " . $e->getMessage());
        }
    }
}
