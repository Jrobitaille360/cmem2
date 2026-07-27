<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\TenantModule;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Middleware\LoggingMiddleware;
use Stripe\Config\CmemModules;
use Stripe\Services\EntitlementService;

/**
 * Registre de modules activables — GET /modules, PATCH /modules/{key}.
 * Directive cmem_web 20260727_144926.
 *
 * L'autorité est serveur : le plan décide de « disponible », l'usager de « activé ».
 * Un PATCH sur un module non disponible est refusé (403 MODULE_NOT_AVAILABLE), quel
 * que soit ce que croit le client.
 */
class ModuleController
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

    /** Code du plan effectif cmem de l'usager ('free' | 'monthly' | 'yearly' | 'ami'). */
    private function planCode(int $userId): string
    {
        $userData = (new User())->findById($userId);
        $override = $userData['cmem_plan_override'] ?? null;
        return EntitlementService::getEffectivePlanForCmem($userId, $override)['code'];
    }

    /** Contrat d'un module pour le front : available / enabled / quota. */
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
            // Un module non disponible n'est jamais présenté comme actif, même si une
            // ligne héritée d'un ancien plan dit le contraire.
            'enabled'   => $available && $enabled,
            'quota'     => $quota,
        ];
    }

    /** GET /modules — état des 8 modules pour l'usager du JWT. Ne crée aucune ligne. */
    public function index(array $user): void
    {
        $userId = (int) $user['user_id'];
        $plan   = $this->planCode($userId);
        $rows   = $this->model->findAllByOwner($userId);

        $modules = [];
        foreach (CmemModules::KEYS as $key) {
            $modules[] = $this->toContract($key, $plan, $rows[$key] ?? null);
        }

        Response::success('Modules récupérés', ['plan' => $plan, 'modules' => $modules]);
    }

    /** PATCH /modules/{key} — l'usager allume ou éteint un module de son plan. */
    public function update(array $user, string $key): void
    {
        if (!CmemModules::isValidKey($key)) {
            LoggingMiddleware::logExit(422);
            Response::error("Module « {$key} » inconnu", ['code' => 'UNKNOWN_MODULE_KEY'], 422);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
            LoggingMiddleware::logExit(422);
            Response::error('Données de validation invalides',
                ['code' => 'VALIDATION_ERROR', 'enabled' => ['booléen requis']], 422);
            return;
        }

        $userId = (int) $user['user_id'];
        $plan   = $this->planCode($userId);

        if (!CmemModules::isAvailable($plan, $key)) {
            LoggingMiddleware::logExit(403);
            Response::error("Le module « {$key} » n'est pas inclus dans votre plan.",
                ['code' => 'MODULE_NOT_AVAILABLE', 'module' => $key, 'plan' => $plan], 403);
            return;
        }

        // Désactiver ne touche à aucune donnée métier : seul l'accès est coupé.
        $row = $this->model->setEnabled($userId, $this->appId($input), $key, $input['enabled']);

        Response::success('Module mis à jour', ['module' => $this->toContract($key, $plan, $row)]);
    }
}
