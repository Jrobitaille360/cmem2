<?php

namespace Pomo\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Pomo\Models\Engagement;
use Pomo\Validators\EngagementValidator;

/**
 * EngagementController — POST /pomo/engagement
 * Phase 1A — public, sans auth.
 *
 * type=waitlist : valide courriel, vérifie doublon (409), insère.
 * type=survey   : valide 5 réponses yes|no|maybe, insère (multiples par device acceptés).
 */
class EngagementController
{
    public function submit(): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $type = $input['type'] ?? '';

        if (!in_array($type, ['waitlist', 'survey'], true)) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', [
                ['field' => 'type', 'code' => 'invalid_value', 'message' => "type doit être 'waitlist' ou 'survey'"]
            ], 422);
            return;
        }

        if ($type === 'waitlist') {
            $this->handleWaitlist($input);
        } else {
            $this->handleSurvey($input);
        }
    }

    private function handleWaitlist(array $input): void
    {
        $validation = EngagementValidator::validateWaitlist($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $model = new Engagement();

        if ($model->emailExists($input['email'])) {
            LoggingMiddleware::logExit(409);
            Response::error('Courriel déjà enregistré dans la waitlist', null, 409);
            return;
        }

        $id = $model->createWaitlist($input);
        LoggingMiddleware::logExit(201);
        Response::success('Inscription enregistrée', ['reference_id' => $id], 201);
    }

    private function handleSurvey(array $input): void
    {
        $validation = EngagementValidator::validateSurvey($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $model = new Engagement();
        $id    = $model->createSurvey($input);
        LoggingMiddleware::logExit(201);
        Response::success('Sondage enregistré', ['reference_id' => $id], 201);
    }
}
