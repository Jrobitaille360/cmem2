<?php

namespace Memories\Controllers;

use Memories\Utils\Response;
use Memories\Services\LogService;
use Memories\Models\AdminModel;
use Exception;

/**
 * Contrôleur admin secret pour exécuter des procédures stockées
 * ATTENTION : Ce contrôleur n'est pas documenté et ne doit être utilisé 
 * qu'avec la clé secrète ADMIN_SECRET_KEY
 */
class SecretAdminController 
{
    private AdminModel $model;
    
    public function __construct() {
        $this->model = new AdminModel();
    }
    
    /**
     * Vérifier la clé secrète admin
     * Supporte maintenant :
     * 1. Header X-Admin-Secret (ancien mode, pour rétrocompatibilité)
     * 2. Dans le body JSON avec le champ 'admin_secret' (nouveau mode, compatible navigateurs)
     */
    private function verifySecretKey($jsonData = null): bool {
        $providedKey = null;
        
        // Vérifier d'abord dans les données JSON (nouveau mode)
        if ($jsonData && isset($jsonData['admin_secret'])) {
            $providedKey = $jsonData['admin_secret'];
        }
        // Puis dans le header (ancien mode, pour rétrocompatibilité)
        else {
            $providedKey = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $_POST['admin_secret'] ?? $_GET['admin_secret'] ?? null;
        }
        
        $validKey = $_ENV['ADMIN_SECRET_KEY'] ?? null;
        
        if (!$validKey || !$providedKey) {
            return false;
        }
        
        return hash_equals($validKey, $providedKey);
    }
    
    /**
     * Exécuter une procédure stockée
     * POST /secret-admin/execute-procedure
     * 
     * SÉCURITÉ RENFORCÉE : Double authentification requise
     * 1. Token JWT valide avec rôle ADMINISTRATEUR (vérifié dans RouteHandler)
     * 2. Clé secrète admin dans le body JSON ou header
     * 
     * Deux modes d'authentification supportés :
     * Mode 1 (nouveau, compatible navigateurs) - Body JSON:
     * {
     *   "admin_secret": "clé_secrète",
     *   "procedure": "nom_procedure", 
     *   "parameters": []
     * }
     * 
     * Mode 2 (ancien, rétrocompatibilité) - Header + Body:
     * Headers: X-Admin-Secret: clé_secrète
     * Body: {"procedure": "nom_procedure", "parameters": []}
     */
    public function executeProcedure(array $authenticatedUser): void {
        try {
            // Lire les données JSON d'abord
            $input = Response::getRequestParams();
            if (!$input) {
                Response::error('Données JSON invalides', null, 400);
                return;
            }
            
            // Vérifier la clé secrète (soit dans JSON, soit dans header)
            if (!$this->verifySecretKey($input)) {
                LogService::warning('Tentative d\'accès admin secret sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'has_header_key' => isset($_SERVER['HTTP_X_ADMIN_SECRET']) ? 'yes' : 'no',
                    'has_json_key' => isset($input['admin_secret']) ? 'yes' : 'no'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }

            $procedure = $input['procedure'] ?? null;
            $parameters = $input['parameters'] ?? [];
            
            if (!$procedure) {
                Response::error('Nom de procédure manquant', null, 400);
                return;
            }
            
            // Liste des procédures autorisées
            $allowedProcedures = [
                'CleanupOldStats',
                'GenerateGroupStats',
                'GeneratePlatformStats',
                'GenerateUserStats', 
                'ResetAuthGroupsData',
                'ResetAuthenticationGroups'
            ];
            
            if (!in_array($procedure, $allowedProcedures)) {
                LogService::warning('Tentative d\'exécution de procédure non autorisée', [
                    'procedure' => $procedure,
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                Response::error('Procédure non autorisée', ['allowed_procedures' => $allowedProcedures], 400);
                return;
            }
            
            LogService::info('Exécution de procédure stockée via admin secret - AUTHENTIFIÉ', [
                'procedure' => $procedure,
                'parameters' => $parameters,
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'admin_role' => $authenticatedUser['role'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Exécuter la procédure
            $result = $this->model->executeProcedure($procedure, $parameters);
            
            LogService::info('Procédure exécutée avec succès', [
                'procedure' => $procedure,
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'result_success' => $result['success'] ?? false
            ]);
            
            Response::success('Procédure exécutée avec succès', [
                'procedure' => $procedure,
                'parameters' => $parameters,
                'result' => $result,
                'executed_at' => date('Y-m-d H:i:s'),
                'executed_by' => [
                    'admin_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email']
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur lors de l\'exécution de la procédure', [
                'error' => $e->getMessage(),
                'procedure' => $procedure ?? 'unknown',
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur lors de l\'exécution: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Lister les procédures disponibles
     * 
     * SÉCURITÉ RENFORCÉE : Double authentification requise
     * 1. Token JWT valide avec rôle ADMINISTRATEUR (vérifié dans RouteHandler)
     * 2. Clé secrète admin dans query param ou header
     * 
     * Deux modes d'authentification supportés :
     * Mode 1 (nouveau, compatible navigateurs) - Paramètre GET:
     * GET /secret-admin/procedures?admin_secret=clé_secrète
     * 
     * Mode 2 (ancien, rétrocompatibilité) - Header:
     * GET /secret-admin/procedures
     * Headers: X-Admin-Secret: clé_secrète
     */
    public function listProcedures(array $authenticatedUser): void {
        try {
            // Pour GET, nous supportons admin_secret en query parameter
            $queryData = null;
            if (isset($_GET['admin_secret'])) {
                $queryData = ['admin_secret' => $_GET['admin_secret']];
            }
            
            // Vérifier la clé secrète
            if (!$this->verifySecretKey($queryData)) {
                LogService::warning('Tentative d\'accès admin secret sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }
            
            $procedures = [
                'ResetData' => [
                    'description' => 'Remet à zéro toutes les données en gardant la structure',
                    'parameters' => [],
                    'danger_level' => 'HIGH'
                ],
                'ResetDatabase' => [
                    'description' => 'Recrée complètement la base de données',
                    'parameters' => [],
                    'danger_level' => 'EXTREME'
                ],
                'GenerateAllStats' => [
                    'description' => 'Génère toutes les statistiques',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'GenerateUserStats' => [
                    'description' => 'Génère les statistiques des utilisateurs',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'GenerateGroupStats' => [
                    'description' => 'Génère les statistiques des groupes',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'GeneratePlatformStats' => [
                    'description' => 'Génère les statistiques de la plateforme',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'CleanupOldStats' => [
                    'description' => 'Nettoie les anciennes statistiques',
                    'parameters' => [],
                    'danger_level' => 'MEDIUM'
                ]
            ];
            
            LogService::info('Liste des procédures consultée via admin secret - AUTHENTIFIÉ', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'admin_role' => $authenticatedUser['role'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            Response::success('Procédures disponibles', [
                'procedures' => $procedures,
                'authenticated_admin' => [
                    'user_id' => $authenticatedUser['user_id'],
                    'email' => $authenticatedUser['email'],
                    'role' => $authenticatedUser['role']
                ],
                'usage' => [
                    'endpoint' => '/secret-admin/execute-procedure',
                    'method' => 'POST',
                    'authentication' => 'Double authentification requise : JWT + clé secrète',
                    'modes' => [
                        'recommended' => [
                            'description' => 'Compatible navigateurs',
                            'headers' => ['Authorization: Bearer YOUR_JWT_TOKEN'],
                            'body' => [
                                'admin_secret' => 'clé_secrète',
                                'procedure' => 'nom_de_la_procedure',
                                'parameters' => []
                            ]
                        ],
                        'legacy' => [
                            'description' => 'Mode traditionnel',
                            'headers' => [
                                'Authorization: Bearer YOUR_JWT_TOKEN',
                                'X-Admin-Secret: clé_secrète'
                            ],
                            'body' => [
                                'procedure' => 'nom_de_la_procedure',
                                'parameters' => []
                            ]
                        ]
                    ]
                ]
            ]);
            
        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des procédures', [
                'error' => $e->getMessage(),
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur: ' . $e->getMessage(), null, 500);
        }
    }
}