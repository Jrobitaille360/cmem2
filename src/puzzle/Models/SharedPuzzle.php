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
            $data['seed'] ?? null,
            $data['creator_id'],
            $data['partner_id'],
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    /** Retourne un partagé actif par shared_uid, en vérifiant que user_id est créateur ou partenaire. */
    public function findActiveByUidAndUser(string $sharedUid, int $userId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT ps.*
            FROM puzzle_shared ps
            WHERE ps.shared_uid = ?
              AND ps.status = 'active'
              AND (ps.creator_id = ? OR ps.partner_id = ?)
        ");
        $stmt->execute([$sharedUid, $userId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retourne tous les partagés actifs d'un utilisateur (créateur ou partenaire).
     *
     * @param int    $userId          ID de l'utilisateur appelant
     * @param string $appId           App ID (pour pseudonymes et présence)
     * @param int    $pollActiveWindow Fenêtre en secondes pour déterminer si le partenaire est actif
     */
    public function listActiveForUser(int $userId, string $appId, int $pollActiveWindow = 10): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                ps.shared_uid                        AS uid,
                pi.uid                               AS image_uid,
                COALESCE(
                    (SELECT label FROM puzzle_image_translations
                      WHERE image_id = pi.id AND lang = 'fr'),
                    ''
                )                                    AS image_label,
                pi.thumb_path,
                ps.piece_count,
                ps.completion,
                ps.last_activity_at,
                ps.status                            AS status,
                (ps.creator_id = ?)                  AS is_creator,
                aus_c.pseudonym                      AS creator_pseudo,
                CASE
                    WHEN ps.creator_id = ? THEN aus_p.pseudonym
                    ELSE aus_c.pseudonym
                END                                  AS partner_pseudo,
                CASE
                    WHEN ps.creator_id = ?
                        THEN (
                            SELECT COALESCE(MAX(d.last_seen_at), '2000-01-01') >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                            FROM (
                                SELECT last_seen_at FROM android_devices WHERE user_id = ps.partner_id AND app_id = ?
                                UNION ALL
                                SELECT last_seen_at FROM web_devices WHERE user_id = ps.partner_id AND app_id = ?
                            ) d
                        )
                    ELSE (
                        SELECT COALESCE(MAX(d.last_seen_at), '2000-01-01') >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                        FROM (
                            SELECT last_seen_at FROM android_devices WHERE user_id = ps.creator_id AND app_id = ?
                            UNION ALL
                            SELECT last_seen_at FROM web_devices WHERE user_id = ps.creator_id AND app_id = ?
                        ) d
                    )
                END                                  AS partner_active
            FROM puzzle_shared ps
            INNER JOIN puzzle_images  pi    ON pi.id    = ps.image_id
            LEFT  JOIN app_user_settings aus_p ON aus_p.user_id = ps.partner_id  AND aus_p.app_id = ?
            LEFT  JOIN app_user_settings aus_c ON aus_c.user_id = ps.creator_id  AND aus_c.app_id = ?
            WHERE ps.status = 'active'
              AND (ps.creator_id = ? OR ps.partner_id = ?)
            ORDER BY ps.last_activity_at DESC
        ");
        $stmt->execute([
            $userId,           // is_creator
            $userId,           // partner_pseudo CASE
            $userId,           // partner_active CASE
            $pollActiveWindow, // interval creator branch
            $appId,            // android_devices creator branch
            $appId,            // web_devices creator branch
            $pollActiveWindow, // interval partner branch
            $appId,            // android_devices partner branch
            $appId,            // web_devices partner branch
            $appId,            // aus_p JOIN
            $appId,            // aus_c JOIN
            $userId,           // WHERE creator_id
            $userId,           // WHERE partner_id
        ]);
        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';

        return array_map(function ($r) use ($apiBase) {
            return [
                'uid'              => $r['uid'],
                'image_uid'        => $r['image_uid'],
                'image_label'      => $r['image_label'],
                'thumb_url'        => "{$apiBase}/puzzle/thumb/{$r['image_uid']}",
                'piece_count'      => (int)  $r['piece_count'],
                'completion'       => (int)  $r['completion'],
                'status'           => $r['status'],
                'is_creator'       => (bool) $r['is_creator'],
                'creator_pseudo'   => $r['creator_pseudo'],
                'partner_pseudo'   => $r['partner_pseudo'],
                'partner_active'   => (bool) $r['partner_active'],
                'last_activity_at' => date('c', strtotime($r['last_activity_at'])),
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

    /** Vérifie si une partie active existe déjà entre deux utilisateurs (quelle que soit leur position créateur/partenaire). */
    public function activeGameExists(int $userA, int $userB): bool
    {
        $stmt = $this->getDb()->prepare("
            SELECT 1 FROM puzzle_shared
            WHERE status = 'active'
              AND (
                  (creator_id = ? AND partner_id = ?)
               OR (creator_id = ? AND partner_id = ?)
              )
            LIMIT 1
        ");
        $stmt->execute([$userA, $userB, $userB, $userA]);
        return (bool) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Pièces
    // -----------------------------------------------------------------------

    /** Insère l'état initial de toutes les pièces (state = 'tray', x/y NULL). */
    public function insertPieces(int $sharedId, int $pieceCount): void
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO puzzle_shared_pieces (shared_id, piece_id, state)
            VALUES (?, ?, 'tray')
            ON DUPLICATE KEY UPDATE state = 'tray'
        ");
        for ($i = 0; $i < $pieceCount; $i++) {
            $stmt->execute([$sharedId, $i]);
        }
    }

    /** Retourne les pièces non-tray d'un partagé avec leur état complet. */
    public function getPieces(int $sharedId, string $appId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                psp.piece_id,
                psp.state,
                psp.x,
                psp.y,
                psp.rotation,
                aus_held.pseudonym AS held_by,
                aus_by.pseudonym   AS `by`
            FROM puzzle_shared_pieces psp
            LEFT JOIN app_user_settings aus_held ON aus_held.user_id = psp.held_by_id AND aus_held.app_id = ?
            LEFT JOIN app_user_settings aus_by   ON aus_by.user_id   = psp.by_id      AND aus_by.app_id = ?
            WHERE psp.shared_id = ?
              AND psp.state != 'tray'
            ORDER BY psp.piece_id
        ");
        $stmt->execute([$appId, $appId, $sharedId]);
        return array_map(fn($r) => [
            'piece_id' => (int) $r['piece_id'],
            'state'    => $r['state'],
            'x'        => $r['x'] !== null ? (float) $r['x'] : null,
            'y'        => $r['y'] !== null ? (float) $r['y'] : null,
            'rotation' => (int) $r['rotation'],
            'held_by'  => $r['held_by'],
            'by'       => $r['by'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Prend une pièce (tray|floating → held).
     * Retourne ['ok' => true, 'state' => 'held', 'held_by' => pseudonym]
     *       ou ['ok' => false, 'code' => 'LOCKED'|'HELD_BY_OTHER']
     */
    public function pickPiece(int $sharedId, int $pieceId, int $userId, string $appId): array
    {
        $db = $this->getDb();
        $db->beginTransaction();

        $stmt = $db->prepare(
            "SELECT state, held_by_id, prev_state FROM puzzle_shared_pieces WHERE shared_id = ? AND piece_id = ?"
        );
        $stmt->execute([$sharedId, $pieceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $row = ['state' => 'tray', 'held_by_id' => null, 'prev_state' => 'tray'];
        }

        if ($row['state'] === 'locked') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'LOCKED'];
        }

        if ($row['state'] === 'held' && (int) $row['held_by_id'] !== $userId) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'HELD_BY_OTHER'];
        }

        $prevState = ($row['state'] === 'held') ? $row['prev_state'] : $row['state'];

        $db->prepare("
            INSERT INTO puzzle_shared_pieces (shared_id, piece_id, state, held_by_id, prev_state, held_at)
            VALUES (?, ?, 'held', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                prev_state = IF(state != 'held', state, prev_state),
                state      = 'held',
                held_by_id = VALUES(held_by_id),
                held_at    = NOW()
        ")->execute([$sharedId, $pieceId, $userId, $prevState]);

        $db->commit();

        $pseudoStmt = $db->prepare(
            "SELECT pseudonym FROM app_user_settings WHERE user_id = ? AND app_id = ?"
        );
        $pseudoStmt->execute([$userId, $appId]);
        $pseudo = $pseudoStmt->fetchColumn() ?: null;

        return ['ok' => true, 'state' => 'held', 'held_by' => $pseudo];
    }

    /**
     * Pose une pièce (held → tray|floating|locked).
     * Retourne ['ok' => true, 'state' => ..., 'x' => ..., 'y' => ..., 'rotation' => ..., 'completion' => ...]
     *       ou ['ok' => false, 'code' => 'NOT_HELD_BY_YOU']
     */
    public function dropPiece(int $sharedId, int $pieceId, int $userId, float $x, float $y, int $rotation, bool $toTray, bool $lockedHint = false): array
    {
        $db = $this->getDb();
        $db->beginTransaction();

        $stmt = $db->prepare("
            SELECT psp.state, psp.held_by_id, sh.piece_count
            FROM puzzle_shared_pieces psp
            INNER JOIN puzzle_shared sh ON sh.id = psp.shared_id
            WHERE psp.shared_id = ? AND psp.piece_id = ?
        ");
        $stmt->execute([$sharedId, $pieceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['state'] !== 'held' || (int) $row['held_by_id'] !== $userId) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'NOT_HELD_BY_YOU'];
        }

        $pieceCount = (int) $row['piece_count'];

        if ($toTray) {
            $newState = 'tray';
            $finalX   = null;
            $finalY   = null;
        } else {
            $nbCols  = max(1, (int) round(sqrt($pieceCount)));
            $col     = $pieceId % $nbCols;
            $rowIdx  = (int) ($pieceId / $nbCols);
            $targetX = ($col + 0.5) / $nbCols;
            $targetY = ($rowIdx + 0.5) / $nbCols;

            // Le client signal explicitement un snap → on lui fait confiance
            if ($lockedHint) {
                $newState = 'locked';
                $finalX   = $targetX;
                $finalY   = $targetY;
            } else {
                // Fallback serveur par distance (client sans hint ou ancien client)
                $snapTol = defined('PUZZLE_SNAP_TOLERANCE') ? (float) \PUZZLE_SNAP_TOLERANCE : 0.15;
                $pieceW  = 1.0 / $nbCols;
                $dist    = sqrt(($x - $targetX) ** 2 + ($y - $targetY) ** 2);

                if ($dist <= $snapTol * $pieceW) {
                    $newState = 'locked';
                    $finalX   = $targetX;
                    $finalY   = $targetY;
                } else {
                    $newState = 'floating';
                    $finalX   = $x;
                    $finalY   = $y;
                }
            }
        }

        $db->prepare("
            UPDATE puzzle_shared_pieces
            SET state      = ?,
                x          = ?,
                y          = ?,
                rotation   = ?,
                held_by_id = NULL,
                held_at    = NULL,
                prev_state = 'tray',
                by_id      = ?
            WHERE shared_id = ? AND piece_id = ?
        ")->execute([$newState, $finalX, $finalY, $rotation, $userId, $sharedId, $pieceId]);

        $cStmt = $db->prepare("SELECT COUNT(*) FROM puzzle_shared_pieces WHERE shared_id = ? AND state = 'locked'");
        $cStmt->execute([$sharedId]);
        $lockedCount = (int) $cStmt->fetchColumn();
        $completion  = (int) round(100 * $lockedCount / max($pieceCount, 1));

        $db->prepare("UPDATE puzzle_shared SET completion = ?, last_activity_at = NOW() WHERE id = ?")
            ->execute([$completion, $sharedId]);

        $db->commit();

        return [
            'ok'         => true,
            'state'      => $newState,
            'x'          => $finalX,
            'y'          => $finalY,
            'rotation'   => $rotation,
            'completion' => $completion,
        ];
    }

    // -----------------------------------------------------------------------
    // Événements
    // -----------------------------------------------------------------------

    /**
     * Insère un événement dans le journal.
     * Pour state='held' : held_by_id = $userId, by_id = null.
     * Pour state='floating'|'locked'|'tray' : held_by_id = null, by_id = $userId.
     */
    public function insertEvent(int $sharedId, int $userId, int $pieceId, string $state, ?float $x, ?float $y, int $rotation): int
    {
        $heldById = ($state === 'held') ? $userId : null;
        $byId     = ($state !== 'held') ? $userId : null;
        $stmt = $this->getDb()->prepare("
            INSERT INTO puzzle_shared_events (shared_id, device_id, piece_id, state, x, y, rotation, held_by_id, by_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sharedId, $userId, $pieceId, $state, $x, $y, $rotation, $heldById, $byId]);
        return (int) $this->getDb()->lastInsertId();
    }

    /** Retourne tous les événements depuis $afterEventId (tous joueurs, pour réconciliation client). */
    public function getPartnerEvents(int $sharedId, int $callerUserId, int $afterEventId, int $partnerActiveWindow, string $appId): array
    {
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT
                e.id              AS event_id,
                e.piece_id,
                e.state,
                e.x,
                e.y,
                e.rotation,
                aus_held.pseudonym AS held_by,
                aus_by.pseudonym   AS `by`,
                e.created_at      AS at
            FROM puzzle_shared_events e
            LEFT JOIN app_user_settings aus_held ON aus_held.user_id = e.held_by_id AND aus_held.app_id = ?
            LEFT JOIN app_user_settings aus_by   ON aus_by.user_id   = e.by_id      AND aus_by.app_id = ?
            WHERE e.shared_id = ?
              AND e.id > ?
            ORDER BY e.id ASC
        ");
        $stmt->execute([$appId, $appId, $sharedId, $afterEventId]);
        $events = array_map(fn($r) => [
            'event_id' => (int) $r['event_id'],
            'piece_id' => (int) $r['piece_id'],
            'state'    => $r['state'],
            'x'        => $r['x'] !== null ? (float) $r['x'] : null,
            'y'        => $r['y'] !== null ? (float) $r['y'] : null,
            'rotation' => (int) $r['rotation'],
            'held_by'  => $r['held_by'],
            'by'       => $r['by'],
            'at'       => date('c', strtotime($r['at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $partnerIdRow = $db->prepare("
            SELECT CASE WHEN creator_id = ? THEN partner_id ELSE creator_id END AS partner_user_id
            FROM puzzle_shared WHERE id = ?
        ");
        $partnerIdRow->execute([$callerUserId, $sharedId]);
        $partnerUserId = (int) ($partnerIdRow->fetchColumn() ?: 0);

        $activeStmt = $db->prepare("
            SELECT COALESCE(MAX(d.last_seen_at), '2000-01-01') >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            FROM (
                SELECT last_seen_at FROM android_devices WHERE user_id = ? AND app_id = ?
                UNION ALL
                SELECT last_seen_at FROM web_devices WHERE user_id = ? AND app_id = ?
            ) d
        ");
        $activeStmt->execute([$partnerActiveWindow, $partnerUserId, $appId, $partnerUserId, $appId]);
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

    /**
     * Expire les pièces tenues depuis plus de $ttlSeconds secondes.
     * Les remet à prev_state et insère un événement TTL pour chacune.
     */
    public function expireHeldPieces(int $sharedId, int $ttlSeconds): void
    {
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT piece_id, prev_state
            FROM puzzle_shared_pieces
            WHERE shared_id = ?
              AND state = 'held'
              AND held_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$sharedId, $ttlSeconds]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($expired as $p) {
            $db->prepare("
                UPDATE puzzle_shared_pieces
                SET state = ?, held_by_id = NULL, held_at = NULL
                WHERE shared_id = ? AND piece_id = ?
            ")->execute([$p['prev_state'], $sharedId, (int) $p['piece_id']]);

            $db->prepare("
                INSERT INTO puzzle_shared_events (shared_id, device_id, piece_id, state, x, y, rotation, held_by_id, by_id)
                SELECT ?, NULL, piece_id, ?, x, y, rotation, NULL, NULL
                FROM puzzle_shared_pieces
                WHERE shared_id = ? AND piece_id = ?
            ")->execute([$sharedId, $p['prev_state'], $sharedId, (int) $p['piece_id']]);
        }
    }

    /** Relâche toutes les pièces tenues par un utilisateur (utilisé lors du leave). */
    public function releaseHeldPieces(int $sharedId, int $userId): void
    {
        $db = $this->getDb();

        $stmt = $db->prepare("
            SELECT piece_id, prev_state
            FROM puzzle_shared_pieces
            WHERE shared_id = ? AND state = 'held' AND held_by_id = ?
        ");
        $stmt->execute([$sharedId, $userId]);
        $held = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($held as $p) {
            $db->prepare("
                UPDATE puzzle_shared_pieces
                SET state = ?, held_by_id = NULL, held_at = NULL
                WHERE shared_id = ? AND piece_id = ?
            ")->execute([$p['prev_state'], $sharedId, (int) $p['piece_id']]);

            $db->prepare("
                INSERT INTO puzzle_shared_events (shared_id, device_id, piece_id, state, x, y, rotation, held_by_id, by_id)
                SELECT ?, ?, piece_id, ?, x, y, rotation, NULL, NULL
                FROM puzzle_shared_pieces
                WHERE shared_id = ? AND piece_id = ?
            ")->execute([$sharedId, $userId, $p['prev_state'], $sharedId, (int) $p['piece_id']]);
        }
    }
}
