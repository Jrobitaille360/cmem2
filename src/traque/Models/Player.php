<?php

namespace Traque\Models;

use PDO;

class Player
{
    private PDO $db;

    // XP requis par niveau (index = niveau, valeur = XP total)
    public const XP_TABLE = [
        1  => 0,
        2  => 300,
        3  => 900,
        4  => 2100,
        5  => 3500,
        6  => 6000,
        7  => 9500,
        8  => 14000,
        9  => 19500,
        10 => 25000,
        11 => 33250,
        12 => 45625,
        13 => 64188,
        14 => 92033,
        15 => 133801,
        16 => 196453,
        17 => 290431,
        18 => 431398,
        19 => 642849,
        20 => 960026,
    ];

    // HP die par classe
    private const HP_DICE = [
        'warrior' => 10,
        'mage'    => 6,
        'ranger'  => 8,
        'cleric'  => 8,
        'rogue'   => 6,
    ];

    public function __construct()
    {
        $this->db = \Database::getInstance()->getConnection();
    }

    public function isCharacterNameTaken(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM traque_players WHERE character_name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        return $stmt->fetchColumn() !== false;
    }

    public function findById(int $playerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM traque_players WHERE player_id = :id');
        $stmt->execute([':id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO traque_players
              (player_id, character_name, class, race, level, xp, hp_max, hp_current,
               stat_for, stat_dex, stat_con, stat_int, stat_sag, stat_cha,
               skill_points_available, gems, gps_consent)
            VALUES
              (:pid, :cname, :class, :race, 1, 0, :hpmax, :hpcur,
               :for, :dex, :con, :int, :sag, :cha,
               :sp, 0, :gps)
        ");
        return $stmt->execute([
            ':pid'   => $data['player_id'],
            ':cname' => $data['character_name'],
            ':class' => $data['class'],
            ':race'  => $data['race'],
            ':hpmax' => $data['hp_max'],
            ':hpcur' => $data['hp_max'],
            ':for'   => $data['stat_for'],
            ':dex'   => $data['stat_dex'],
            ':con'   => $data['stat_con'],
            ':int'   => $data['stat_int'],
            ':sag'   => $data['stat_sag'],
            ':cha'   => $data['stat_cha'],
            ':sp'    => $data['skill_points_available'],
            ':gps'   => $data['gps_consent'] ? 1 : 0,
        ]);
    }

    public function updateHp(int $playerId, int $hpCurrent): bool
    {
        $stmt = $this->db->prepare('UPDATE traque_players SET hp_current = :hp WHERE player_id = :id');
        return $stmt->execute([':hp' => max(0, $hpCurrent), ':id' => $playerId]);
    }

    public function rest(int $playerId, string $type): array
    {
        $player = $this->findById($playerId);
        if (!$player) return ['error' => 'player_not_found', 'code' => 404];

        if (!empty($player['rest_available_at'])) {
            $until = strtotime($player['rest_available_at']);
            if ($until > time()) {
                return [
                    'error'             => 'rest_cooldown',
                    'code'              => 409,
                    'rest_available_at' => gmdate('Y-m-d\TH:i:s\Z', $until),
                ];
            }
        }

        $hpMax     = (int) $player['hp_max'];
        $hpCurrent = (int) $player['hp_current'];

        if ($type === 'full') {
            $newHp = $hpMax;
            $stmt  = $this->db->prepare("
                UPDATE traque_players
                SET hp_current = :hp, rest_available_at = DATE_ADD(NOW(), INTERVAL 4 HOUR)
                WHERE player_id = :pid
            ");
        } else {
            $missing = $hpMax - $hpCurrent;
            $heal    = max(1, (int) floor($missing * 0.5));
            $newHp   = min($hpMax, $hpCurrent + $heal);
            $stmt    = $this->db->prepare("
                UPDATE traque_players
                SET hp_current = :hp, rest_available_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                WHERE player_id = :pid
            ");
        }

        $stmt->execute([':hp' => $newHp, ':pid' => $playerId]);
        return ['player' => $this->findById($playerId)];
    }

    public function applyPassiveRegen(int $playerId): void
    {
        // Calcul entièrement en SQL pour éviter tout décalage timezone PHP/MySQL
        $stmt = $this->db->prepare("
            UPDATE traque_players
            SET hp_current     = LEAST(hp_max,
                                       hp_current + FLOOR(TIMESTAMPDIFF(MINUTE, last_combat_at, NOW()) / 5)),
                last_combat_at = DATE_ADD(last_combat_at,
                                          INTERVAL FLOOR(TIMESTAMPDIFF(MINUTE, last_combat_at, NOW()) / 5) * 5 MINUTE)
            WHERE player_id    = :pid
              AND last_combat_at IS NOT NULL
              AND hp_current    < hp_max
              AND FLOOR(TIMESTAMPDIFF(MINUTE, last_combat_at, NOW()) / 5) > 0
        ");
        $stmt->execute([':pid' => $playerId]);
    }

    public function updateLastCombatAt(int $playerId): void
    {
        $stmt = $this->db->prepare('UPDATE traque_players SET last_combat_at = NOW() WHERE player_id = :pid');
        $stmt->execute([':pid' => $playerId]);
    }

    public function addXp(int $playerId, int $xp): array
    {
        $stmt = $this->db->prepare('UPDATE traque_players SET xp = xp + :xp WHERE player_id = :id');
        $stmt->execute([':xp' => $xp, ':id' => $playerId]);
        return $this->findById($playerId);
    }

    public function levelUp(int $playerId): array
    {
        $player = $this->findById($playerId);
        if (!$player) return ['already_max' => true];

        $currentLevel = (int) $player['level'];
        if ($currentLevel >= 20) return ['already_max' => true];

        $xpRequired = self::XP_TABLE[$currentLevel + 1] ?? PHP_INT_MAX;
        if ((int) $player['xp'] < $xpRequired) return ['already_max' => true];

        $newLevel    = $currentLevel + 1;
        $die         = self::HP_DICE[$player['class']] ?? 6;
        $conMod      = (int) floor(((int) $player['stat_con'] - 10) / 2);
        $hpGained    = max(1, random_int(1, $die) + $conMod);
        $newHpMax    = (int) $player['hp_max'] + $hpGained;
        $spAdd       = $player['race'] === 'human' ? 2 : 1;

        $stmt = $this->db->prepare("
            UPDATE traque_players
            SET level = :lvl, hp_max = :hpmax, hp_current = hp_current + :hpg,
                skill_points_available = skill_points_available + :sp
            WHERE player_id = :pid
        ");
        $stmt->execute([
            ':lvl'   => $newLevel,
            ':hpmax' => $newHpMax,
            ':hpg'   => $hpGained,
            ':sp'    => $spAdd,
            ':pid'   => $playerId,
        ]);

        $updated = $this->findById($playerId);
        return [
            'new_level'               => $newLevel,
            'hp_max'                  => $newHpMax,
            'hp_gained'               => $hpGained,
            'skill_points_available'  => (int) $updated['skill_points_available'],
        ];
    }

    public function updateSettings(int $playerId, array $fields): bool
    {
        $allowed = ['location_visibility', 'pvp_enabled'];
        $sets    = [];
        $params  = [':pid' => $playerId];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $fields)) {
                $sets[]       = "`$f` = :$f";
                $params[":$f"] = $fields[$f];
            }
        }

        if (empty($sets)) return false;

        $stmt = $this->db->prepare('UPDATE traque_players SET ' . implode(', ', $sets) . ' WHERE player_id = :pid');
        return $stmt->execute($params);
    }

    public function upsertPushToken(int $playerId, string $fcmToken, ?string $deviceId): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO traque_push_tokens (player_id, fcm_token, device_id)
            VALUES (:pid, :token, :did)
            ON DUPLICATE KEY UPDATE fcm_token = :token2, updated_at = NOW()
        ");
        return $stmt->execute([
            ':pid'    => $playerId,
            ':token'  => $fcmToken,
            ':did'    => $deviceId,
            ':token2' => $fcmToken,
        ]);
    }

    public function getBestiary(int $playerId): array
    {
        $stmt = $this->db->prepare("
            SELECT b.monster_id, m.name, m.asset_key, m.lore,
                   b.kills, b.first_kill_at
            FROM traque_player_bestiary b
            JOIN monsters m ON m.id = b.monster_id
            WHERE b.player_id = :pid
            ORDER BY b.first_kill_at ASC
        ");
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertBestiary(int $playerId, int $monsterId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO traque_player_bestiary (player_id, monster_id, kills)
            VALUES (:pid, :mid, 1)
            ON DUPLICATE KEY UPDATE kills = kills + 1, last_kill_at = NOW()
        ");
        $stmt->execute([':pid' => $playerId, ':mid' => $monsterId]);
    }

    public function getLeaderboard(string $type, ?string $value, int $limit): array
    {
        $limit = min(100, max(1, $limit));

        if ($type === 'class' && $value) {
            $stmt = $this->db->prepare("
                SELECT ROW_NUMBER() OVER (ORDER BY (tp.level * 10000 + tp.xp) DESC) AS `rank`,
                       tp.player_id, tp.character_name AS display_name,
                       tp.level, tp.xp, tp.class
                FROM traque_players tp
                WHERE tp.class = :val
                ORDER BY (tp.level * 10000 + tp.xp) DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':val', $value);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($type === 'biome' && $value) {
            $stmt = $this->db->prepare("
                SELECT ROW_NUMBER() OVER (ORDER BY SUM(j.xp_earned) DESC) AS `rank`,
                       tp.player_id,
                       tp.character_name AS display_name,
                       tp.level, SUM(j.xp_earned) AS xp, tp.class
                FROM traque_players tp
                JOIN traque_player_journal j ON j.player_id = tp.player_id
                JOIN monsters m ON m.id = j.monster_id
                WHERE m.biome = :val AND j.outcome = 'victory'
                GROUP BY tp.player_id, tp.character_name, tp.level, tp.class
                ORDER BY xp DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':val', $value);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // global
        $stmt = $this->db->prepare("
            SELECT ROW_NUMBER() OVER (ORDER BY (tp.level * 10000 + tp.xp) DESC) AS `rank`,
                   tp.player_id,
                   tp.character_name AS display_name,
                   tp.level, tp.xp, tp.class
            FROM traque_players tp
            ORDER BY (tp.level * 10000 + tp.xp) DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function xpNextLevel(int $level): int
    {
        return self::XP_TABLE[$level + 1] ?? 0;
    }

    /**
     * Calcule HP initial à la création (dé HP classe + CON mod, min 1).
     */
    public static function rollInitialHp(string $class, int $statCon): int
    {
        $die    = self::HP_DICE[$class] ?? 6;
        $conMod = (int) floor(($statCon - 10) / 2);
        return max(1, random_int(1, $die) + $conMod);
    }
}
