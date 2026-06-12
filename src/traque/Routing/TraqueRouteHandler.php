<?php

namespace Traque\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Traque\Models\Monster;
use Traque\Models\CombatSession;
use Traque\Models\Player;
use Traque\Services\CombatService;
use Traque\Services\AchievementService;

/**
 * TraqueRouteHandler — toutes les routes /traque/*
 *
 * Authentification JWT obligatoire sur toutes les routes.
 *
 * Segments :
 *   [0] traque
 *   [1] monsters | combat | players | leaderboard
 *   [2] nearby | {id} | start | attack | flee | me | create | ''
 *   [3] respawn | journal | achievements | levelup | settings | push-token | bestiary
 */
class TraqueRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = true;

    protected function getSupportedControllers(): array
    {
        return ['traque'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method'];
        $segments = $request['segments'];
        $user     = $request['user'];

        $s1 = $segments[1] ?? '';
        $s2 = $segments[2] ?? '';
        $s3 = $segments[3] ?? '';

        match (true) {

            // ---------------------------------------------------------------
            // MONSTERS
            // ---------------------------------------------------------------

            // GET /traque/monsters/nearby
            ($s1 === 'monsters' && $s2 === 'nearby' && $method === 'GET') =>
                $this->monstersNearby($user),

            // POST /traque/monsters/{id}/respawn
            ($s1 === 'monsters' && $s3 === 'respawn' && $method === 'POST') =>
                $this->monsterRespawn((int) $s2, $user),

            // ---------------------------------------------------------------
            // COMBAT
            // ---------------------------------------------------------------

            // POST /traque/combat/start
            ($s1 === 'combat' && $s2 === 'start' && $method === 'POST') =>
                $this->combatStart($user),

            // POST /traque/combat/attack
            ($s1 === 'combat' && $s2 === 'attack' && $method === 'POST') =>
                $this->combatAttack($user),

            // POST /traque/combat/flee
            ($s1 === 'combat' && $s2 === 'flee' && $method === 'POST') =>
                $this->combatFlee($user),

            // ---------------------------------------------------------------
            // PLAYERS — journal & achievements (me sub-resources)
            // ---------------------------------------------------------------

            // GET /traque/players/me/journal
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'journal' && $method === 'GET') =>
                $this->playerJournal($user),

            // GET /traque/players/me/achievements
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'achievements' && $method === 'GET') =>
                $this->playerAchievements($user),

            // POST /traque/players/me/rest
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'rest' && $method === 'POST') =>
                $this->playerRest($user),

            // POST /traque/players/me/levelup
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'levelup' && $method === 'POST') =>
                $this->playerLevelUp($user),

            // PUT /traque/players/me/settings
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'settings' && $method === 'PUT') =>
                $this->playerSettings($user),

            // POST /traque/players/me/push-token
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'push-token' && $method === 'POST') =>
                $this->playerPushToken($user),

            // GET /traque/players/me/bestiary
            ($s1 === 'players' && $s2 === 'me' && $s3 === 'bestiary' && $method === 'GET') =>
                $this->playerBestiary($user),

            // GET /traque/players/check-name
            ($s1 === 'players' && $s2 === 'check-name' && $method === 'GET') =>
                $this->playerCheckName($user),

            // POST /traque/players/create
            ($s1 === 'players' && $s2 === 'create' && $method === 'POST') =>
                $this->playerCreate($user),

            // GET /traque/players/me
            ($s1 === 'players' && $s2 === 'me' && $s3 === '' && $method === 'GET') =>
                $this->playerMe($user),

            // ---------------------------------------------------------------
            // LEADERBOARD
            // ---------------------------------------------------------------

            // GET /traque/leaderboard
            ($s1 === 'leaderboard' && $method === 'GET') =>
                $this->leaderboard($user),

            default => Response::error('Route traque non trouvée', null, 404)
        };
    }

    // =========================================================================
    // MONSTERS
    // =========================================================================

    private function monstersNearby(array $user): void
    {
        $lat    = isset($_GET['lat'])    ? (float) $_GET['lat']    : null;
        $lng    = isset($_GET['lng'])    ? (float) $_GET['lng']    : null;
        $radius = isset($_GET['radius']) ? (int)   $_GET['radius'] : 500;

        if ($lat === null || $lng === null) {
            Response::error('Paramètres lat et lng requis', null, 400);
            return;
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Response::error('Coordonnées invalides', null, 400);
            return;
        }
        if ($radius < 1 || $radius > 2000) {
            Response::error('radius doit être entre 1 et 2000 mètres', null, 422);
            return;
        }

        $playerLevel = (int) ($_SERVER['HTTP_X_PLAYER_LEVEL'] ?? 1);
        $playerLevel = max(1, min(20, $playerLevel));

        $hourUtc = (int) gmdate('G');

        $model    = new Monster();
        $monsters = $model->findNearby($lat, $lng, $radius, $hourUtc);

        $result = [];
        foreach ($monsters as $m) {
            $scaled   = Monster::applyScaling($m, $playerLevel);
            $result[] = [
                'id'            => (int) $m['id'],
                'name'          => $m['name'],
                'asset_key'     => $m['asset_key'],
                'level'         => $scaled['level'],
                'lat'           => (float) $m['lat'],
                'lng'           => (float) $m['lng'],
                'hp_max'        => $scaled['hp_max'],
                'hp_current'    => $scaled['hp_current'],
                'ac'            => $scaled['ac'],
                'damage_dice'   => $m['damage_dice'],
                'xp_reward'     => $scaled['xp_reward'],
                'behavior_type' => $m['behavior_type'],
                'biome'         => $m['biome'],
                'is_boss'       => (bool) $m['is_boss'],
            ];
        }

        Response::success('nearby_monsters', $result);
    }

    private function monsterRespawn(int $monsterId, array $user): void
    {
        $model   = new Monster();
        $monster = $model->findById($monsterId);

        if (!$monster) {
            Response::error('Monstre introuvable', null, 404);
            return;
        }

        $model->markDead($monsterId);

        // Recalcule respawn_at depuis la DB
        $updated = $model->findById($monsterId);
        Response::success('monster_respawn', [
            'id'         => $monsterId,
            'is_alive'   => false,
            'respawn_at' => $updated['respawn_at'],
        ]);
    }

    // =========================================================================
    // COMBAT
    // =========================================================================

    private function combatStart(array $user): void
    {
        $input = Response::getRequestParams();
        $monsterId = isset($input['monster_id']) ? (int) $input['monster_id'] : 0;

        if (!$monsterId) {
            Response::error('monster_id requis', null, 400);
            return;
        }

        $service = new CombatService();
        $result  = $service->start((int) $user['user_id'], $monsterId);

        if (isset($result['error'])) {
            $extra = isset($result['session_id']) ? ['session_id' => $result['session_id']] : null;
            Response::error($result['error'], $extra, $result['code']);
            return;
        }

        Response::success('combat_started', $result, 201);
    }

    private function combatAttack(array $user): void
    {
        $input = Response::getRequestParams();

        $sessionId  = isset($input['session_id'])  ? (int) $input['session_id']  : 0;
        $attackRoll = isset($input['attack_roll'])  ? (int) $input['attack_roll'] : null;
        $damageRoll = isset($input['damage_roll'])  ? (int) $input['damage_roll'] : null;
        $statMod    = isset($input['stat_mod'])     ? (int) $input['stat_mod']    : 0;

        if (!$sessionId || $attackRoll === null || $damageRoll === null) {
            Response::error('session_id, attack_roll et damage_roll requis', null, 400);
            return;
        }
        if ($attackRoll < 1 || $attackRoll > 20) {
            Response::error('attack_roll doit être entre 1 et 20', null, 422);
            return;
        }
        if ($damageRoll < 0) {
            Response::error('damage_roll ne peut pas être négatif', null, 422);
            return;
        }

        $service = new CombatService();
        $result  = $service->attack((int) $user['user_id'], $sessionId, $attackRoll, $damageRoll, $statMod);

        if (isset($result['error'])) {
            Response::error($result['error'], null, $result['code']);
            return;
        }

        Response::success('combat_attack', $result);
    }

    private function combatFlee(array $user): void
    {
        $input = Response::getRequestParams();

        $sessionId = isset($input['session_id']) ? (int) $input['session_id'] : 0;
        $dexRoll   = isset($input['dex_roll'])   ? (int) $input['dex_roll']   : null;
        $statDex   = isset($input['stat_dex'])   ? (int) $input['stat_dex']   : 10;

        if (!$sessionId || $dexRoll === null) {
            Response::error('session_id et dex_roll requis', null, 400);
            return;
        }

        $service = new CombatService();
        $result  = $service->flee((int) $user['user_id'], $sessionId, $dexRoll, $statDex);

        if (isset($result['error'])) {
            Response::error($result['error'], null, $result['code']);
            return;
        }

        Response::success('combat_flee', $result);
    }

    // =========================================================================
    // PLAYERS
    // =========================================================================

    private function playerCreate(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $model    = new Player();

        $input = Response::getRequestParams();

        $characterName = trim($input['character_name'] ?? '');
        $class         = $input['class'] ?? '';
        $race          = $input['race']  ?? '';
        $gpsConsent    = $input['gps_consent'] ?? false;
        $dist          = $input['stat_distribution'] ?? [];

        if ($characterName === '') {
            Response::error('character_name requis', ['error' => 'character_name_required'], 422);
            return;
        }
        if (mb_strlen($characterName) > 50) {
            Response::error('character_name doit faire 50 caractères max', ['error' => 'character_name_required'], 422);
            return;
        }

        $validClasses = ['warrior','mage','ranger','cleric','rogue'];
        $validRaces   = ['human','elf','dwarf','half_orc'];

        if (!in_array($class, $validClasses, true)) {
            Response::error('Classe invalide', null, 422);
            return;
        }
        if (!in_array($race, $validRaces, true)) {
            Response::error('Race invalide', null, 422);
            return;
        }
        if (!$gpsConsent) {
            Response::error('Consentement GPS requis', ['error' => 'gps_consent_required'], 422);
            return;
        }

        // Validation distribution stats
        $statKeys = ['stat_for','stat_dex','stat_con','stat_int','stat_sag','stat_cha'];
        $delta    = 0;
        foreach ($statKeys as $k) {
            $v     = isset($dist[$k]) ? (int) $dist[$k] : 10;
            $delta += ($v - 10);
            if ($v < 10 || $v > 18) {
                Response::error("Stat $k hors bornes (10–18)", ['error' => 'invalid_stat_distribution'], 422);
                return;
            }
            $dist[$k] = $v;
        }
        if ($delta !== 6) {
            Response::error('La somme des bonus de stats doit être 6', ['error' => 'invalid_stat_distribution', 'expected_delta' => 6], 422);
            return;
        }

        if ($model->findById($playerId)) {
            Response::error('Personnage déjà existant', ['error' => 'character_exists'], 409);
            return;
        }

        if ($model->isCharacterNameTaken($characterName)) {
            Response::error('Nom de personnage déjà utilisé.', ['error' => 'character_name_taken'], 422);
            return;
        }

        // Bonus race
        if ($race === 'elf')      $dist['stat_dex'] += 1;
        if ($race === 'dwarf')    $dist['stat_con'] += 1;
        if ($race === 'half_orc') $dist['stat_for'] += 1;

        $skillPoints = ($race === 'human') ? 1 : 0;
        $hpMax       = Player::rollInitialHp($class, $dist['stat_con']);

        $data = array_merge($dist, [
            'player_id'              => $playerId,
            'character_name'         => $characterName,
            'class'                  => $class,
            'race'                   => $race,
            'hp_max'                 => $hpMax,
            'skill_points_available' => $skillPoints,
            'gps_consent'            => true,
        ]);

        if (!$model->create($data)) {
            Response::error('Erreur lors de la création du personnage', null, 500);
            return;
        }

        $player = $model->findById($playerId);
        Response::success('player_created', $this->formatPlayer($player, null), 201);
    }

    private function playerMe(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $model    = new Player();

        $model->applyPassiveRegen($playerId);

        $player = $model->findById($playerId);

        if (!$player) {
            Response::error('Personnage introuvable', null, 404);
            return;
        }

        $userStmt = \Database::getInstance()->getConnection()->prepare('SELECT email FROM users WHERE id = :id');
        $userStmt->execute([':id' => $playerId]);
        $userData = $userStmt->fetch(\PDO::FETCH_ASSOC);

        Response::success('player_profile', $this->formatPlayer($player, $userData['email'] ?? null));
    }

    private function playerRest(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $type     = $_GET['type'] ?? 'active';
        $model    = new Player();

        if (!$model->findById($playerId)) {
            Response::error('Personnage introuvable', null, 404);
            return;
        }

        $result = $model->rest($playerId, $type);

        if (isset($result['error'])) {
            if ($result['error'] === 'rest_cooldown') {
                Response::error(
                    "Repos en cooldown jusqu'à {$result['rest_available_at']}",
                    ['error' => 'rest_cooldown'],
                    409
                );
                return;
            }
            Response::error($result['error'], null, $result['code']);
            return;
        }

        Response::success('rest_applied', $this->formatPlayer($result['player'], null));
    }

    private function playerLevelUp(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $model    = new Player();

        if (!$model->findById($playerId)) {
            Response::error('Personnage introuvable', null, 404);
            return;
        }

        $result = $model->levelUp($playerId);

        if (isset($result['already_max'])) {
            Response::success('level_up', ['already_max' => true]);
            return;
        }

        // Check level achievements
        $achService      = new AchievementService();
        $newAchievements = $achService->checkAfterVictory($playerId, 0, $result['new_level']);
        $result['new_achievements'] = $newAchievements ?: null;

        Response::success('level_up', $result);
    }

    private function playerSettings(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $input    = Response::getRequestParams();
        $model    = new Player();

        $allowed = [];
        if (isset($input['location_visibility'])) {
            $valid = ['all','group','friends'];
            if (!in_array($input['location_visibility'], $valid, true)) {
                Response::error('location_visibility invalide', null, 422);
                return;
            }
            $allowed['location_visibility'] = $input['location_visibility'];
        }
        if (isset($input['pvp_enabled'])) {
            $allowed['pvp_enabled'] = $input['pvp_enabled'] ? 1 : 0;
        }

        if (empty($allowed)) {
            Response::error('Aucun champ modifiable fourni', null, 400);
            return;
        }

        $model->updateSettings($playerId, $allowed);
        $player = $model->findById($playerId);
        if (!$player) {
            Response::error('Personnage introuvable', null, 404);
            return;
        }
        Response::success('settings_updated', $this->formatPlayer($player, null));
    }

    private function playerPushToken(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $input    = Response::getRequestParams();
        $fcmToken = $input['fcm_token'] ?? '';
        $deviceId = $input['device_id'] ?? null;

        if (!$fcmToken) {
            Response::error('fcm_token requis', null, 400);
            return;
        }

        $model = new Player();
        $model->upsertPushToken($playerId, $fcmToken, $deviceId);
        Response::success('push_token_registered', ['registered' => true]);
    }

    private function playerJournal(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $model    = new CombatSession();
        $journal  = $model->getJournal($playerId, 50);
        Response::success('player_journal', $journal);
    }

    private function playerAchievements(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $service  = new AchievementService();
        Response::success('player_achievements', $service->getAllWithStatus($playerId));
    }

    private function playerBestiary(array $user): void
    {
        $playerId = (int) $user['user_id'];
        $model    = new Player();
        $entries  = $model->getBestiary($playerId);

        $result = array_map(fn($e) => [
            'monster_id'   => (int) $e['monster_id'],
            'name'         => $e['name'],
            'asset_key'    => $e['asset_key'],
            'lore'         => $e['lore'],
            'kills'        => (int) $e['kills'],
            'first_kill_at' => $e['first_kill_at'],
        ], $entries);

        Response::success('player_bestiary', $result);
    }

    // =========================================================================
    // LEADERBOARD
    // =========================================================================

    private function leaderboard(array $user): void
    {
        $type  = $_GET['type']  ?? 'global';
        $value = $_GET['value'] ?? null;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

        $validTypes = ['global','biome','class'];
        if (!in_array($type, $validTypes, true)) {
            Response::error('type invalide (global, biome, class)', null, 422);
            return;
        }

        $model  = new Player();
        $rows   = $model->getLeaderboard($type, $value, $limit);
        $result = array_map(fn($r) => [
            'rank'         => (int) $r['rank'],
            'player_id'    => (int) $r['player_id'],
            'display_name' => $r['display_name'],
            'level'        => (int) $r['level'],
            'xp'           => (int) $r['xp'],
            'class'        => $r['class'],
        ], $rows);

        Response::success('leaderboard', $result);
    }

    private function playerCheckName(array $user): void
    {
        $name = trim($_GET['name'] ?? '');
        if ($name === '') {
            Response::error('Paramètre name requis', null, 400);
            return;
        }
        $model = new Player();
        Response::success('check_name', ['available' => !$model->isCharacterNameTaken($name)]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function formatPlayer(array $p, ?string $email): array
    {
        $level      = (int) $p['level'];
        $xp         = (int) $p['xp'];
        $xpNext     = Player::xpNextLevel($level);

        $restAt = !empty($p['rest_available_at'])
            ? gmdate('Y-m-d\TH:i:s\Z', strtotime($p['rest_available_at']))
            : null;

        return [
            'player_id'               => (int) $p['player_id'],
            'character_name'          => $p['character_name'],
            'email'                   => $email,
            'class'                   => $p['class'],
            'race'                    => $p['race'],
            'level'                   => $level,
            'xp'                      => $xp,
            'xp_next_level'           => $xpNext,
            'hp_current'              => (int) $p['hp_current'],
            'hp_max'                  => (int) $p['hp_max'],
            'stat_for'                => (int) $p['stat_for'],
            'stat_dex'                => (int) $p['stat_dex'],
            'stat_con'                => (int) $p['stat_con'],
            'stat_int'                => (int) $p['stat_int'],
            'stat_sag'                => (int) $p['stat_sag'],
            'stat_cha'                => (int) $p['stat_cha'],
            'skill_points_available'  => (int) $p['skill_points_available'],
            'gems'                    => (int) $p['gems'],
            'location_visibility'     => $p['location_visibility'],
            'pvp_enabled'             => (bool) $p['pvp_enabled'],
            'rest_available_at'       => $restAt,
        ];
    }
}
