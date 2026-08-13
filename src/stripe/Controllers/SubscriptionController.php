<?php

namespace Stripe\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\Group;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use Stripe\Services\StripeSubscriptionService;

class SubscriptionController
{
    private function isGroupAdmin(int $groupId, int $userId, string $userRole): bool
    {
        return (new Group())->isGroupAdmin($groupId, $userId) || RoleHelper::isAtLeast($userRole, 'ADMINISTRATEUR');
    }

    public function getStatus(array $user): void
    {
        LoggingMiddleware::logEntry();

        $appId   = $_GET['app_id'] ?? '';
        $groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id est requis', null, 422);
            return;
        }

        // Lecture seule : simple appartenance au groupe suffit, pas besoin d'être admin.
        if ($groupId && !(new Group())->isMember($groupId, $user['user_id'])) {
            LoggingMiddleware::logExit(403);
            Response::error('Vous devez être membre de ce groupe pour consulter son abonnement', [
                'code' => 'GROUP_MEMBERSHIP_REQUIRED',
            ], 403);
            return;
        }

        $result = StripeSubscriptionService::getStatus($user['user_id'], $appId, $groupId);

        LoggingMiddleware::logExit(200);
        Response::success('Statut récupéré', $result);
    }

    public function cancel(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId   = $input['app_id'] ?? $_GET['app_id'] ?? '';
        $groupId = isset($input['group_id']) ? (int) $input['group_id'] : (isset($_GET['group_id']) ? (int) $_GET['group_id'] : null);

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id est requis', null, 422);
            return;
        }

        if ($groupId && !$this->isGroupAdmin($groupId, $user['user_id'], $user['role'])) {
            LoggingMiddleware::logExit(403);
            Response::error('Seul un admin du groupe peut annuler cet abonnement', [
                'code' => 'GROUP_ADMIN_REQUIRED',
            ], 403);
            return;
        }

        try {
            StripeSubscriptionService::cancel($user['user_id'], $appId, $groupId);
        } catch (\RuntimeException $e) {
            LoggingMiddleware::logExit(422);
            Response::error($e->getMessage(), null, 422);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Abonnement Stripe annulé (fin de période)');
    }
}
