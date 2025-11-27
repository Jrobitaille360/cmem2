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
                
            // Route de connexion publique - AVEC VALIDATION API KEY OBLIGATOIRE
            ($controller === 'users' && $action === 'login' && $method === 'POST') => 
                $this->handleLoginWithApiKey(),
            // PAR pour créer la première clé API si aucune n'existe encore:
            //    $this->controllers['users']->authenticate(),    
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
                'authentication' => 'Requiert une API Key pour la plupart des endpoints'
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
                    'POST /users/login - Connexion utilisateur (requiert API Key)',
                    'POST /users/request-password-reset - Demande de réinitialisation de mot de passe',
                    'POST /users/reset-password - Réinitialisation de mot de passe avec token',
                    'POST /users/verify-email - Vérification d\'email avec token',
                    'POST /users/resend-verification - Renvoi d\'email de vérification',
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
                'api_key' => [
                    'header' => 'X-API-Key: {your_api_key}',
                    'alternative' => 'Authorization: Bearer {your_api_key}',
                    'scopes' => 'read, write, admin (selon les permissions)',
                    'environments' => 'development, staging, production'
                ],
                'session' => [
                    'usage' => 'Stocker et inclure dans les requêtes suivantes'
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
    
    /**
     * Gestion du login avec validation obligatoire d'API key
     * 
     * SÉCURITÉ : Tous les logins nécessitent une API key valide
     * Cette méthode vérifie d'abord la présence et validité d'une API key,
     * puis procède à l'authentification (email + password).
     */
    private function handleLoginWithApiKey(): void
    {
        try {
            // ÉTAPE 1: Vérifier qu'une API key valide est fournie
            $apiKeyData = \AuthGroups\Middleware\ApiKeyAuthMiddleware::requireApiKey();
            
            if (!$apiKeyData) {
                // L'erreur a déjà été envoyée par requireApiKey()
                return;
            }
            
            // ÉTAPE 2: Vérifier que la clé a les permissions appropriées pour le login
            if (!isset($apiKeyData['scopes']) || !is_array($apiKeyData['scopes'])) {
                LogService::warning('API key sans scopes valides utilisée pour login', [
                    'api_key_id' => $apiKeyData['id'],
                    'user_id' => $apiKeyData['user_id'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                
               Response::error('API key invalide', [
                    'error' => 'INVALID_API_KEY_SCOPES',
                    'message' => 'Cette API key n\'a pas les permissions appropriées'
                ], 403);
                return;
            }
            
            // ÉTAPE 3: Log de sécurité pour traçabilité
            LogService::info('Login tenté avec API key valide', [
                'api_key_id' => $apiKeyData['id'],
                'api_key_user_id' => $apiKeyData['user_id'],
                'api_key_environment' => $apiKeyData['environment'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // ÉTAPE 4: Procéder au login avec api_key + email + password
            $this->controllers['users']->authenticate();
            
        } catch (\Exception $e) {
            LogService::error('Erreur lors de la validation API key pour login', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            Response::error('Erreur de validation de sécurité', [
                'error' => 'SECURITY_VALIDATION_ERROR',
                'message' => 'Une erreur est survenue lors de la validation de sécurité'
            ], 500);
        }
    }

}