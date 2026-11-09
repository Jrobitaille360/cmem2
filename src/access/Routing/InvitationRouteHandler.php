<?php

namespace Access\Routing;

use Access\Controllers\AccessApprovalController;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;

/**
 * Invitations d'accès (plan #269, Phase 2) — ADMINISTRATEUR+.
 *
 *   POST   /invitations
 *   GET    /invitations
 *   DELETE /invitations/{id}
 */
class InvitationRouteHandler extends BaseRouteHandler
{
    private AccessApprovalController $controller;

    public function __construct($authService)
    {
        parent::__construct($authService);
        $this->controller = new AccessApprovalController();
    }

    protected function getSupportedControllers(): array
    {
        return ['invitations'];
    }

    protected function handleRoute(array $request): void
    {
        $action = $request['action'];
        $method = $request['method'];
        $user   = $request['user'];

        match (true) {
            ($action === '' && $method === 'POST') =>
                $this->controller->createInvitation($user),

            ($action === '' && $method === 'GET') =>
                $this->controller->listInvitations($user),

            (ctype_digit((string) $action) && $method === 'DELETE' && !isset($request['segments'][2])) =>
                $this->controller->deleteInvitation((int) $action, $user),

            default => Response::error('Route non trouvée', null, 404),
        };
    }
}
