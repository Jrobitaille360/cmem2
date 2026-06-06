<?php

namespace Traque\Models;

use PDO;

class Monster
{
    private PDO $db;

    public function __construct()
    {
        $this->db = \Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM monsters WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retourne les monstres vivants dans un rayon donné (Haversine SQL).
     * Applique le filtre temporel (spawn_hour_start / spawn_hour_end en UTC).
     *
     * @param float $lat        Latitude joueur
     * @param float $lng        Longitude joueur
     * @param int   $radiusM    Rayon en mètres (max 2000)
     * @param int   $hourUtc    Heure UTC courante (0–23)
     * @return array
     */
    public function findNearby(float $lat, float $lng, int $radiusM, int $hourUtc): array
    {
        $sql = "
            SELECT *,
                (6371000 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(lat)) *
                    COS(RADIANS(lng) - RADIANS(:lng)) +
                    SIN(RADIANS(:lat2)) * SIN(RADIANS(lat))
                )) AS distance_m
            FROM monsters
            WHERE is_alive = 1
              AND (
                spawn_hour_start IS NULL
                OR (
                    spawn_hour_start <= spawn_hour_end
                    AND :hour BETWEEN spawn_hour_start AND spawn_hour_end
                )
                OR (
                    spawn_hour_start > spawn_hour_end
                    AND (:hour2 >= spawn_hour_start OR :hour3 <= spawn_hour_end)
                )
              )
            HAVING distance_m <= :radius
            ORDER BY distance_m
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':lat'    => $lat,
            ':lat2'   => $lat,
            ':lng'    => $lng,
            ':hour'   => $hourUtc,
            ':hour2'  => $hourUtc,
            ':hour3'  => $hourUtc,
            ':radius' => $radiusM,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marque le monstre comme mort et fixe respawn_at = NOW() + 2 heures.
     */
    public function markDead(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE monsters
            SET is_alive = 0, respawn_at = DATE_ADD(NOW(), INTERVAL 2 HOUR), hp_current = 0
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Remet le monstre vivant (utilisé par cron de respawn).
     */
    public function respawn(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE monsters
            SET is_alive = 1, hp_current = hp_max, respawn_at = NULL
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Met à jour hp_current du monstre.
     */
    public function updateHp(int $id, int $hpCurrent): bool
    {
        $stmt = $this->db->prepare('UPDATE monsters SET hp_current = :hp WHERE id = :id');
        return $stmt->execute([':hp' => max(0, $hpCurrent), ':id' => $id]);
    }

    /**
     * Applique le scaling de niveau effectif selon player_level et biome (sauf is_boss).
     * Retourne le tableau monster enrichi avec level_eff et stats scalées.
     */
    public static function applyScaling(array $monster, int $playerLevel): array
    {
        if ($monster['is_boss']) {
            $levelEff = (int) $monster['level_base'];
        } else {
            $rand     = random_int(-2, 2);
            $levelEff = max(1, min(20, $playerLevel + $rand));

            $biomeMultiplier = self::biomeMultiplier($monster['biome'], (int) $monster['spawn_hour_start']);
            $levelEff = (int) round($levelEff * $biomeMultiplier);
            $levelEff = max(1, min(20, $levelEff));
        }

        $levelBase = (int) $monster['level_base'];
        $ratio     = $levelBase > 0 ? $levelEff / $levelBase : 1;

        $hpMaxEff  = max(1, (int) round($monster['hp_max'] * $ratio));
        $acEff     = (int) $monster['ac'] + (int) floor(($levelEff - $levelBase) / 5);
        $xpEff     = max(1, (int) round($monster['xp_reward'] * $ratio));

        return array_merge($monster, [
            'level'      => $levelEff,
            'hp_max'     => $hpMaxEff,
            'hp_current' => $hpMaxEff,
            'ac'         => $acEff,
            'xp_reward'  => $xpEff,
        ]);
    }

    private static function biomeMultiplier(string $biome, ?int $spawnHourStart): float
    {
        if ($biome === 'mountain') return 1.2;
        if ($biome === 'cemetery' && $spawnHourStart !== null) {
            if ($spawnHourStart >= 21 || $spawnHourStart <= 5) return 1.3;
        }
        return 1.0;
    }
}
