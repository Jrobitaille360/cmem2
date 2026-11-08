<?php

namespace Access\Services;

use Access\Config\AiPricing;
use Database;
use PDO;

/**
 * Budget IA mensuel en dollars — plan accès sur approbation, Phase 1 (tasks cmem #274, #275).
 *
 * Deux plafonds, vérifiés avant chaque appel modèle :
 *  - personnel : users.ai_budget_usd_monthly, NULL = AI_DEFAULT_BUDGET_USD (admin inclus) ;
 *  - global    : AI_GLOBAL_BUDGET_USD_MONTHLY, somme de tous les usagers du mois.
 *
 * Réservation puis ajustement : reserve() insère dans ai_usage une ligne au coût MAXIMAL
 * estimé (entrée estimée + max_tokens) sous verrou nommé, ce qui empêche deux appels
 * concurrents de passer ensemble sous le plafond ; settle() la ramène au coût réel.
 * Le mois est calculé côté base (NOW()) pour rester cohérent avec created_at.
 */
class AiBudgetService
{
    private const LOCK_NAME    = 'cmem2_ai_budget';
    private const LOCK_TIMEOUT = 5;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /** Date de remise à zéro : 1er du mois suivant, 00:00:00 (heure de la base). */
    public function resetAt(): string
    {
        return (string) $this->pdo
            ->query("SELECT DATE_FORMAT(DATE_ADD(LAST_DAY(NOW()), INTERVAL 1 DAY), '%Y-%m-%d 00:00:00')")
            ->fetchColumn();
    }

    public function limitFor(int $userId): float
    {
        $st = $this->pdo->prepare("SELECT ai_budget_usd_monthly FROM users WHERE id = ?");
        $st->execute([$userId]);
        $value = $st->fetchColumn();
        return ($value === null || $value === false) ? (float) AI_DEFAULT_BUDGET_USD : (float) $value;
    }

    public function spentBy(int $userId): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage
             WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $st->execute([$userId]);
        return (float) $st->fetchColumn();
    }

    public function spentGlobal(): float
    {
        return (float) $this->pdo
            ->query("SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")
            ->fetchColumn();
    }

    /** @return array{spent_usd: float, limit_usd: float, reset_at: string} */
    public function usage(int $userId): array
    {
        return [
            'spent_usd' => round($this->spentBy($userId), 6),
            'limit_usd' => $this->limitFor($userId),
            'reset_at'  => $this->resetAt(),
        ];
    }

    /**
     * Réserve le coût maximal estimé d'un appel.
     *
     * @return array{ok: true, usage_id: int}|array{ok: false, scope: string, budget: array}
     */
    public function reserve(int $userId, string $model, int $estimatedInputTokens, int $maxOutputTokens): array
    {
        $estimate = AiPricing::cost($model, $estimatedInputTokens, $maxOutputTokens);

        $locked = (int) $this->pdo->query(
            "SELECT GET_LOCK('" . self::LOCK_NAME . "', " . self::LOCK_TIMEOUT . ")"
        )->fetchColumn();
        if ($locked !== 1) {
            // Verrou non obtenu : refuser plutôt que laisser passer un appel non budgété.
            return ['ok' => false, 'scope' => 'global', 'budget' => $this->usage($userId)];
        }

        try {
            if ($this->spentGlobal() + $estimate > (float) AI_GLOBAL_BUDGET_USD_MONTHLY) {
                return ['ok' => false, 'scope' => 'global', 'budget' => $this->usage($userId)];
            }
            if ($this->spentBy($userId) + $estimate > $this->limitFor($userId)) {
                return ['ok' => false, 'scope' => 'user', 'budget' => $this->usage($userId)];
            }

            $this->pdo->prepare(
                "INSERT INTO ai_usage (user_id, model, input_tokens, output_tokens, cost_usd)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$userId, $model, $estimatedInputTokens, $maxOutputTokens, $estimate]);

            return ['ok' => true, 'usage_id' => (int) $this->pdo->lastInsertId()];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /** Ajuste une réservation au coût réel (0 si l'appel modèle a échoué : non facturé). */
    public function settle(int $usageId, string $model, int $inputTokens, int $outputTokens): void
    {
        $this->pdo->prepare(
            "UPDATE ai_usage SET input_tokens = ?, output_tokens = ?, cost_usd = ? WHERE id = ?"
        )->execute([$inputTokens, $outputTokens, AiPricing::cost($model, $inputTokens, $outputTokens), $usageId]);
    }
}
