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
    private array $pluginPublicHandlers = [];
    
    public function __construct() {
        parent::__construct(null);
        $this->controllers = [
            'users' => new UserController(),
            'groups' => new GroupController(),
        ];
        $this->loadPluginPublicHandlers();
    }
    
    /**
     * Charge les route handlers publics des plugins
     */
    private function loadPluginPublicHandlers(): void {
        try {
            if (isset($GLOBALS['plugin_manager'])) {
                $pluginManager = $GLOBALS['plugin_manager'];
                $publicHandlers = $pluginManager->getPublicRouteHandlers();
                
                foreach ($publicHandlers as $pluginName => $handlerClass) {
                    if (class_exists($handlerClass)) {
                        $this->pluginPublicHandlers[$pluginName] = new $handlerClass();
                        
                        if (defined('APP_DEBUG') && APP_DEBUG && class_exists('\AuthGroups\Services\LogService')) {
                            \AuthGroups\Services\LogService::info("Route handler public de plugin chargé", [
                                'plugin' => $pluginName,
                                'handler' => $handlerClass
                            ]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (defined('APP_DEBUG') && APP_DEBUG && class_exists('\AuthGroups\Services\LogService')) {
                \AuthGroups\Services\LogService::warning("Erreur lors du chargement des routes publiques de plugins", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    protected function getSupportedControllers(): array {
        return ['help', 'health', 'users', 'groups', 'secret-admin'];
    }
    
    protected function handleRoute(array $request) {
        $controller = $request['controller'];
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        
        // D'abord, essayer les route handlers publics des plugins
        foreach ($this->pluginPublicHandlers as $pluginName => $handler) {
            if (method_exists($handler, 'handleRoute')) {
                $result = $handler->handleRoute($request);
                if ($result === true || is_array($result)) {
                    return true; // Route traitée par le plugin
                }
            }
        }
        
        // Ensuite, traiter les routes publiques intégrées
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
            'api' => [
                'name' => 'AuthGroups API',
                'version' => '1.3.0',
                'documentation' => '/help'
            ],
            'endpoints' => [
                'public' => [
                    'GET / - Informations générales sur l\'API',
                    'GET /help - Aide et liste des endpoints',
                    'GET /health - Statut de santé de l\'API',
                    'POST /users/register - Inscription utilisateur',
                    'POST /users/login - Connexion utilisateur',
                    'POST /users/request-password-reset - Demande de réinitialisation de mot de passe',
                    'POST /users/reset-password - Réinitialisation de mot de passe avec token',
                    'POST /users/verify-email - Vérification d\'email',
                    'POST /users/resend-verification - Renvoi d\'email de vérification',
                    'GET /groups - Liste des groupes publics',
                    'POST /groups/join - Rejoindre un groupe avec code'
                ],
                'authenticated' => [
                    'users' => 'Gestion des utilisateurs (CRUD, avatar, password, restore)',
                    'groups' => 'Gestion des groupes (CRUD, members, invitations, search)',
                    'files' => 'Gestion des fichiers (upload, download, delete, restore)',
                    'tags' => 'Gestion des tags (CRUD, associations, search, most-used)',
                    'stats' => 'Statistiques et analytics (platform, users, groups)',
                    'api-keys' => 'Gestion des clés API (create, list, revoke, regenerate)',
                    'data' => 'Synchronisation des données hors-ligne'
                ],
                'webhooks' => [
                    'POST /webhook/payment - Webhook générique de paiement',
                    'POST /webhook/stripe - Webhook Stripe',
                    'POST /webhook/paypal - Webhook PayPal'
                ],
                'admin' => [
                    'secret-admin' => 'Endpoints d\'administration (authentification renforcée requise)'
                ]
            ],
            'authentication' => [
                'jwt' => 'Authorization: Bearer {token}',
                'api_key' => 'X-API-Key: {key}'
            ],
            'documentation' => 'Voir /src/auth_groups/docs/ pour la documentation complète'
        ];
        Response::success('help', $info);
    }

    private function showHealthInfo(): void {
        $info = [
            "status" => "OK",
            "message" => "API AuthGroups opérationnelle",
            "timestamp" => date('Y-m-d H:i:s'),
            "version" => "1.3.0"
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