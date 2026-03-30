<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\PlanController;
use AuthGroups\Services\AuthService;
use AuthGroups\Utils\Response;

/**
 * Gestionnaire des routes pour les plans
 */
class PlanRouteHandler extends BaseRouteHandler
{
    private PlanController $planController;
    protected bool $requiresAuth = false; // Les plans sont publics

    public function __construct(?AuthService $authService = null)
    {
        parent::__construct($authService);
        $this->planController = new PlanController();
    }

    protected function getSupportedControllers(): array
    {
        return ['plans'];
    }

    protected function handleRoute(array $request): void
    {
        $method = $request['method'];
        $segments = $request['segments'];
        
        match(true) {
            // GET /plans - Lister tous les plans disponibles (public)
            ($method === 'GET' && count($segments) === 1) =>
                $this->planController->listPlans(),

            // GET /plans/{id} - Détails d'un plan spécifique (public)
            ($method === 'GET' && count($segments) === 2 && is_numeric($segments[1])) =>
                $this->planController->getPlan((int)$segments[1]),

            default => Response::error('Route de plan non trouvée', null, 404)
        };
    }
}