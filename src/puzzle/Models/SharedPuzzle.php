<?php

namespace Puzzle\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class SharedPuzzle extends BaseModel
{
    protected $table = 'puzzle_shared';

    public function create()
    {
        throw new \LogicException('Utiliser createFromData()');
    }

    public function update()
    {
        throw new \LogicException('Utiliser les méthodes spécifiques');
    }

    /** Crée un casse-tête partagé et retourne son ID. */
    public function createFromData(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO puzzle_shared
                (shared_uid, image_id, piece_count, seed, creator_id, partner_id, completion, status)
            VALUES (?, ?, ?, ?, ?, ?, 0, 'active')
        ");
        $stmt->execute([
            $data['shared_uid'],
            $data['image_id'],
            $data['piece_count'],
            $data['seed'],
            $data['creator_id'],
            $data['partner_id'],
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    /** Retourne un partagé actif par shared_uid, en vérifiant que device_id est créateur ou partenaire. */
    public function findActiveByUidAndDevice(string $sharedUid, int $deviceId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT ps.*
            FROM puzzle_shared ps
            WHERE ps.shared_uid = ?
              AND ps.status = 'active'
              AND (ps.creator_id = ? OR ps.partner_id = ?)
        ");
        $stmt->execute([$sharedUid, $deviceId, $deviceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Retourne tous les partagés actifs d'un appareil (créateur ou partenaire). */
    public function listActiveForDevice(int $deviceId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                ps.shared_uid,
                pi.uid AS image_uid,
                COALESCE(
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = 'fr'),
                    ''
                ) AS image_label,
                pi.thumb_path,
                ps.piece_count,
                ps.completion,
                ps.last_activity_at,
                CASE
                    WHEN ps.creator_id = ? THEN pd.pseudonym
                    ELSE pd2.pseudonym
                END AS partner_pseudonym
            FROM puzzle_shared ps
            INNER JOIN puzzle_images pi ON pi.id = ps.image_id
            LEFT JOIN puzzle_devices pd  ON pd.id  = ps.partner_id
            LEFT JOIN puzzle_devices pd2 ON pd2.id = ps.creator_id
            WHERE ps.status = 'active'
              AND (ps.creator_id = ? OR ps.partner_id = ?)
            ORDER BY ps.last_activity_at DESC
        ");
        $stmt->execute([$deviceId, $deviceId, $deviceId]);
        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';

        return array_map(function ($r) use ($apiBase) {
            return [
                'shared_uid'        => $r['shared_uid'],
                'image_uid'         => $r['image_uid'],
                'image_label'       => $r['image_label'],
                'thumb_url'         => "{$apiBase}/puzzle/thumb/{$r['image_uid']}",
                'piece_count'       => (int) $r['piece_count'],
                'completion'        => (int) $r['completion'],
                'partner_pseudonym' => $r['partner_pseudonym'],
                'last_activity_at'  => date('c', strtotime($r['last_activity_at'])),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function updateCompletion(int $id, int $completion): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_shared SET completion = ?, last_activity_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$completion, $id]);
    }

    public function archive(int $id): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_shared SET status = 'archived' WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->getDb()->prepare("DELETE FROM puzzle_shared WHERE id = ?");
        $stmt->execute([$id]);
    }

    // -----------------------------------------------------------------------
    // Pièces
    // -----------------------------------------------------------------------

    /** Insère l'état initial de toutes les pièces. */
    public function insertPieces(int $sharedId, array $pieces): void
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO puzzle_shared_pieces (shared_id, piece_id, x, y, rotation, locked)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE x = VALUES(x), y = VALUES(y),
                rotation = VALUES(rotation), locked = VALUES(locked)
        ");
        foreach ($pieces as $p) {
            $stmt->execute([
                $sharedId,
                (int) $p['piece_id'],
                (float) $p['x'],
                (float) $p['y'],
                (int) ($p['rotation'] ?? 0),
                (int) ($p['locked']   ?? 0),
            ]);
        }
    }

    /** Retourne toutes les pièces d'un partagé. */
    public function getPieces(int $sharedId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT piece_id, x, y, rotation, locked FROM puzzle_shared_pieces WHERE shared_id = ? ORDER BY piece_id"
        );
        $stmt->execute([$sharedId]);
        return array_map(fn($r) => [
            'piece_id' => (int) $r['piece_id'],
            'x'        => (float) $r['x'],
            'y'        => (float) $r['y'],
            'rotation' => (int) $r['rotation'],
            'locked'   => (bool) $r['locked'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Met à jour une pièce et retourne le nouveau pourcentage de completion. */
    public function movePiece(int $sharedId, int $pieceId, float $x, float $y, int $rotation, bool $locked): int
    {
        $db = $this->getDb();
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO puzzle_shared_pieces (shared_id, piece_id, x, y, rotation, locked)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE x = VALUES(x), y = VALUES(y),
                rotation = VALUES(rotation), locked = VALUES(locked)
        ");
        $stmt->execute([$sharedId, $pieceId, $x, $y, $rotation, (int) $locked]);

        // Recalculer completion
        $stmt2 = $db->prepare("
            SELECT piece_count FROM puzzle_shared WHERE id = ?
        ");
        $stmt2->execute([$sharedId]);
        $total = (int) ($stmt2->fetchColumn() ?: 1);

        $stmt3 = $db->prepare(
            "SELECT COUNT(*) FROM puzzle_shared_pieces WHERE shared_id = ? AND locked = 1"
        );
        $stmt3->execute([$sharedId]);
        $lockedCount = (int) $stmt3->fetchColumn();

        $completion = (int) round(100 * $lockedCount / $total);

        $db->prepare("UPDATE puzzle_shared SET completion = ?, last_activity_at = NOW() WHERE id = ?")
            ->execute([$completion, $sharedId]);

        $db->commit();
        return $completion;
    }

    // -----------------------------------------------------------------------
    // Événements
    // -----------------------------------------------------------------------

    /** Insère un événement de mouvement dans le journal. */
    public function insertEvent(int $sharedId, int $deviceId, int $pieceId, float $x, float $y, int $rotation, bool $locked): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO puzzle_shared_events (shared_id, device_id, piece_id, x, y, rotation, locked)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sharedId, $deviceId, $pieceId, $x, $y, $rotation, (int) $locked]);
        return (int) $this->getDb()->lastInsertId();
    }

    /** Retourne les événements du partenaire survenus depuis $afterEventId. */
    public function getPartnerEvents(int $sharedId, int $callerDeviceId, int $afterEventId, int $partnerActiveWindow): array
    {
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT
                e.id AS event_id,
                e.piece_id,
                e.x,
                e.y,
                e.rotation,
                e.locked,
                pd.pseudonym AS `by`,
                e.created_at AS at
            FROM puzzle_shared_events e
            LEFT JOIN puzzle_devices pd ON pd.id = e.device_id
            WHERE e.shared_id = ?
              AND e.id > ?
              AND e.device_id != ?
            ORDER BY e.id ASC
        ");
        $stmt->execute([$sharedId, $afterEventId, $callerDeviceId]);
        $events = array_map(fn($r) => [
            'event_id'  => (int) $r['event_id'],
            'piece_id'  => (int) $r['piece_id'],
            'x'         => (float) $r['x'],
            'y'         => (float) $r['y'],
            'rotation'  => (int) $r['rotation'],
            'locked'    => (bool) $r['locked'],
            'by'        => $r['by'],
            'at'        => date('c', strtotime($r['at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Déterminer si le partenaire est actif récemment
        $partnerIdRow = $db->prepare("
            SELECT CASE WHEN creator_id = ? THEN partner_id ELSE creator_id END AS partner_id
            FROM puzzle_shared WHERE id = ?
        ");
        $partnerIdRow->execute([$callerDeviceId, $sharedId]);
        $partnerId = (int) ($partnerIdRow->fetchColumn() ?: 0);

        $activeStmt = $db->prepare("
            SELECT 1 FROM puzzle_devices
            WHERE id = ? AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $activeStmt->execute([$partnerId, $partnerActiveWindow]);
        $partnerActive = (bool) $activeStmt->fetchColumn();

        return [$events, $partnerActive];
    }

    /** Purge les événements plus anciens que $retentionHours heures. */
    public function purgeOldEvents(int $retentionHours): void
    {
        $stmt = $this->getDb()->prepare(
            "DELETE FROM puzzle_shared_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $stmt->execute([$retentionHours]);
    }

    /** Retourne le dernier event_id d'un partagé (0 si aucun). */
    public function getLastEventId(int $sharedId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT MAX(id) FROM puzzle_shared_events WHERE shared_id = ?"
        );
        $stmt->execute([$sharedId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
