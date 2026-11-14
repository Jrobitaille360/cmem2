<?php

namespace Assistant\Services;

use RuntimeException;

/** Budget IA (personnel ou global) atteint avant le premier appel modèle → 429 AI_BUDGET_EXCEEDED. */
class AssistantBudgetException extends RuntimeException
{
    public function __construct(public readonly string $scope, public readonly array $budget)
    {
        parent::__construct('AI_BUDGET_EXCEEDED');
    }
}
