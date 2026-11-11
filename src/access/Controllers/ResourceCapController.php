<?php

namespace Access\Controllers;

use Access\Config\ResourceCaps;
use Access\Services\ResourceCapService;
use AuthGroups\Models\Group;
use AuthGroups\Models\User;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;

/**
 * Caps de ressources — endpoints admin (ADMINISTRATEUR+), plan #269 Phase 3 bis (#295).
 *
 *   GET /caps/defaults
 *   GET /users/{id}/caps     → { user_id, overrides, effective }
 *   PUT /users/{id}/caps     { caps: { max_contacts: 2, max_tasks: null } }  (remplace toutes les surcharges)
 *   GET /groups/{id}/caps    → { group_id, overrides, effective }
 *   PUT /groups/{id}/caps    { caps: { max_group_members: 10 } }
 *
 * value null = illimité explicite ; {} = retour au défaut.
 */
class ResourceCapController
{
    private ResourceCapService $service;

    public function __construct(?ResourceCapService $service = null)
    {
        $this->service = $service ?? new ResourceCapService();
    }

    private function requireAdmin(array $user): bool
    {
        if (!RoleHelper::isAtLeast($user['role'] ?? null, 'ADMINISTRATEUR')) {
            LogService::warning('Accès admin caps refusé', [
                'user_id' => $user['user_id'] ?? null,
                'role'    => $user['role'] ?? null,
            ]);
            Response::error('Accès non autorisé', null, 403);
            return false;
        }
        return true;
    }

    public function getDefaults(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }
        Response::success('Caps par défaut', [
            'caps'       => ResourceCaps::defaults(),
            'group_keys' => ResourceCaps::groupKeys(),
        ]);
    }

    public function getUserCaps(int $targetId, array $user): void
    {
        if (!$this->requireAdmin($user) || !$this->userExists($targetId)) {
            return;
        }
        $this->respondUser($targetId, 'Caps de l\'utilisateur');
    }

    public function putUserCaps(int $targetId, array $user): void
    {
        if (!$this->requireAdmin($user) || !$this->userExists($targetId)) {
            return;
        }
        $caps = $this->readCaps(false);
        if ($caps === null) {
            return;
        }
        $this->service->replaceUserOverrides($targetId, $caps, (int) $user['user_id']);
        LogService::info('Caps utilisateur remplacés', [
            'target_id' => $targetId,
            'caps'      => $caps,
            'by'        => $user['user_id'] ?? null,
        ]);
        $this->respondUser($targetId, 'Caps de l\'utilisateur mis à jour');
    }

    public function getGroupCaps(int $groupId, array $user): void
    {
        if (!$this->requireAdmin($user) || !$this->groupExists($groupId)) {
            return;
        }
        $this->respondGroup($groupId, 'Caps du groupe');
    }

    public function putGroupCaps(int $groupId, array $user): void
    {
        if (!$this->requireAdmin($user) || !$this->groupExists($groupId)) {
            return;
        }
        $caps = $this->readCaps(true);
        if ($caps === null) {
            return;
        }
        $this->service->replaceGroupOverrides($groupId, $caps, (int) $user['user_id']);
        LogService::info('Caps groupe remplacés', [
            'group_id' => $groupId,
            'caps'     => $caps,
            'by'       => $user['user_id'] ?? null,
        ]);
        $this->respondGroup($groupId, 'Caps du groupe mis à jour');
    }

    /** Lit et valide body.caps ; émet 422 et retourne null si invalide. */
    private function readCaps(bool $groupLevel): ?array
    {
        $input = Response::getRequestParams();
        if (!array_key_exists('caps', $input)) {
            Response::error('Données de validation invalides', ['caps' => 'champ requis'], 422);
            return null;
        }
        $errors = ResourceCapService::validate($input['caps'], $groupLevel);
        if ($errors) {
            Response::error('Données de validation invalides', $errors, 422);
            return null;
        }
        return $input['caps'];
    }

    private function userExists(int $id): bool
    {
        $u = (new User())->findById($id);
        if (!$u || !empty($u['deleted_at'])) {
            Response::error('Utilisateur non trouvé', null, 404);
            return false;
        }
        return true;
    }

    private function groupExists(int $id): bool
    {
        $g = (new Group())->findById($id);
        if (!$g || !empty($g['deleted_at'])) {
            Response::error('Groupe non trouvé', null, 404);
            return false;
        }
        return true;
    }

    private function respondUser(int $id, string $message): void
    {
        Response::success($message, [
            'user_id'   => $id,
            'overrides' => (object) $this->service->userOverrides($id),
            'effective' => $this->service->effectiveForUser($id),
        ]);
    }

    private function respondGroup(int $id, string $message): void
    {
        Response::success($message, [
            'group_id'  => $id,
            'overrides' => (object) $this->service->groupOverrides($id),
            'effective' => $this->service->effectiveForGroup($id),
        ]);
    }
}
