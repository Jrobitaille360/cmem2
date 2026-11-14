<?php

namespace Assistant\Routing;

use Assistant\Controllers\AssistantController;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;

/**
 * Routes /assistant/* — toutes authentifiées (401 sans JWT, via BaseRouteHandler),
 * toutes soumises au module « assistant » coché et activé (403 MODULE_NOT_AVAILABLE).
 *
 *   GET|POST|DELETE /assistant/consent
 *   POST            /assistant/chat                       (JSON, ou SSE si Accept: text/event-stream)
 *   GET|DELETE      /assistant/conversations
 *   GET|DELETE      /assistant/conversations/{id}
 *   GET             /assistant/actions[?status=PENDING]
 *   POST            /assistant/actions/{id}/confirm|reject
 *   GET             /assistant/usage
 */
class AssistantRouteHandler extends BaseRouteHandler
{
    protected function getSupportedControllers(): array
    {
        return ['assistant'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method'] ?? 'GET';
        $segments = $request['segments'] ?? [];
        $userId   = (int) $request['user']['user_id'];

        $action = $segments[1] ?? '';
        $id     = $segments[2] ?? '';
        $sub    = $segments[3] ?? '';
        $isId   = $id !== '' && ctype_digit((string) $id);

        $c = new AssistantController();
        if (!$c->requireModule($userId)) {
            return;
        }

        match (true) {
            $action === 'consent' && $id === '' && $method === 'GET'    => $c->getConsent($userId),
            $action === 'consent' && $id === '' && $method === 'POST'   => $c->grantConsent($userId),
            $action === 'consent' && $id === '' && $method === 'DELETE' => $c->revokeConsent($userId),

            $action === 'chat' && $id === '' && $method === 'POST' => $c->chat($userId),

            $action === 'conversations' && $id === '' && $method === 'GET'    => $c->listConversations($userId),
            $action === 'conversations' && $id === '' && $method === 'DELETE' => $c->deleteAllConversations($userId),
            $action === 'conversations' && $isId && $sub === '' && $method === 'GET'    => $c->getConversation($userId, (int) $id),
            $action === 'conversations' && $isId && $sub === '' && $method === 'DELETE' => $c->deleteConversation($userId, (int) $id),

            $action === 'actions' && $id === '' && $method === 'GET'                    => $c->listActions($userId),
            $action === 'actions' && $isId && $sub === 'confirm' && $method === 'POST'  => $c->resolveAction($userId, (int) $id, true),
            $action === 'actions' && $isId && $sub === 'reject' && $method === 'POST'   => $c->resolveAction($userId, (int) $id, false),

            $action === 'usage' && $id === '' && $method === 'GET' => $c->usage($userId),

            default => Response::error('Endpoint non trouvé', null, 404),
        };
    }
}
