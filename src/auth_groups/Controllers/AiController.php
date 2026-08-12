<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\TenantModule;
use AuthGroups\Models\User;
use AuthGroups\Services\AiSummarizeService;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use Stripe\Config\CmemModules;
use Stripe\Services\EntitlementService;
use Throwable;

/**
 * Proxy IA — POST /ai/summarize. Directive cmem_web 20260810_140000_ai-proxy.
 *
 * Le quota est décompté AVANT l'appel au modèle (jamais après) : ça évite un appel
 * gratuit en cas de course entre deux requêtes concurrentes, au prix d'un quota
 * consommé même si l'appel modèle échoue ensuite — comportement voulu par la directive.
 */
class AiController
{
    private const MODULE_KEY = 'ia';

    private TenantModule $model;

    public function __construct()
    {
        $this->model = new TenantModule();
    }

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : 'cmemweb';
    }

    private function planCode(int $userId): string
    {
        $userData = (new User())->findById($userId);
        $override = $userData['cmem_plan_override'] ?? null;
        return EntitlementService::getEffectivePlanForCmem($userId, $override)['code'];
    }

    /** Quota "used" effectif pour la période en cours (0 si la période précédente est échue). */
    private function effectiveUsed(?array $row): int
    {
        if ($row === null) {
            return 0;
        }
        $resetAt = $row['quota_reset_at'] ?? null;
        if ($resetAt === null || strtotime($resetAt) <= time()) {
            return 0;
        }
        return (int) ($row['quota_used'] ?? 0);
    }

    public function summarize(array $user): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'period' => 'required',
            'items'  => 'required|array',
        ]);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $userId = (int) $user['user_id'];
        $appId  = $this->appId($input);
        $plan   = $this->planCode($userId);

        $row       = $this->model->findByOwnerAndKey($userId, self::MODULE_KEY);
        $available = CmemModules::isAvailable($plan, self::MODULE_KEY);
        $enabled   = $row !== null
            ? ((int) $row['enabled'] === 1)
            : CmemModules::isEnabledByDefault(self::MODULE_KEY);

        if (!$available || !$enabled) {
            LoggingMiddleware::logExit(403);
            Response::error("Le module « ia » n'est pas inclus dans votre plan ou n'est pas activé.",
                ['code' => 'MODULE_NOT_AVAILABLE', 'module' => self::MODULE_KEY], 403);
            return;
        }

        $limit = CmemModules::quotaLimit($plan, self::MODULE_KEY);
        $used  = $this->effectiveUsed($row);

        if ($limit !== null && $used >= $limit) {
            LoggingMiddleware::logExit(429);
            Response::error('Quota mensuel du module IA atteint.', [
                'code'  => 'AI_QUOTA_EXCEEDED',
                'quota' => [
                    'used'     => $used,
                    'limit'    => $limit,
                    'reset_at' => $row['quota_reset_at'] ?? CmemModules::nextQuotaReset(),
                ],
            ], 429);
            return;
        }

        // Décompte avant l'appel modèle — voir note de classe.
        $newUsed = $this->model->incrementQuota($userId, $appId, self::MODULE_KEY, CmemModules::nextQuotaReset());
        $updatedRow = $this->model->findByOwnerAndKey($userId, self::MODULE_KEY);

        try {
            $result = AiSummarizeService::summarize($input['period'], $input['items']);
        } catch (Throwable $e) {
            LogService::error('Échec appel modèle IA (quota déjà décompté)', [
                'owner_id' => $userId,
                'error'    => $e->getMessage(),
            ]);
            LoggingMiddleware::logExit(502);
            Response::error("Erreur du service IA", null, 502);
            return;
        }

        LogService::info('Résumé IA généré', [
            'owner_id'      => $userId,
            'model'         => AI_SUMMARIZE_MODEL,
            'output_tokens' => $result['output_tokens'],
        ]);

        LoggingMiddleware::logExit(200);
        Response::success('Résumé généré', [
            'summary' => $result['summary'],
            'quota'   => [
                'used'     => $newUsed,
                'limit'    => $limit,
                'reset_at' => $updatedRow['quota_reset_at'] ?? CmemModules::nextQuotaReset(),
            ],
        ]);
    }
}
