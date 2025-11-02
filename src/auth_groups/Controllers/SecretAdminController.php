<?php

namespace AuthGroups\Controllers;

use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;
use AuthGroups\Models\AdminModel;
use Exception;

/**
 * Contrôleur admin secret pour exécuter des procédures stockées
 * ATTENTION : Ce contrôleur n'est pas documenté et ne doit être utilisé 
 * qu'avec la clé secrète ADMIN_SECRET_KEY
 */
class SecretAdminController
{
    private AdminModel $model;

    public function __construct()
    {
        $this->model = new AdminModel();
    }

    /**
     * Vérifier la clé secrète admin
     * Supporte maintenant :
     * 2. Dans le body JSON avec le champ 'admin_secret' (nouveau mode, compatible navigateurs)
     */
    private function verifySecretKey($jsonData = null): bool
    {
        $providedKey = null;

        // Vérifier d'abord dans les données JSON (nouveau mode)
        if ($jsonData && isset($jsonData['admin_secret']))
        {
            $providedKey = $jsonData['admin_secret'];
        }

        $validKey = $_ENV['ADMIN_SECRET_KEY'] ?? null;

        if (!$validKey || !$providedKey)
        {
            return false;
        }

        return hash_equals($validKey, $providedKey);
    }

    /**
     * Exécuter une procédure stockée
     * POST /secret-admin/execute-procedure
     * 
     * SÉCURITÉ RENFORCÉE : Double authentification requise
     * 1. API Key valide avec rôle ADMINISTRATEUR (vérifié dans RouteHandler)
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
     */
    public function executeProcedure(array $authenticatedUser): void
    {
        try
        {
            // Lire les données JSON d'abord
            $input = Response::getRequestParams();
            if (!$input)
            {
                Response::error('Données JSON invalides', null, 400);
                return;
            }

            // Vérifier la clé secrète (soit dans JSON, soit dans header)
            if (!$this->verifySecretKey($input))
            {
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

            if (!$procedure)
            {
                Response::error('Nom de procédure manquant', null, 400);
                return;
            }

            // Liste des procédures autorisées
            $allowedProcedures = [
                'AddCalDAVSupport',
                'CleanupExpiredSessions',
                'CleanupOldStats',
                'cleanup_expired_api_keys',
                'GenerateGroupStats',
                'GeneratePlatformStats',
                'GenerateUserStats',
                'ResetAuthenticationGroups',
                'ResetICSTables'
            ];

            if (!in_array($procedure, $allowedProcedures))
            {
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
        }
        catch (Exception $e)
        {
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
     * Liste les procédures stockées disponibles
     * 
     * SÉCURITÉ RENFORCÉE : Double authentification requise
     * 1. API Key valide avec rôle ADMINISTRATEUR (vérifié dans RouteHandler)
     * 2. Clé secrète admin dans query param
     * 
     * Format d'appel :
     * GET /secret-admin/procedures?admin_secret=clé_secrète
     * Headers: X-API-Key: {API_KEY}
     * 
     * @param array $authenticatedUser Utilisateur authentifié via API Key (doit être ADMINISTRATEUR)
     * @return void
     */
    public function listProcedures(array $authenticatedUser): void
    {
        try
        {
            // Pour GET, nous supportons admin_secret en query parameter
            $queryData = null;
            if (isset($_GET['admin_secret']))
            {
                $queryData = ['admin_secret' => $_GET['admin_secret']];
            }

            // Vérifier la clé secrète
            if (!$this->verifySecretKey($queryData))
            {
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
                'AddCalDAVSupport' => [
                    'name' => 'AddCalDAVSupport',
                    'description' => 'Ajoute le support CalDAV aux calendriers existants (ajoute les colonnes ctag, etag, uid, etc.)',
                    'parameters' => [],
                    'danger_level' => 'MEDIUM',
                    'warning' => 'Modifie la structure des tables calendars et calendar_events'
                ],
                'CleanupExpiredSessions' => [
                    'name' => 'CleanupExpiredSessions',
                    'description' => 'Marque les sessions utilisateur expirées comme inactives',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'CleanupOldStats' => [
                    'name' => 'CleanupOldStats',
                    'description' => 'Nettoie les anciennes statistiques (garde les 100 derniers snapshots et supprime ceux de +30 jours)',
                    'parameters' => [],
                    'danger_level' => 'MEDIUM'
                ],
                'cleanup_expired_api_keys' => [
                    'name' => 'cleanup_expired_api_keys',
                    'description' => 'Marque comme révoquées les clés API expirées non encore révoquées',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'GenerateGroupStats' => [
                    'name' => 'GenerateGroupStats',
                    'description' => 'Génère les statistiques pour chaque groupe (membres, fichiers, stockage)',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'GeneratePlatformStats' => [
                    'name' => 'GeneratePlatformStats',
                    'description' => 'Génère les statistiques globales de la plateforme (utilisateurs, groupes, tags, fichiers, stockage)',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'GenerateUserStats' => [
                    'name' => 'GenerateUserStats',
                    'description' => 'Génère les statistiques individuelles pour chaque utilisateur',
                    'parameters' => [],
                    'danger_level' => 'LOW'
                ],
                'ResetAuthenticationGroups' => [
                    'name' => 'ResetAuthenticationGroups',
                    'description' => 'Recrée complètement la base de données (DROP et CREATE de toutes les tables users, groups, files, etc.)',
                    'parameters' => [],
                    'danger_level' => 'EXTREME',
                    'warning' => 'DANGER EXTRÊME : Toute la base de données sera recréée, toutes les données seront perdues'
                ],
                'ResetICSTables' => [
                    'name' => 'ResetICSTables',
                    'description' => 'Recrée les tables de calendrier ICS (calendars, calendar_events, calendar_shares)',
                    'parameters' => [],
                    'danger_level' => 'HIGH',
                    'warning' => 'ATTENTION : Supprime toutes les données de calendriers et événements'
                ]
            ];

            LogService::info('Liste des procédures consultée via admin secret - AUTHENTIFIÉ', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'admin_role' => $authenticatedUser['role'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            Response::success('Procédures disponibles récupérées avec succès', [
                'count' => count($procedures),
                'procedures' => array_values($procedures),
                'authenticated_admin' => [
                    'user_id' => $authenticatedUser['user_id'],
                    'email' => $authenticatedUser['email'],
                    'role' => $authenticatedUser['role']
                ],
                'authentication_info' => [
                    'type' => 'Double authentification',
                    'requirements' => [
                        '1. API Key valide avec rôle ADMINISTRATEUR',
                        '2. Clé secrète admin (ADMIN_SECRET_KEY)'
                    ]
                ],
                'usage' => [
                    'endpoint' => '/secret-admin/execute-procedure',
                    'method' => 'POST',
                    'headers' => [
                        'X-API-Key' => '{API_KEY}',
                        'Content-Type' => 'application/json'
                    ],
                    'body' => [
                        'admin_secret' => '{ADMIN_SECRET_KEY}',
                        'procedure' => 'nom_de_la_procedure',
                        'parameters' => []
                    ]
                ]
            ]);
        }
        catch (Exception $e)
        {
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
