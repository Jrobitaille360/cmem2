<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\TenantModule;
use AuthGroups\Models\User;
use AuthGroups\Services\AiSummarizeService;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use Access\Services\AiBudgetService;
use Stripe\Config\CmemModules;
use Stripe\Services\EntitlementService;
use Throwable;

/**
 * Proxy IA — POST /ai/summarize. Directive cmem_web 20260810_140000_ai-proxy.
 *
 * Budget IA en dollars (plan accès sur approbation, Phase 1) : le coût maximal estimé est
 * réservé AVANT l'appel au modèle (anti-course entre requêtes concurrentes), puis ajusté
 * au coût réel ; ramené à 0 si l'appel modèle échoue. Remplace l'ancien quota en nombre
 * d'appels (tenant_modules.quota_used), plus utilisé pour le module ia.
 *
 *   POST /ai/summarize → résumé d'agenda
 *   GET  /ai/usage     → dépense du mois / budget / remise à zéro
 */
class AiController
{
    private const MODULE_KEY = 'ia';

    private TenantModule $model;

    public function __construct()
    {
        $this->model = new TenantModule();
    }

    private function planCode(int $userId): string
    {
        $userData = (new User())->findById($userId);
        $override = $userData['cmem_plan_override'] ?? null;
        return EntitlementService::getEffectivePlanForCmem($userId, $override)['code'];
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

        // Budget IA en dollars — réservation au coût maximal avant l'appel modèle (voir AiBudgetService).
        $budgetService = new AiBudgetService();
        $reservation   = $budgetService->reserve(
            $userId,
            AI_SUMMARIZE_MODEL,
            AiSummarizeService::estimateInputTokens((array) $input['period'], (array) $input['items']),
            AI_SUMMARIZE_MAX_TOKENS
        );

        if (!$reservation['ok']) {
            LoggingMiddleware::logExit(429);
            Response::error(
                $reservation['scope'] === 'global'
                    ? "Budget IA global du mois atteint : l'IA est suspendue jusqu'à la remise à zéro."
                    : 'Budget IA mensuel atteint.',
                [
                    'code'   => 'AI_BUDGET_EXCEEDED',
                    'scope'  => $reservation['scope'],
                    'budget' => $reservation['budget'],
                ],
                429
            );
            return;
        }

        try {
            $result = AiSummarizeService::summarize($input['period'], $input['items']);
        } catch (Throwable $e) {
            $budgetService->settle($reservation['usage_id'], AI_SUMMARIZE_MODEL, 0, 0);
            LogService::error('Échec appel modèle IA (réservation annulée)', [
                'owner_id' => $userId,
                'error'    => $e->getMessage(),
            ]);
            LoggingMiddleware::logExit(502);
            Response::error("Erreur du service IA", null, 502);
            return;
        }

        $budgetService->settle(
            $reservation['usage_id'],
            AI_SUMMARIZE_MODEL,
            (int) ($result['input_tokens'] ?? 0),
            (int) ($result['output_tokens'] ?? 0)
        );

        LogService::info('Résumé IA généré', [
            'owner_id'      => $userId,
            'model'         => AI_SUMMARIZE_MODEL,
            'input_tokens'  => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ]);

        LoggingMiddleware::logExit(200);
        Response::success('Résumé généré', [
            'summary' => $result['summary'],
            'budget'  => $budgetService->usage($userId),
        ]);
    }

    /** GET /ai/usage — dépense du mois, budget personnel, date de remise à zéro. */
    public function usage(array $user): void
    {
        LoggingMiddleware::logEntry();
        LoggingMiddleware::logExit(200);
        Response::success('Budget IA', [
            'budget' => (new AiBudgetService())->usage((int) $user['user_id']),
        ]);
    }
}
