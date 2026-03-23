<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use AuthGroups\Services\LogService;
use PDO;

/**
 * Modèle pour la queue des notifications email planifiées.
 */
class EmailNotificationQueue extends BaseModel
{
    protected $table = 'email_notification_queue';

    public $id;
    public $userId;
    public $eventId;
    public $calendarId;
    public $occurrenceKey;
    public $fireAt;
    public $minutesBefore;
    public $recipientEmail;
    public $status;
    public $sentAt;
    public $attemptCount;
    public $error;

    public function __construct()
    {
        parent::__construct();
    }

    // ------------------------------------------------------------------
    // Implémentation des méthodes abstraites de BaseModel
    // ------------------------------------------------------------------

    /**
     * Crée une entrée dans la queue (alias de schedule()).
     */
    public function create(): int
    {
        return $this->schedule();
    }

    /**
     * Met à jour le statut et les champs d'une entrée existante.
     */
    public function update(): bool
    {
        if (!$this->id) {
            return false;
        }
        $stmt = $this->getDb()->prepare(
            "UPDATE email_notification_queue
             SET status        = ?,
                 sent_at       = ?,
                 attempt_count = ?,
                 error         = ?,
                 updated_at    = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([
            $this->status,
            $this->sentAt,
            $this->attemptCount ?? 0,
            $this->error,
            $this->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    /**
     * Insère une nouvelle entrée dans la queue.
     */
    public function schedule(): int
    {
        $sql = "INSERT INTO email_notification_queue
                    (user_id, event_id, calendar_id, occurrence_key, fire_at,
                     minutes_before, recipient_email, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute([
            $this->userId,
            $this->eventId,
            $this->calendarId,
            $this->occurrenceKey,
            $this->fireAt,
            $this->minutesBefore,
            $this->recipientEmail,
        ]);

        return (int)$this->getDb()->lastInsertId();
    }

    /**
     * Annule toutes les notifications en attente pour un événement donné.
     * Retourne le nombre de lignes affectées.
     */
    public static function cancelPendingForEvent(int $eventId): int
    {
        $db = self::getStaticDb();
        $stmt = $db->prepare(
            "UPDATE email_notification_queue
             SET status = 'cancelled', updated_at = NOW()
             WHERE event_id = ? AND status = 'pending'"
        );
        $stmt->execute([$eventId]);
        return $stmt->rowCount();
    }

    /**
     * Annule une notification spécifique si elle est encore en attente.
     * Retourne false si introuvable ou déjà envoyée.
     */
    public static function cancelOne(int $notificationId, int $userId): array
    {
        $db = self::getStaticDb();

        $row = $db->prepare(
            "SELECT * FROM email_notification_queue WHERE id = ? LIMIT 1"
        );
        $row->execute([$notificationId]);
        $notif = $row->fetch(PDO::FETCH_ASSOC);

        if (!$notif) {
            return ['success' => false, 'reason' => 'not_found'];
        }
        if ((int)$notif['user_id'] !== $userId) {
            return ['success' => false, 'reason' => 'forbidden'];
        }
        if ($notif['status'] === 'sent') {
            return ['success' => false, 'reason' => 'already_sent'];
        }
        if ($notif['status'] === 'cancelled') {
            return ['success' => false, 'reason' => 'not_found'];
        }

        $upd = $db->prepare(
            "UPDATE email_notification_queue
             SET status = 'cancelled', updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([$notificationId]);

        return ['success' => true];
    }

    /**
     * Marque une notification comme envoyée.
     */
    public static function markSent(int $id): void
    {
        $db = self::getStaticDb();
        $db->prepare(
            "UPDATE email_notification_queue
             SET status = 'sent', sent_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$id]);
    }

    /**
     * Incrémente le compteur d'essais et enregistre l'erreur.
     * Si attempt_count atteint 3, passe en 'failed'.
     */
    public static function markAttemptFailed(int $id, string $errorMsg): void
    {
        $db = self::getStaticDb();
        $db->prepare(
            "UPDATE email_notification_queue
             SET attempt_count  = attempt_count + 1,
                 error          = ?,
                 status         = IF(attempt_count + 1 >= 3, 'failed', 'pending'),
                 updated_at     = NOW()
             WHERE id = ?"
        )->execute([$errorMsg, $id]);
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Retourne les notifications à envoyer maintenant par le cron.
     * Règle R1 : fire_at passé depuis > 24 h → ne pas envoyer.
     * Règle R2 : retry max 3 fois.
     */
    public static function getDueNotifications(int $limit = 100): array
    {
        $db = self::getStaticDb();
        $stmt = $db->prepare(
            "SELECT n.*, u.email_notifications_enabled
             FROM email_notification_queue n
             JOIN users u ON u.id = n.user_id
             WHERE n.status = 'pending'
               AND n.fire_at <= NOW()
               AND n.fire_at >= NOW() - INTERVAL 24 HOUR
               AND n.attempt_count < 3
             ORDER BY n.fire_at ASC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liste les notifications d'un utilisateur avec filtres.
     */
    public static function listForUser(
        int $userId,
        string $status = 'pending',
        ?string $from = null,
        ?string $to = null,
        string $sort = 'fire_at_asc',
        int $limit = 200
    ): array {
        $db = self::getStaticDb();

        $where  = ['n.user_id = ?'];
        $params = [$userId];

        if ($status !== 'all') {
            $where[]  = 'n.status = ?';
            $params[] = $status;
        }
        if ($from) {
            $where[]  = 'n.fire_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to) {
            $where[]  = 'n.fire_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $orderDir = ($sort === 'fire_at_desc') ? 'DESC' : 'ASC';

        $sql = "SELECT
                    n.id,
                    n.event_id,
                    n.calendar_id,
                    e.title        AS event_title,
                    e.start_datetime AS event_start,
                    n.occurrence_key,
                    n.fire_at,
                    n.minutes_before,
                    n.recipient_email,
                    n.status,
                    n.sent_at,
                    n.error
                FROM email_notification_queue n
                JOIN calendar_events e ON e.id = n.event_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY n.fire_at {$orderDir}
                LIMIT ?";

        $params[] = $limit;
        $stmt     = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Accès statique à la connexion PDO (identique à getDb() mais sans instance).
     */
    private static function getStaticDb(): PDO
    {
        require_once __DIR__ . '/../../auth_groups/database.php';
        return \Database::getInstance()->getConnection();
    }
}
