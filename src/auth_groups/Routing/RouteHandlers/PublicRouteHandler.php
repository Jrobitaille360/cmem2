<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\{
    UserController, 
    GroupController, 
};
use AuthGroups\Services\LogService;
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
                        if (defined('APP_DEBUG') && APP_DEBUG && class_exists(LogService::class)) {
                            LogService::info("Route handler public de plugin chargé", [
                                'plugin' => $pluginName,
                                'handler' => $handlerClass
                            ]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (defined('APP_DEBUG') && APP_DEBUG && class_exists('\AuthGroups\Services\LogService')) {
                LogService::warning("Erreur lors du chargement des routes publiques de plugins", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    protected function getSupportedControllers(): array {
        return ['help', 'health', 'users', 'groups', 'secret-admin', 'test'];
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
            ($controller === '' && $action === '' && $method === 'GET') => 
                $this->showWelcomeInfo(),
                
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
            ($controller === 'users' && $action === 'resend-verification-email' && $method === 'POST') =>
                $this->controllers['users']->resendVerificationEmail(),

            // Route d'injection OTP (développement uniquement)
            ($controller === 'test' && $action === 'inject-otp' && $method === 'POST') =>
                $this->devInjectOtp(),

            default => false
        };
        return $res;
    }
    
    private function showWelcomeInfo(): void {
        $info = [
            'api' => [
                'name' => 'CMEM2 API',
                'version' => '2.0.0',
                'description' => 'API complète de gestion d\'utilisateurs, groupes, fichiers, tags et plans',
                'status' => 'operational'
            ],
            'quick_start' => [
                'documentation' => 'GET /help - Liste complète des endpoints disponibles',
                'health_check' => 'GET /health - Vérifier l\'état de l\'API',
                'authentication' => 'JWT Bearer token requis (POST /auth/login ou POST /auth/verify-code)'
            ],
            'main_features' => [
                'users' => 'Gestion complète des utilisateurs (inscription, authentification, profils)',
                'groups' => 'Création et gestion de groupes avec rôles et permissions',
                'files' => 'Upload, stockage et gestion de fichiers avec versioning',
                'tags' => 'Système de tags avancé avec recherche et associations',
                'plans' => 'Gestion des plans d\'abonnement et facturation',
                'webhooks' => 'Intégration webhooks pour paiements (Stripe, PayPal)',
                'plugins' => 'Système de plugins extensible (ICS Calendar, etc.)'
            ],
            'resources' => [
                'help' => '/help',
                'docs' => '/src/auth_groups/docs/',
                'github' => 'https://github.com/Jrobitaille360/cmem2'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        Response::success('welcome', $info);
    }
    
    private function showHelpInfo(): void {
        $info = [
            'api' => [
                'name' => 'CMEM2 API',
                'version' => '2.0.0',
                'documentation' => '/help',
                'base_url' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'])
            ],
            'endpoints' => [
                'public' => [
                    'GET / - Informations générales sur l\'API',
                    'GET /help - Aide et liste complète des endpoints',
                    'GET /health - Statut de santé de l\'API',
                    'GET /plans - Liste des plans d\'abonnement disponibles',
                    'POST /users/register - Inscription d\'un nouvel utilisateur',
                    'POST /auth/login - Connexion email + password → JWT (15 jours)',
                    'POST /auth/send-code - Envoyer un code OTP par email',
                    'POST /auth/verify-code - Vérifier le code OTP → JWT (15 jours)',
                    'POST /auth/refresh - Renouveler le JWT via device token (sans re-login)',
                    'POST /auth/logout - Déconnexion (JWT requis)',
                    'GET /auth/devices - Lister les appareils de confiance (JWT requis)',
                    'DELETE /auth/devices/{device_id} - Révoquer un appareil (JWT requis)',
                    'POST /users/request-password-reset - Demande de réinitialisation de mot de passe',
                    'POST /users/reset-password - Réinitialisation de mot de passe avec token',
                    'POST /users/verify-email - Vérification d\'email avec token',
                    'POST /users/resend-verification-email - Renvoi d\'email de vérification',
                    'GET /groups - Liste des groupes publics',
                    'POST /groups/join - Rejoindre un groupe avec code d\'invitation'
                ],
                'authenticated' => [
                    'users' => [
                        'description' => 'Gestion des utilisateurs',
                        'operations' => 'CRUD complet, avatar, password, 2FA, restore'
                    ],
                    'groups' => [
                        'description' => 'Gestion des groupes',
                        'operations' => 'CRUD, membres, rôles, invitations, codes, search'
                    ],
                    'files' => [
                        'description' => 'Gestion des fichiers',
                        'operations' => 'upload, download, delete, restore, metadata, versioning'
                    ],
                    'tags' => [
                        'description' => 'Gestion des tags',
                        'operations' => 'CRUD, associations, search, most-used, suggestions'
                    ],
                    'plans' => [
                        'description' => 'Gestion des abonnements',
                        'operations' => 'subscribe, upgrade, cancel, billing history'
                    ],
                    'stats' => [
                        'description' => 'Statistiques et analytics',
                        'operations' => 'platform, users, groups, usage metrics'
                    ],
                    'api-keys' => [
                        'description' => 'Gestion des clés API',
                        'operations' => 'create, list, revoke, regenerate, scopes'
                    ],
                    'data' => [
                        'description' => 'Synchronisation hors-ligne',
                        'operations' => 'sync, conflict resolution, offline queue'
                    ]
                ],
                'plugins' => [
                    'ics' => [
                        'description' => 'Plugin de calendriers ICS',
                        'endpoints' => 'GET /calendars, POST /calendars, GET /calendars/{id}/events, etc.'
                    ]
                ],
                'webhooks' => [
                    'POST /webhook/payment - Webhook générique de paiement',
                    'POST /webhook/stripe - Webhook Stripe (signature verification)',
                    'POST /webhook/paypal - Webhook PayPal (IPN validation)'
                ],
                'admin' => [
                    'secret-admin' => 'Endpoints d\'administration (authentification super-admin requise)',
                    'operations' => 'user management, system config, logs, monitoring'
                ]
            ],
            'authentication' => [
                'jwt' => [
                    'header'     => 'Authorization: Bearer {jwt_token}',
                    'obtain'     => 'POST /auth/login (email+password) ou POST /auth/send-code + POST /auth/verify-code (OTP)',
                    'expiry'     => '15 jours',
                    'algorithm'  => 'HS256'
                ]
            ],
            'rate_limiting' => [
                'public_endpoints' => '100 requêtes/heure',
                'authenticated_endpoints' => '1000 requêtes/heure',
                'admin_endpoints' => 'Illimité'
            ],
            'documentation' => [
                'full_docs' => '/src/auth_groups/docs/',
                'api_reference' => '/src/auth_groups/docs/API_REFERENCE.md',
                'authentication_guide' => '/src/auth_groups/docs/AUTHENTICATION.md',
                'plugins_guide' => '/src/auth_groups/docs/PLUGINS.md'
            ],
            'support' => [
                'github_issues' => 'https://github.com/Jrobitaille360/cmem2/issues',
                'repository' => 'https://github.com/Jrobitaille360/cmem2'
            ]
        ];
        Response::success('help', $info);
    }

    private function showHealthInfo(): void {
        // Vérifier la connexion à la base de données
        $dbStatus = 'OK';
        $dbMessage = 'Connecté';
        try {
            $db = \Database::getInstance();
            $db->query("SELECT 1");
        } catch (\Exception $e) {
            $dbStatus = 'ERROR';
            $dbMessage = 'Erreur de connexion: ' . $e->getMessage();
        }
        
        // Vérifier les dossiers d'uploads
        $uploadsWritable = is_writable(__DIR__ . '/../../uploads');
        $logsWritable = is_writable(__DIR__ . '/../../../logs');
        
        // Vérifier les plugins
        $pluginsLoaded = isset($GLOBALS['plugin_manager']) ? 
            count($GLOBALS['plugin_manager']->getLoadedPlugins()) : 0;
        
        $info = [
            'status' => $dbStatus === 'OK' ? 'healthy' : 'degraded',
            'message' => 'API CMEM2 opérationnelle',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '2.0.0',
            'uptime' => [
                'server' => function_exists('sys_getloadavg') ? sys_getloadavg() : 'N/A',
                'php_version' => PHP_VERSION,
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
            ],
            'services' => [
                'database' => [
                    'status' => $dbStatus,
                    'message' => $dbMessage
                ],
                'file_storage' => [
                    'status' => $uploadsWritable ? 'OK' : 'ERROR',
                    'writable' => $uploadsWritable
                ],
                'logging' => [
                    'status' => $logsWritable ? 'OK' : 'ERROR',
                    'writable' => $logsWritable
                ],
                'plugins' => [
                    'status' => 'OK',
                    'loaded' => $pluginsLoaded
                ]
            ],
            'environment' => [
                'mode' => defined('APP_ENV') ? APP_ENV : 'production',
                'debug' => defined('APP_DEBUG') && APP_DEBUG ? 'enabled' : 'disabled',
                'timezone' => date_default_timezone_get()
            ]
        ];
        Response::success('health_status', $info);
    }

}