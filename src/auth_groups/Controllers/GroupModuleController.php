<?php

namespace AuthGroups\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\Group;
use AuthGroups\Models\TenantModule;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use Stripe\Config\CmemModules;
use Stripe\Services\EntitlementService;

/**
 * Registre de modules activables au niveau du GROUPE — GET/PATCH /groups/{id}/modules[/{key}].
 * Directive cmem_web 20260813_143000 (plan-equipe).
 *
 * Même contrat (available/enabled/quota) et mêmes codes d'erreur que ModuleController, mais
 * keyed sur group_id : « disponible » suit le plan du groupe (EntitlementService::
 * getEffectivePlanForGroup), « activé » est décidé par un admin du groupe.
 */
class GroupModuleController
{
    private const DEFAULT_APP_ID = 'puzzle';

    private TenantModule $model;

    public function __construct()
    {
        $this->model = new TenantModule();
    }

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : self::DEFAULT_APP_ID;
    }

    private function toContract(string $key, string $planCode, ?array $row): array
    {
        $available = CmemModules::isAvailable($planCode, $key);
        $enabled   = $row !== null
            ? ((int) $row['enabled'] === 1)
            : CmemModules::isEnabledByDefault($key);

        $quota = null;
        if (CmemModules::hasQuota($key)) {
            $resetAt = $row['quota_reset_at'] ?? null;
            $quota = [
                'used'     => (int) ($row['quota_used'] ?? 0),
                'limit'    => CmemModules::quotaLimit($planCode, $key),
                'reset_at' => $resetAt ?: CmemModules::nextQuotaReset(),
            ];
        }

        return [
            'key'       => $key,
            'available' => $available,
            'enabled'   => $available && $enabled,
            'quota'     => $quota,
        ];
    }

    /** GET /groups/{id}/modules — lecture ouverte à tout membre du groupe. */
    public function index(int $groupId, int $userId): void
    {
        if (!(new Group())->isMember($groupId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Vous devez être membre de ce groupe pour consulter ses modules', [
                'code' => 'GROUP_MEMBERSHIP_REQUIRED',
            ], 403);
            return;
        }

        $plan = EntitlementService::getEffectivePlanForGroup($groupId)['code'];
        $rows = $this->model->findAllByGroup($groupId);

        $modules = [];
        foreach (CmemModules::KEYS as $key) {
            $modules[] = $this->toContract($key, $plan, $rows[$key] ?? null);
        }

        Response::success('Modules du groupe récupérés', ['plan' => $plan, 'modules' => $modules]);
    }

    /** PATCH /groups/{id}/modules/{key} — admin du groupe requis. */
    public function update(int $groupId, int $userId, string $userRole, string $key): void
    {
        if (!CmemModules::isValidKey($key)) {
            LoggingMiddleware::logExit(422);
            Response::error("Module « {$key} » inconnu", ['code' => 'UNKNOWN_MODULE_KEY'], 422);
            return;
        }

        if (!(new Group())->isGroupAdmin($groupId, $userId) && !RoleHelper::isAtLeast($userRole, 'ADMINISTRATEUR')) {
            LoggingMiddleware::logExit(403);
            Response::error('Seul un admin du groupe peut modifier ses modules', [
                'code' => 'GROUP_ADMIN_REQUIRED',
            ], 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
            LoggingMiddleware::logExit(422);
            Response::error('Données de validation invalides',
                ['code' => 'VALIDATION_ERROR', 'enabled' => ['booléen requis']], 422);
            return;
        }

        $plan = EntitlementService::getEffectivePlanForGroup($groupId)['code'];

        if (!CmemModules::isAvailable($plan, $key)) {
            LoggingMiddleware::logExit(403);
            Response::error("Le module « {$key} » n'est pas inclus dans le plan de ce groupe.",
                ['code' => 'MODULE_NOT_AVAILABLE', 'module' => $key, 'plan' => $plan], 403);
            return;
        }

        $row = $this->model->setEnabledForGroup($groupId, $this->appId($input), $key, $input['enabled']);

        Response::success('Module de groupe mis à jour', ['module' => $this->toContract($key, $plan, $row)]);
    }
}
