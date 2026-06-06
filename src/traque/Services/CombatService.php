<?php

namespace Traque\Services;

use Traque\Models\Monster;
use Traque\Models\CombatSession;
use Traque\Models\Player;

class CombatService
{
    private Monster       $monsterModel;
    private CombatSession $sessionModel;
    private Player        $playerModel;
    private AchievementService $achievementService;

    public function __construct()
    {
        $this->monsterModel       = new Monster();
        $this->sessionModel       = new CombatSession();
        $this->playerModel        = new Player();
        $this->achievementService = new AchievementService();
    }

    // -------------------------------------------------------------------------
    // start
    // -------------------------------------------------------------------------

    public function start(int $playerId, int $monsterId): array
    {
        $monster = $this->monsterModel->findById($monsterId);
        if (!$monster) {
            return ['error' => 'not_found', 'code' => 404];
        }
        if (!$monster['is_alive']) {
            return ['error' => 'monster_dead', 'code' => 409];
        }

        $active = $this->sessionModel->findActiveByPlayer($playerId);
        if ($active) {
            return ['error' => 'session_already_active', 'code' => 409];
        }

        $player = $this->playerModel->findById($playerId);
        if (!$player) {
            return ['error' => 'player_not_found', 'code' => 404];
        }

        $scaled     = Monster::applyScaling($monster, (int) $player['level']);
        $sessionId  = $this->sessionModel->create(
            $playerId, $monsterId,
            (int) $player['hp_current'],
            (int) $scaled['hp_max'],
            (int) $scaled['level']
        );

        return [
            'session_id' => $sessionId,
            'monster'    => [
                'id'          => (int) $monster['id'],
                'name'        => $monster['name'],
                'level'       => $scaled['level'],
                'hp_current'  => $scaled['hp_current'],
                'hp_max'      => $scaled['hp_max'],
                'ac'          => $scaled['ac'],
                'damage_dice' => $monster['damage_dice'],
            ],
            'player' => [
                'hp_current' => (int) $player['hp_current'],
                'hp_max'     => (int) $player['hp_max'],
                'ac'         => $this->playerAc($player),
                'stat_for'   => (int) $player['stat_for'],
                'stat_dex'   => (int) $player['stat_dex'],
                'stat_con'   => (int) $player['stat_con'],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // attack
    // -------------------------------------------------------------------------

    public function attack(int $playerId, int $sessionId, int $attackRoll, int $damageRoll, int $statMod): array
    {
        $session = $this->sessionModel->findById($sessionId);
        if (!$session || (int) $session['player_id'] !== $playerId) {
            return ['error' => 'session_not_found', 'code' => 404];
        }
        if ($session['status'] !== 'active') {
            return ['error' => 'session_ended', 'code' => 409];
        }

        $monster = $this->monsterModel->findById((int) $session['monster_id']);
        $player  = $this->playerModel->findById($playerId);

        $turn          = (int) $session['turn'];
        $monsterLevel  = (int) $session['monster_level'];
        $log           = [];

        // --- Player attacks monster ---
        $monsterAc  = $this->scaledAc($monster, $monsterLevel);
        $totalAttack = $attackRoll + $statMod;
        $hit         = ($totalAttack >= $monsterAc);
        $damageDealt = $hit ? max(0, $damageRoll) : 0;

        $monsterHpCurrent = (int) $monster['hp_current'] - $damageDealt;
        $monsterHpCurrent = max(0, $monsterHpCurrent);

        $action = $hit ? 'attack' : 'miss';
        $log[]  = [
            'actor'  => 'player',
            'action' => $action,
            'roll'   => $attackRoll,
            'result' => $damageDealt,
            'text'   => $hit
                ? "Vous touchez {$monster['name']} pour {$damageDealt} dégâts."
                : "Vous ratez {$monster['name']}.",
        ];

        $this->sessionModel->addLog($sessionId, $turn, 'player', $action, $attackRoll, $statMod, $damageDealt, end($log)['text']);

        $monsterDead      = ($monsterHpCurrent <= 0);
        $counterAttack    = null;
        $playerDead       = false;
        $xpEarned         = 0;
        $newAchievements  = [];
        $newLevel         = null;

        if ($monsterDead) {
            $this->monsterModel->updateHp((int) $monster['id'], 0);
            $this->monsterModel->markDead((int) $monster['id']);
            $this->sessionModel->updateStatus($sessionId, 'victory');

            // XP
            $xpEarned = $this->scaledXp($monster, $monsterLevel);
            $updatedPlayer = $this->playerModel->addXp($playerId, $xpEarned);

            // Journal + bestiaire
            $this->sessionModel->addJournalEntry(
                $playerId, $sessionId, (int) $monster['id'],
                $monster['name'], $monsterLevel, 'victory', $xpEarned
            );
            $this->playerModel->upsertBestiary($playerId, (int) $monster['id']);

            // Achievements
            $newAchievements = $this->achievementService->checkAfterVictory($playerId, (int) $monster['id'], (int) $updatedPlayer['level']);

            // Level-up auto
            $lvResult = $this->playerModel->levelUp($playerId);
            if (isset($lvResult['new_level'])) {
                $newLevel = $lvResult['new_level'];
                $newAchievements = array_merge(
                    $newAchievements,
                    $this->achievementService->checkAfterVictory($playerId, (int) $monster['id'], $newLevel)
                );
            }

            $this->sessionModel->addLog($sessionId, $turn, 'monster', 'victory', null, null, null, "{$monster['name']} est vaincu !");
        } else {
            $this->monsterModel->updateHp((int) $monster['id'], $monsterHpCurrent);

            // --- Monster counter-attacks ---
            $monsterAttackMod  = (int) floor(($monsterLevel - 10) / 2);
            $monsterRoll       = random_int(1, 20);
            $monsterTotal      = $monsterRoll + $monsterAttackMod;
            $playerAc          = $this->playerAc($player);
            $monsterHit        = ($monsterTotal >= $playerAc);
            $monsterDamage     = 0;

            if ($monsterHit) {
                $monsterDamage = $this->rollDice($monster['damage_dice']);
            }

            $playerHpCurrent = (int) $player['hp_current'] - $monsterDamage;
            $playerHpCurrent = max(0, $playerHpCurrent);

            $mAction = $monsterHit ? 'attack' : 'miss';
            $mText   = $monsterHit
                ? "{$monster['name']} vous attaque pour {$monsterDamage} dégâts."
                : "{$monster['name']} vous rate.";

            $log[] = [
                'actor'  => 'monster',
                'action' => $mAction,
                'roll'   => $monsterRoll,
                'result' => $monsterDamage,
                'text'   => $mText,
            ];
            $this->sessionModel->addLog($sessionId, $turn, 'monster', $mAction, $monsterRoll, $monsterAttackMod, $monsterDamage, $mText);

            $counterAttack = [
                'hit'              => $monsterHit,
                'attack_roll'      => $monsterRoll,
                'damage_dealt'     => $monsterDamage,
                'player_hp_current' => max(0, $playerHpCurrent),
            ];

            $playerDead = ($playerHpCurrent <= 0);
            if ($playerDead) {
                $restoredHp = max(1, (int) round($player['hp_max'] * 0.30));
                $this->playerModel->updateHp($playerId, $restoredHp);
                $this->sessionModel->updateStatus($sessionId, 'defeat');
                $this->sessionModel->addJournalEntry(
                    $playerId, $sessionId, (int) $monster['id'],
                    $monster['name'], $monsterLevel, 'defeat', 0
                );
                $counterAttack['player_hp_current'] = $restoredHp;
            } else {
                $this->playerModel->updateHp($playerId, $playerHpCurrent);
            }
        }

        $this->sessionModel->incrementTurn($sessionId);

        return [
            'hit'               => $hit,
            'damage_dealt'      => $damageDealt,
            'monster_hp_current' => $monsterHpCurrent,
            'monster_dead'      => $monsterDead,
            'counter_attack'    => $counterAttack,
            'player_dead'       => $playerDead,
            'log'               => $log,
            'xp_earned'         => $xpEarned,
            'new_achievements'  => $newAchievements ?: null,
            'new_level'         => $newLevel,
        ];
    }

    // -------------------------------------------------------------------------
    // flee
    // -------------------------------------------------------------------------

    public function flee(int $playerId, int $sessionId, int $dexRoll, int $statDex): array
    {
        $session = $this->sessionModel->findById($sessionId);
        if (!$session || (int) $session['player_id'] !== $playerId) {
            return ['error' => 'session_not_found', 'code' => 404];
        }
        if ($session['status'] !== 'active') {
            return ['error' => 'session_ended', 'code' => 409];
        }

        $monster  = $this->monsterModel->findById((int) $session['monster_id']);
        $player   = $this->playerModel->findById($playerId);
        $turn     = (int) $session['turn'];
        $dexMod   = (int) floor(($statDex - 10) / 2);
        $fleeScore = $dexRoll + $dexMod;
        $fleeSeuil = 10;
        $fled      = ($fleeScore >= $fleeSeuil);

        if ($fled) {
            $this->sessionModel->updateStatus($sessionId, 'fled');
            $this->sessionModel->addJournalEntry(
                $playerId, $sessionId, (int) $monster['id'],
                $monster['name'], (int) $session['monster_level'], 'fled', 0
            );
            $this->sessionModel->addLog($sessionId, $turn, 'player', 'flee', $dexRoll, $dexMod, null, 'Vous prenez la fuite avec succès.');
            return ['fled' => true, 'session_status' => 'fled', 'log' => 'Vous prenez la fuite avec succès.'];
        }

        // Fuite échouée — monstre contre-attaque
        $monsterLevel     = (int) $session['monster_level'];
        $monsterAttackMod = (int) floor(($monsterLevel - 10) / 2);
        $monsterRoll      = random_int(1, 20);
        $monsterTotal     = $monsterRoll + $monsterAttackMod;
        $playerAc         = $this->playerAc($player);
        $monsterHit       = ($monsterTotal >= $playerAc);
        $monsterDamage    = $monsterHit ? $this->rollDice($monster['damage_dice']) : 0;

        $playerHpCurrent = max(0, (int) $player['hp_current'] - $monsterDamage);
        $this->playerModel->updateHp($playerId, $playerHpCurrent);

        $this->sessionModel->addLog($sessionId, $turn, 'player', 'flee', $dexRoll, $dexMod, null, 'La fuite échoue.');
        $this->sessionModel->incrementTurn($sessionId);

        return [
            'fled'           => false,
            'counter_attack' => [
                'hit'              => $monsterHit,
                'attack_roll'      => $monsterRoll,
                'damage_dealt'     => $monsterDamage,
                'player_hp_current' => $playerHpCurrent,
            ],
            'log' => "La fuite échoue. {$monster['name']} vous attaque.",
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function playerAc(array $player): int
    {
        $dexMod = (int) floor(((int) $player['stat_dex'] - 10) / 2);
        return 10 + $dexMod;
    }

    private function scaledAc(array $monster, int $levelEff): int
    {
        return (int) $monster['ac'] + (int) floor(($levelEff - (int) $monster['level_base']) / 5);
    }

    private function scaledXp(array $monster, int $levelEff): int
    {
        $ratio = (int) $monster['level_base'] > 0
            ? $levelEff / (int) $monster['level_base']
            : 1;
        return max(1, (int) round((int) $monster['xp_reward'] * $ratio));
    }

    /**
     * Évalue une expression de dés : "2d6+2", "1d8", "1d6", "3d8+4", etc.
     */
    private function rollDice(string $expr): int
    {
        if (!preg_match('/^(\d+)d(\d+)(?:\+(\d+))?$/', strtolower(trim($expr)), $m)) {
            return 1;
        }
        $total = (int) ($m[3] ?? 0);
        for ($i = 0; $i < (int) $m[1]; $i++) {
            $total += random_int(1, (int) $m[2]);
        }
        return max(0, $total);
    }
}
