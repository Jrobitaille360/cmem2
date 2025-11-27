<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\ApiKey;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use Exception;

/**
 * Contrôleur pour la gestion des API Keys via le système secret admin
 * 
 * SÉCURITÉ RENFORCÉE : Toutes les opérations nécessitent :
 * 1. Token API Keys valide avec rôle ADMINISTRATEUR
 * 2. Clé secrète admin (ADMIN_SECRET_KEY)
 * 
 * Ce contrôleur gère maintenant TOUTES les opérations sur les API keys :
 * - Création de clés
 * - Liste des clés  
 * - Détails d'une clé
 * - Révocation de clés
 * - Régénération de clés
 */
class SecretApiKeyController
{
    /**
     * Créer une nouvelle API key
     * POST /secret-admin/api-keys
     */
    public function create(array $authenticatedUser): void
    {
        try {
            $input = Response::getRequestParams();
            
            if (!$input) {
                Response::error('Données JSON invalides', null, 400);
                return;
            }

            // Validation des données d'entrée
            $validation = Validator::validate($input, [
                'admin_secret' => 'required|string',
                'user_id' => 'required|integer',
                'name' => 'required|string|min:3|max:100',
                'scopes' => 'optional|array',
                'environment' => 'optional|string|in:production,test',
                'expires_in_days' => 'optional|integer|min:1|max:3650',
                'rate_limit_per_minute' => 'optional|integer|min:1|max:10000',
                'rate_limit_per_hour' => 'optional|integer|min:1|max:100000',
                'notes' => 'optional|string|max:500'
            ]);

            if (!$validation['valid']) {
                LogService::warning('Validation échouée pour création API key', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'errors' => $validation['errors']
                ]);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return;
            }

            // Vérifier la clé secrète admin
            if (!$this->verifySecretKey($input)) {
                LogService::warning('Tentative de création API key sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'target_user_id' => $input['user_id'] ?? null,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }

            // Préparer les options pour la génération de clé
            $options = [
                'scopes' => $input['scopes'] ?? [ApiKey::SCOPE_READ, ApiKey::SCOPE_WRITE],
                'environment' => $input['environment'] ?? ApiKey::ENV_PRODUCTION,
                'rate_limit_per_minute' => $input['rate_limit_per_minute'] ?? 60,
                'rate_limit_per_hour' => $input['rate_limit_per_hour'] ?? 3600,
                'notes' => $input['notes'] ?? null
            ];

            // Gestion de l'expiration
            if (isset($input['expires_in_days'])) {
                $options['expires_in_days'] = $input['expires_in_days'];
            }

            // Générer la clé API
            $result = ApiKey::generate($input['user_id'], $input['name'], $options);

            LogService::info('API Key créée via système secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'target_user_id' => $input['user_id'],
                'api_key_id' => $result['data']['id'],
                'api_key_name' => $input['name'],
                'environment' => $options['environment'],
                'scopes' => $options['scopes']
            ]);

            Response::success('API Key créée avec succès via système secret admin', [
                'api_key' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name'],
                    'key' => $result['key'], // IMPORTANT: Clé complète montrée une seule fois
                    'prefix' => $result['data']['key_prefix'],
                    'last_4' => $result['data']['last_4'],
                    'environment' => $result['data']['environment'],
                    'scopes' => json_decode($result['data']['scopes'], true),
                    'rate_limit_per_minute' => $result['data']['rate_limit_per_minute'],
                    'rate_limit_per_hour' => $result['data']['rate_limit_per_hour'],
                    'expires_at' => $result['data']['expires_at'],
                    'created_at' => $result['data']['created_at']
                ],
                'warning' => '⚠️ IMPORTANT: Sauvegardez cette clé maintenant - elle ne sera plus jamais affichée!',
                'admin_info' => [
                    'created_by' => $authenticatedUser['email'],
                    'admin_user_id' => $authenticatedUser['user_id']
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la création API key via secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur lors de la création de l\'API key', null, 500);
        }
    }

    /**
     * Lister toutes les API keys ou les clés d'un utilisateur spécifique
     * GET /secret-admin/api-keys?admin_secret=xxx&user_id=123 (optionnel)
     */
    public function list(array $authenticatedUser): void
    {
        try {
            // Récupérer les paramètres de query
            $adminSecret = $_GET['admin_secret'] ?? null;
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

            if (!$adminSecret) {
                Response::error('Clé secrète admin requise dans les paramètres', null, 400);
                return;
            }

            // Vérifier la clé secrète admin
            if (!$this->verifySecretKey(['admin_secret' => $adminSecret])) {
                LogService::warning('Tentative de liste API keys sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'requested_user_id' => $userId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }

            // Récupérer les clés
            if ($userId) {
                $apiKeys = ApiKey::getByUserId($userId);
                $logMessage = 'Liste API keys d\'un utilisateur via secret admin';
            } else {
                // Récupérer toutes les clés (nécessite une méthode dans le modèle)
                $apiKeys = ApiKey::getAll();
                $logMessage = 'Liste de toutes les API keys via secret admin';
            }

            LogService::info($logMessage, [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'requested_user_id' => $userId,
                'keys_count' => count($apiKeys)
            ]);

            Response::success('Liste des API keys récupérée', [
                'api_keys' => $apiKeys,
                'total' => count($apiKeys),
                'filtered_by_user' => $userId ? true : false,
                'admin_info' => [
                    'requested_by' => $authenticatedUser['email'],
                    'admin_user_id' => $authenticatedUser['user_id']
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la récupération des API keys via secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur lors de la récupération des API keys', null, 500);
        }
    }

    /**
     * Obtenir les détails d'une API key spécifique
     * GET /secret-admin/api-keys/{id}?admin_secret=xxx
     */
    public function get(int $keyId, array $authenticatedUser): void
    {
        try {
            $adminSecret = $_GET['admin_secret'] ?? null;

            if (!$adminSecret) {
                Response::error('Clé secrète admin requise dans les paramètres', null, 400);
                return;
            }

            // Vérifier la clé secrète admin
            if (!$this->verifySecretKey(['admin_secret' => $adminSecret])) {
                LogService::warning('Tentative de consultation API key sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'api_key_id' => $keyId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }

            // Récupérer les détails de la clé (en excluant les clés révoquées)
            $apiKeyData = ApiKey::getById($keyId, false);

            if (!$apiKeyData) {
                LogService::warning('Tentative d\'accès à une API key inexistante ou révoquée', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'api_key_id' => $keyId
                ]);
                Response::error('API key non trouvée', null, 404);
                return;
            }

            LogService::info('Consultation détails API key via secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'api_key_id' => $keyId,
                'api_key_owner_id' => $apiKeyData['user_id']
            ]);

            Response::success('Détails de l\'API key récupérés', [
                'api_key' => $apiKeyData,
                'admin_info' => [
                    'requested_by' => $authenticatedUser['email'],
                    'admin_user_id' => $authenticatedUser['user_id']
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la consultation API key via secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'api_key_id' => $keyId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur lors de la consultation de l\'API key', null, 500);
        }
    }

    /**
     * Révoquer une API key
     * DELETE /secret-admin/api-keys/{id}
     */
    public function revoke(int $keyId, array $authenticatedUser): void
    {
        try {
            $input = Response::getRequestParams();
            
            if (!$input) {
                Response::error('Données JSON invalides', null, 400);
                return;
            }

            // Validation des données d'entrée
            $validation = Validator::validate($input, [
                'admin_secret' => 'required|string',
                'reason' => 'optional|string|max:255'
            ]);

            if (!$validation['valid']) {
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return;
            }

            // Vérifier la clé secrète admin
            if (!$this->verifySecretKey($input)) {
                LogService::warning('Tentative de révocation API key sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'api_key_id' => $keyId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }

            $reason = $input['reason'] ?? 'Révoquée par administrateur via système secret';

            // Révoquer la clé
            $success = ApiKey::revoke($keyId, $reason);

            if (!$success) {
                Response::error('API key non trouvée ou déjà révoquée', null, 404);
                return;
            }

            LogService::info('API Key révoquée via système secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'api_key_id' => $keyId,
                'reason' => $reason
            ]);

            Response::success('API key révoquée avec succès', [
                'api_key_id' => $keyId,
                'revoked_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
                'admin_info' => [
                    'revoked_by' => $authenticatedUser['email'],
                    'admin_user_id' => $authenticatedUser['user_id']
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la révocation API key via secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'api_key_id' => $keyId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur lors de la révocation de l\'API key', null, 500);
        }
    }

    /**
     * Régénérer une API key
     * POST /secret-admin/api-keys/{id}/regenerate
     */
    public function regenerate(int $keyId, array $authenticatedUser): void
    {
        try {
            $input = Response::getRequestParams();
            // Validation des données d'entrée
            $validation = Validator::validate($input, [
                'admin_secret' => 'required|string'
            ]);

            if (!$validation['valid']) {
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return;
            }

            // Vérifier la clé secrète admin
            if (!$this->verifySecretKey($input)) {
                LogService::warning('Tentative de régénération API key sans clé secrète valide', [
                    'admin_user_id' => $authenticatedUser['user_id'],
                    'admin_email' => $authenticatedUser['email'],
                    'api_key_id' => $keyId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                Response::error('Clé secrète admin invalide', null, 403);
                return;
            }

            // Régénérer la clé
            $result = ApiKey::regenerate($keyId);

            if (!$result) {
                Response::error('API key non trouvée ou révoquée', null, 404);
                return;
            }

            LogService::info('API Key régénérée via système secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'admin_email' => $authenticatedUser['email'],
                'api_key_id' => $keyId,
                'new_key_id' => $result['data']['id']
            ]);

            Response::success('API key régénérée avec succès', [
                'api_key' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name'],
                    'key' => $result['key'], // IMPORTANT: Nouvelle clé montrée une seule fois
                    'scopes' => json_decode($result['data']['scopes'], true),
                    'environment' => $result['data']['environment'],
                    'prefix' => $result['data']['key_prefix'],
                    'last_4' => $result['data']['last_4'],
                    'regenerated_at' => $result['data']['created_at']
                ],
                'warning' => '⚠️ IMPORTANT: Sauvegardez cette nouvelle clé maintenant - elle ne sera plus jamais affichée!',
                'admin_info' => [
                    'regenerated_by' => $authenticatedUser['email'],
                    'admin_user_id' => $authenticatedUser['user_id']
                ]
            ]);

        } catch (Exception $e) {
            LogService::error('Erreur lors de la régénération API key via secret admin', [
                'admin_user_id' => $authenticatedUser['user_id'],
                'api_key_id' => $keyId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur lors de la régénération de l\'API key', null, 500);
        }
    }

    /**
     * Vérifier la clé secrète admin
     */
    private function verifySecretKey(array $data): bool
    {
        $providedKey = $data['admin_secret'] ?? null;
        $validKey = $_ENV['ADMIN_SECRET_KEY'] ?? null;

        if (!$validKey || !$providedKey) {
            return false;
        }

        return hash_equals($validKey, $providedKey);
    }
}