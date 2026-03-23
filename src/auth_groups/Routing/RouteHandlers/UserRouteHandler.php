<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\UserController;
use AuthGroups\Controllers\PlanController;
use AuthGroups\Utils\Response;
use ICS\Controllers\NotificationController;

class UserRouteHandler extends BaseRouteHandler 
{
    private UserController $controller;
    private PlanController $planController;
    private ?NotificationController $notificationController = null;

    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new UserController();
        $this->planController = new PlanController();
    }

    private function getNotificationController(): NotificationController
    {
        if ($this->notificationController === null) {
            $this->notificationController = new NotificationController();
        }
        return $this->notificationController;
    }
    
    protected function getSupportedControllers(): array {
        return ['users'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        $segments = $request['segments'];
        
        match(true) {
            // POST /users/avatar
            ($action === 'avatar' && $method === 'POST' && !$id) => 
                $this->controller->uploadAvatar($user['user_id'], $user['user_id'], $user['role']),

            // POST /users/{id}/avatar
            (isset($segments[2]) && $segments[2] === 'avatar' && $method === 'POST') =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->uploadAvatar($targetId, $user['user_id'], $user['role'])),

            // PUT /users/password
            ($action === 'password' && $method === 'PUT' && !$id) =>
                $this->controller->changePassword($user['user_id'], $user['user_id'], $user['role']),

            // PUT /users/{id}/password
            (isset($segments[2]) && $segments[2] === 'password' && $method === 'PUT') =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->changePassword($targetId, $user['user_id'], $user['role'])),

            // POST /users/logout
            ($action === 'logout' && $method === 'POST') => 
                $this->controller->logout($user['user_id']),

            // GET /users/choose-plan?token=xxx - Afficher invitation plan (public)
            ($action === 'choose-plan' && $method === 'GET') =>
                $this->handlePlanInvitationView(),

            // POST /users/choose-plan - Sélectionner un plan via token (public)
            ($action === 'choose-plan' && $method === 'POST') =>
                $this->handlePlanSelection(),

            // GET /users
            ($action === '' && $method === 'GET') =>
                $this->controller->getAll($user['role']),

            // GET /users/me
            ($action === 'me' && $method === 'GET') =>
                $this->controller->getById($user['user_id'], $user['user_id'], $user['role']),
            
            // PUT /users/me
            ($action === 'me' && $method === 'PUT') =>
                $this->controller->updateProfile($user['user_id'], $user['user_id'], $user['role']),

            // DELETE /users/me
            ($action === 'me' && $method === 'DELETE') =>
                $this->controller->delete($user['user_id'], $user['user_id'], $user['role']),

            // POST /users/{id}/restore
            (isset($segments[2]) && $segments[2] === 'restore' && $method === 'POST') =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->restore($targetId, $user['user_id'], $user['role'])),

            // GET /users/{id}
            ($action && ctype_digit($action) && $method === 'GET' && !$id) =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->getById($targetId, $user['user_id'], $user['role'])),

            // PUT /users/{id}
            ($action && ctype_digit($action) && $method === 'PUT' && !$id) =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->updateProfile($targetId, $user['user_id'], $user['role'])),

            // DELETE /users/{id}
            ($action && ctype_digit($action) && $method === 'DELETE' && !$id) =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->delete($targetId, $user['user_id'], $user['role'])),

            // GET /users/me/notification-preferences
            ($action === 'me' && isset($segments[2]) && $segments[2] === 'notification-preferences' && $method === 'GET') =>
                $this->getNotificationController()->getPreferences($user['user_id']),

            // PUT /users/me/notification-preferences
            ($action === 'me' && isset($segments[2]) && $segments[2] === 'notification-preferences' && $method === 'PUT') =>
                $this->getNotificationController()->updatePreferences($user['user_id']),

            // POST /users/app/
            ($action === 'app' && $method === 'POST' && !$id) =>
                $this->controller->createAppSetup($user['user_id']),

            // GET /users/app/
            ($action === 'app' && $method === 'GET' && !$id) =>
                $this->controller->getAppSetups($user['user_id']),

            // GET /users/app/{app_id}
            (isset($segments[2]) && $segments[1] === 'app' && $method === 'GET') =>
                $this->controller->getAppSetup($user['user_id'], $segments[2]),

            // PUT /users/app/{app_id}
            (isset($segments[2]) && $segments[1] === 'app' && $method === 'PUT') =>
                $this->controller->updateAppSetup($user['user_id'], $segments[2]),

            // DELETE /users/app/{app_id}
            (isset($segments[2]) && $segments[1] === 'app' && $method === 'DELETE') =>
                $this->controller->deleteAppSetup($user['user_id'], $segments[2]),

            default => Response::error('Route utilisateur non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID utilisateur'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }

    /**
     * Gère l'affichage d'une invitation plan (public)
     */
    private function handlePlanInvitationView(): void {
        // Cette route est publique, donc on désactive temporairement l'auth
        $originalRequiresAuth = $this->requiresAuth;
        $this->requiresAuth = false;
        
        $this->planController->viewPlanInvitation();
        
        // Remettre l'auth comme avant
        $this->requiresAuth = $originalRequiresAuth;
    }

    /**
     * Gère la sélection d'un plan (public)
     */
    private function handlePlanSelection(): void {
        // Cette route est publique, donc on désactive temporairement l'auth
        $originalRequiresAuth = $this->requiresAuth;
        $this->requiresAuth = false;
        
        $this->planController->choosePlan();
        
        // Remettre l'auth comme avant
        $this->requiresAuth = $originalRequiresAuth;
    }
}