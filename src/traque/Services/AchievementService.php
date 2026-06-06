<?php

namespace Traque\Services;

use PDO;

class AchievementService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = \Database::getInstance()->getConnection();
    }

    /**
     * Vérifie et débloque les achievements méritées après une victoire.
     * Retourne les slugs nouvellement débloqués.
     */
    public function checkAfterVictory(int $playerId, int $monsterId, int $playerLevel): array
    {
        $newlyUnlocked = [];

        $achievements = $this->getAllNotUnlocked($playerId);
        foreach ($achievements as $ach) {
            if ($this->evaluate($ach, $playerId, $monsterId, $playerLevel)) {
                $this->unlock($playerId, (int) $ach['id']);
                $newlyUnlocked[] = $ach['slug'];
            }
        }

        return $newlyUnlocked;
    }

    public function getAllWithStatus(int $playerId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.slug, a.title_fr, a.description_fr, a.icon_key,
                   IF(pa.player_id IS NOT NULL, 1, 0) AS unlocked,
                   pa.unlocked_at
            FROM traque_achievements a
            LEFT JOIN traque_player_achievements pa
                   ON pa.achievement_id = a.id AND pa.player_id = :pid
            ORDER BY a.id
        ");
        $stmt->execute([':pid' => $playerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'slug'          => $r['slug'],
                'title_fr'      => $r['title_fr'],
                'description_fr' => $r['description_fr'],
                'icon_key'      => $r['icon_key'],
                'unlocked'      => (bool) $r['unlocked'],
                'unlocked_at'   => $r['unlocked_at'],
            ];
        }, $rows);
    }

    private function getAllNotUnlocked(int $playerId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*
            FROM traque_achievements a
            WHERE NOT EXISTS (
                SELECT 1 FROM traque_player_achievements pa
                WHERE pa.achievement_id = a.id AND pa.player_id = :pid
            )
        ");
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function unlock(int $playerId, int $achievementId): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO traque_player_achievements (player_id, achievement_id)
            VALUES (:pid, :aid)
        ");
        $stmt->execute([':pid' => $playerId, ':aid' => $achievementId]);
    }

    private function evaluate(array $ach, int $playerId, int $monsterId, int $playerLevel): bool
    {
        $type  = $ach['condition_type'];
        $value = (int) $ach['condition_value'];
        $meta  = $ach['condition_meta'] ?? null;

        switch ($type) {
            case 'first_kill':
                return $this->countKills($playerId) >= 1;

            case 'kills_total':
                return $this->countKills($playerId) >= $value;

            case 'kills_biome':
                $biome = str_replace('biome=', '', $meta ?? '');
                return $this->countKillsByBiome($playerId, $biome) >= $value;

            case 'level_reached':
                return $playerLevel >= $value;

            case 'elusive_kills':
                return $this->countKillsByBehavior($playerId, 'elusive') >= $value;

            case 'nocturnal_kills':
                return $this->countNocturnalKills($playerId) >= $value;

            case 'flee_count':
                return $this->countFlees($playerId) >= $value;

            case 'monsters_unique':
                return $this->countUniqueMonsters($playerId) >= $value;

            default:
                return false;
        }
    }

    private function countKills(int $playerId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM traque_player_journal WHERE player_id = :pid AND outcome = 'victory'"
        );
        $stmt->execute([':pid' => $playerId]);
        return (int) $stmt->fetchColumn();
    }

    private function countKillsByBiome(int $playerId, string $biome): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM traque_player_journal j
            JOIN monsters m ON m.id = j.monster_id
            WHERE j.player_id = :pid AND j.outcome = 'victory' AND m.biome = :biome
        ");
        $stmt->execute([':pid' => $playerId, ':biome' => $biome]);
        return (int) $stmt->fetchColumn();
    }

    private function countKillsByBehavior(int $playerId, string $behavior): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM traque_player_journal j
            JOIN monsters m ON m.id = j.monster_id
            WHERE j.player_id = :pid AND j.outcome = 'victory' AND m.behavior_type = :beh
        ");
        $stmt->execute([':pid' => $playerId, ':beh' => $behavior]);
        return (int) $stmt->fetchColumn();
    }

    private function countNocturnalKills(int $playerId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM traque_player_journal j
            JOIN monsters m ON m.id = j.monster_id
            WHERE j.player_id = :pid AND j.outcome = 'victory'
              AND m.spawn_hour_start IS NOT NULL
              AND (m.spawn_hour_start >= 21 OR m.spawn_hour_start <= 5)
        ");
        $stmt->execute([':pid' => $playerId]);
        return (int) $stmt->fetchColumn();
    }

    private function countFlees(int $playerId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM traque_player_journal WHERE player_id = :pid AND outcome = 'fled'"
        );
        $stmt->execute([':pid' => $playerId]);
        return (int) $stmt->fetchColumn();
    }

    private function countUniqueMonsters(int $playerId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT monster_id) FROM traque_player_bestiary WHERE player_id = :pid"
        );
        $stmt->execute([':pid' => $playerId]);
        return (int) $stmt->fetchColumn();
    }
}
