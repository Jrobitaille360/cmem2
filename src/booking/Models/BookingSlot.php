<?php

namespace Booking\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * booking_slots — une ligne par créneau généré. Zones réservées jamais touchées
 * par la régénération (voir Services\BookingSlotService, Phase 3).
 */
class BookingSlot extends BaseModel
{
    protected $table = 'booking_slots';

    public function create() { throw new \LogicException('Utiliser insertMany() / la régénération'); }
    public function update() { throw new \LogicException("Utiliser reserve()/release() (Phase 5)"); }

    /**
     * Supprime les zones non réservées dont le début est encore à venir, pour une page donnée.
     * Les zones réservées (reserved = 1) ne sont jamais touchées.
     */
    public function deleteNonReservedFutureByPage(int $bookingPageId): int
    {
        $stmt = $this->getDb()->prepare(
            "DELETE FROM {$this->table}
              WHERE booking_page_id = ? AND reserved = 0 AND start_datetime > UTC_TIMESTAMP()"
        );
        $stmt->execute([$bookingPageId]);
        return $stmt->rowCount();
    }

    /**
     * Insertion en lot de zones non réservées. $slots = [['start' => 'Y-m-d H:i:s' (UTC),
     * 'end' => 'Y-m-d H:i:s' (UTC)], ...]. Retourne le nombre de lignes insérées.
     */
    public function insertMany(int $bookingPageId, array $slots): int
    {
        if (empty($slots)) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($slots as $s) {
            $placeholders[] = '(?, ?, ?, 0)';
            $params[] = $bookingPageId;
            $params[] = $s['start'];
            $params[] = $s['end'];
        }

        $sql = "INSERT INTO {$this->table} (booking_page_id, start_datetime, end_datetime, reserved)
                VALUES " . implode(', ', $placeholders);
        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);

        return count($slots);
    }

    /** Zones libres d'une page dans une plage UTC ['Y-m-d H:i:s', 'Y-m-d H:i:s'], triées. */
    public function findFreeInRange(int $bookingPageId, string $fromUtc, string $toUtc): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT id, start_datetime, end_datetime FROM {$this->table}
              WHERE booking_page_id = ? AND reserved = 0
                AND start_datetime >= ? AND start_datetime < ?
              ORDER BY start_datetime"
        );
        $stmt->execute([$bookingPageId, $fromUtc, $toUtc]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countReservedByPage(int $bookingPageId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE booking_page_id = ? AND reserved = 1"
        );
        $stmt->execute([$bookingPageId]);
        return (int) $stmt->fetchColumn();
    }

    /** Sans filtre sur reserved : sert à distinguer SLOT_INVALID (absent) de SLOT_TAKEN (409). */
    public function findByIdForPage(int $id, int $bookingPageId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE id = ? AND booking_page_id = ?"
        );
        $stmt->execute([$id, $bookingPageId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Écriture atomique de réservation. La contrainte `reserved = 0` dans le WHERE règle la
     * course entre deux invités visant la même zone — pas de revérification applicative.
     */
    public function reserve(int $id, string $guestName, string $guestEmail, string $guestTimezone, string $cancelToken): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
                SET reserved = 1, guest_name = ?, guest_email = ?, guest_timezone = ?, cancel_token = ?
              WHERE id = ? AND reserved = 0"
        );
        $stmt->execute([$guestName, $guestEmail, $guestTimezone, $cancelToken, $id]);
        return $stmt->rowCount() > 0;
    }

    public function attachEvent(int $id, int $eventId): void
    {
        $this->getDb()->prepare("UPDATE {$this->table} SET event_id = ? WHERE id = ?")
            ->execute([$eventId, $id]);
    }

    /** Rollback d'une réservation qui a échoué après l'UPDATE atomique (ex. création événement KO). */
    public function release(int $id): void
    {
        $this->getDb()->prepare(
            "UPDATE {$this->table}
                SET reserved = 0, guest_name = NULL, guest_email = NULL, guest_timezone = NULL,
                    cancel_token = NULL, event_id = NULL
              WHERE id = ?"
        )->execute([$id]);
    }

    public function findByCancelToken(string $token): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE cancel_token = ? AND reserved = 1"
        );
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Atomique, gardée par (id, cancel_token, reserved=1) : idempotent si rejouée. */
    public function releaseByToken(int $id, string $token): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
                SET reserved = 0, guest_name = NULL, guest_email = NULL, guest_timezone = NULL,
                    cancel_token = NULL, event_id = NULL
              WHERE id = ? AND cancel_token = ? AND reserved = 1"
        );
        $stmt->execute([$id, $token]);
        return $stmt->rowCount() > 0;
    }
}
