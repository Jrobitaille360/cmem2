<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\{
    UserController, 
    GroupController, 
};

use AuthGroups\Utils\Response;

class PublicRouteHandler extends BaseRouteHandler 
{
    protected bool $requiresAuth = false;
    private array $controllers;
    
    public function __construct() {
        parent::__construct(null);
        $this->controllers = [
            'users' => new UserController(),
            'groups' => new GroupController(),
        ];
    }
    
    protected function getSupportedControllers(): array {
        return ['help', 'health', 'users', 'groups','secret-admin'];
    }
    
    protected function handleRoute(array $request) {
        $controller = $request['controller'];
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        
        $res= match(true) {
            // Routes d'information
            ($controller === 'help' && $action === '' && $method === 'GET') => 
                $this->showHelpInfo(),
                
            ($controller === 'health' && $action === '' && $method === 'GET') => 
                $this->showHealthInfo(),                
                   
            // Routes publiques des groupes
            ($controller === 'groups' && $action === '' && $method === 'GET') => 
                $this->controllers['groups']->getPublicGroups(),
            
            // POST groups/join - Rejoindre un groupe avec code
            ($controller === 'groups' && $action === 'join' && $method === 'POST') => 
                $this->controllers['groups']->joinByCode(),
                
            // Route d'inscription publique
            ($controller === 'users' && $action === 'register' && $method === 'POST') => 
                $this->controllers['users']->create(),
                
            // Route de connexion publique
            ($controller === 'users' && $action === 'login' && $method === 'POST') => 
                $this->controllers['users']->authenticate(),
                
            // Route de demande de changement de mot de passe publique
            ($controller === 'users' && $action === 'request-password-reset' && $method === 'POST') => 
                $this->controllers['users']->requestPasswordChange(),
                
            // Route de changement de mot de passe avec token publique
            ($controller === 'users' && $action === 'reset-password' && $method === 'POST') => 
                $this->controllers['users']->changePasswordToken(),
                
            // Route de vérification email publique
            ($controller === 'users' && $action === 'verify-email' && $method === 'POST') => 
                $this->controllers['users']->confirmEmail(),
                
            // Route de renvoi d'email de vérification publique
            ($controller === 'users' && $action === 'resend-verification' && $method === 'POST') => 
                $this->controllers['users']->resendVerificationEmail(),
                
            default => false
        };
        return $res;
    }
    
    private function showHelpInfo(): void {
        $info = [
            'endpoints' => [
                'public' => [
                    'POST /help - Informations d\'aide sur l\'API',
                    'POST /health - Statut de santé de l\'API',
                    'POST /users/register - Inscription utilisateur',
                    'POST /users/login - Connexion utilisateur',
                    'POST /groups/public - Groupes publics'
                ],
                'authenticated' => [
                    'users' => 'Gestion des utilisateurs',
                    'groups' => 'Gestion des groupes',
                    'tags' => 'Gestion des tags'
                ]
            ]
        ];
        Response::success('help', $info);
    }

    private function showHealthInfo(): void {
        $info = [
            "status" => "OK",
            "message" => "API AuthGroups opérationnelle",
            "timestamp" => date('Y-m-d H:i:s'),
            "version" => "1.2.0"
        ];
        Response::success('health_status', $info);
    }

    private function hasAuthToken(): bool {
        $authHeader = null;

        // 1. Standard
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        // 2. Apache mod_rewrite
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        // 3. Fallback: apache_request_headers (fonctionne seulement si Apache)
        elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            } elseif (isset($headers['authorization'])) {
                $authHeader = $headers['authorization'];
            }
        }
        
        return !empty($authHeader) && strpos($authHeader, 'Bearer ') === 0;
    }

}