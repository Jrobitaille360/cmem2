<?php

namespace Traque\Models;

use PDO;

class CombatSession
{
    private PDO $db;

    public function __construct()
    {
        $this->db = \Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM traque_combat_sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findActiveByPlayer(int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM traque_combat_sessions WHERE player_id = :pid AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':pid' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $playerId, int $monsterId, int $playerHp, int $monsterHp, int $monsterLevel): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO traque_combat_sessions
              (player_id, monster_id, player_hp_start, monster_hp_start, monster_level)
            VALUES (:pid, :mid, :php, :mhp, :mlv)
        ");
        $stmt->execute([
            ':pid' => $playerId,
            ':mid' => $monsterId,
            ':php' => $playerHp,
            ':mhp' => $monsterHp,
            ':mlv' => $monsterLevel,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE traque_combat_sessions SET status = :s, ended_at = NOW() WHERE id = :id"
        );
        return $stmt->execute([':s' => $status, ':id' => $id]);
    }

    public function incrementTurn(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE traque_combat_sessions SET turn = turn + 1 WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function addLog(int $sessionId, int $turn, string $actor, string $action, ?int $roll, ?int $modifier, ?int $result, ?string $text): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO traque_combat_log (session_id, turn, actor, action, roll, modifier, result, log_text)
            VALUES (:sid, :turn, :actor, :action, :roll, :mod, :result, :text)
        ");
        $stmt->execute([
            ':sid'    => $sessionId,
            ':turn'   => $turn,
            ':actor'  => $actor,
            ':action' => $action,
            ':roll'   => $roll,
            ':mod'    => $modifier,
            ':result' => $result,
            ':text'   => $text,
        ]);
    }

    public function addJournalEntry(int $playerId, int $sessionId, int $monsterId, string $monsterName, int $monsterLevel, string $outcome, int $xpEarned): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO traque_player_journal
              (player_id, session_id, monster_id, monster_name, monster_level, outcome, xp_earned)
            VALUES (:pid, :sid, :mid, :mname, :mlvl, :outcome, :xp)
        ");
        $stmt->execute([
            ':pid'     => $playerId,
            ':sid'     => $sessionId,
            ':mid'     => $monsterId,
            ':mname'   => $monsterName,
            ':mlvl'    => $monsterLevel,
            ':outcome' => $outcome,
            ':xp'      => $xpEarned,
        ]);
    }

    public function getJournal(int $playerId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT id, monster_name, monster_level, outcome, xp_earned, occurred_at
            FROM traque_player_journal
            WHERE player_id = :pid
            ORDER BY occurred_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':pid', $playerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
