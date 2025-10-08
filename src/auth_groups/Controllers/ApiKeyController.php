<?php
/**
 * Controller pour la gestion des clés API
 * Permet aux utilisateurs de créer, lister, et révoquer leurs clés API
 */

namespace AuthGroups\Controllers;

use AuthGroups\Models\ApiKey;
use AuthGroups\Services\AuthService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;

class ApiKeyController
{
    private $authService;
    
    public function __construct()
    {
        $this->authService = new AuthService();
    }
    
    /**
     * Récupérer le token Bearer de l'en-tête
     */
    private function getBearerToken()
    {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.+)/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * Créer une nouvelle clé API
     * POST /api-keys
     */
    public function create()
    {
        // Vérifier l'authentification JWT (pas API key pour créer des API keys)
        $token = $this->getBearerToken();
        if (!$token) {
            Response::error('Token manquant', null, 401);
            return;
        }
        
        $userData = $this->authService->validateToken($token);
        if (!$userData) {
            Response::error('Token invalide', null, 401);
            return;
        }
        
        $userId = $userData['user_id'];
        
        // Récupérer les données de la requête
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validation
        $errors = [];
        
        if (empty($data['name'])) {
            $errors['name'] = 'Le nom de la clé est requis';
        } elseif (strlen($data['name']) > 255) {
            $errors['name'] = 'Le nom ne peut pas dépasser 255 caractères';
        }
        
        // Valider l'environnement
        $environment = $data['environment'] ?? ApiKey::ENV_PRODUCTION;
        if (!in_array($environment, [ApiKey::ENV_PRODUCTION, ApiKey::ENV_TEST])) {
            $errors['environment'] = 'Environnement invalide. Doit être "production" ou "test"';
        }
        
        // Valider les scopes
        $scopes = $data['scopes'] ?? [ApiKey::SCOPE_READ, ApiKey::SCOPE_WRITE];
        if (!is_array($scopes)) {
            $errors['scopes'] = 'Les scopes doivent être un tableau';
        } else {
            $validScopes = [
                ApiKey::SCOPE_READ,
                ApiKey::SCOPE_WRITE,
                ApiKey::SCOPE_DELETE,
                ApiKey::SCOPE_ADMIN,
                ApiKey::SCOPE_ALL
            ];
            
            foreach ($scopes as $scope) {
                if (!in_array($scope, $validScopes)) {
                    $errors['scopes'] = "Scope invalide: {$scope}. Scopes valides: " . implode(', ', $validScopes);
                    break;
                }
            }
        }
        
        // Valider l'expiration
        $expiresInDays = null;
        if (isset($data['expires_in_days'])) {
            if (!is_numeric($data['expires_in_days']) || $data['expires_in_days'] < 1) {
                $errors['expires_in_days'] = 'L\'expiration doit être un nombre de jours >= 1';
            } else {
                $expiresInDays = (int)$data['expires_in_days'];
            }
        }
        
        if (!empty($errors)) {
            Response::error('Validation échouée', $errors, 400);
            return;
        }
        
        // Préparer les options
        $options = [
            'environment' => $environment,
            'scopes' => $scopes,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? 60,
            'rate_limit_per_hour' => $data['rate_limit_per_hour'] ?? 3600,
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null
        ];
        
        if ($expiresInDays) {
            $options['expires_in_days'] = $expiresInDays;
        }
        
        try {
            // Générer la clé
            $result = ApiKey::generate($userId, $data['name'], $options);
            
            // IMPORTANT: La clé complète n'est montrée qu'UNE SEULE FOIS
            Response::success('Clé API créée avec succès', [
                'api_key' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name'],
                    'key' => $result['key'], // À sauvegarder maintenant!
                    'prefix' => $result['data']['key_prefix'],
                    'last_4' => $result['data']['last_4'],
                    'environment' => $result['data']['environment'],
                    'scopes' => json_decode($result['data']['scopes'], true),
                    'rate_limit_per_minute' => $result['data']['rate_limit_per_minute'],
                    'rate_limit_per_hour' => $result['data']['rate_limit_per_hour'],
                    'expires_at' => $result['data']['expires_at'],
                    'created_at' => $result['data']['created_at']
                ],
                'warning' => 'Sauvegardez cette clé maintenant! Elle ne sera plus jamais affichée.'
            ], 201);
            
        } catch (\Exception $e) {
            Response::error('Erreur lors de la création de la clé API', [
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Lister toutes les clés de l'utilisateur
     * GET /api-keys
     */
    public function list()
    {
        // Authentification
        $token = $this->getBearerToken();
        if (!$token) {
            Response::error('Token manquant', null, 401);
            return;
        }
        
        $userData = $this->authService->validateToken($token);
        if (!$userData) {
            Response::error('Token invalide', null, 401);
            return;
        }
        
        $userId = $userData['user_id'];
        
        // Filtres optionnels
        $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === 'true';
        
        try {
            $keys = ApiKey::getByUserId($userId, $activeOnly);
            
            // Formater la réponse (ne jamais inclure key_hash)
            $formattedKeys = array_map(function($key) {
                return [
                    'id' => $key['id'],
                    'name' => $key['name'],
                    'prefix' => $key['key_prefix'],
                    'last_4' => $key['last_4'],
                    'environment' => $key['environment'],
                    'scopes' => $key['scopes'],
                    'status' => $key['status'],
                    'rate_limits' => [
                        'per_minute' => $key['rate_limit_per_minute'],
                        'per_hour' => $key['rate_limit_per_hour']
                    ],
                    'usage' => [
                        'total_requests' => $key['total_requests'],
                        'last_used_at' => $key['last_used_at'],
                        'last_used_ip' => $key['last_used_ip']
                    ],
                    'expires_at' => $key['expires_at'],
                    'revoked_at' => $key['revoked_at'],
                    'revoked_reason' => $key['revoked_reason'],
                    'created_at' => $key['created_at'],
                    'updated_at' => $key['updated_at']
                ];
            }, $keys);
            
            Response::success('Clés API récupérées', [
                'count' => count($formattedKeys),
                'keys' => $formattedKeys
            ]);
            
        } catch (\Exception $e) {
            Response::error('Erreur lors de la récupération des clés', [
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtenir les détails d'une clé spécifique
     * GET /api-keys/:id
     */
    public function get($keyId)
    {
        // Authentification
        $token = $this->getBearerToken();
        if (!$token) {
            Response::error('Token manquant', null, 401);
            return;
        }
        
        $userData = $this->authService->validateToken($token);
        if (!$userData) {
            Response::error('Token invalide', null, 401);
            return;
        }
        
        $userId = $userData['user_id'];
        
        try {
            $model = new ApiKey();
            $keyData = $model->findById($keyId);
            
            if (!$keyData) {
                Response::error('Clé API non trouvée', null, 404);
                return;
            }
            
            // Vérifier que la clé appartient à l'utilisateur
            if ($keyData['user_id'] != $userId) {
                Response::error('Accès refusé', null, 403);
                return;
            }
            
            // Obtenir les statistiques
            $stats = ApiKey::getStats($keyId);
            
            // Calculer le statut
            $status = 'active';
            if ($keyData['revoked_at']) {
                $status = 'revoked';
            } elseif ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
                $status = 'expired';
            }
            
            Response::success('Détails de la clé API', [
                'key' => [
                    'id' => $keyData['id'],
                    'name' => $keyData['name'],
                    'prefix' => $keyData['key_prefix'],
                    'last_4' => $keyData['last_4'],
                    'environment' => $keyData['environment'],
                    'scopes' => json_decode($keyData['scopes'], true),
                    'status' => $status,
                    'rate_limits' => [
                        'per_minute' => $keyData['rate_limit_per_minute'],
                        'per_hour' => $keyData['rate_limit_per_hour']
                    ],
                    'metadata' => $keyData['metadata'] ? json_decode($keyData['metadata'], true) : null,
                    'notes' => $keyData['notes'],
                    'expires_at' => $keyData['expires_at'],
                    'revoked_at' => $keyData['revoked_at'],
                    'revoked_reason' => $keyData['revoked_reason'],
                    'created_at' => $keyData['created_at'],
                    'updated_at' => $keyData['updated_at']
                ],
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            Response::error('Erreur lors de la récupération de la clé', [
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Révoquer une clé API
     * DELETE /api-keys/:id
     */
    public function revoke($keyId)
    {
        // Authentification
        $token = $this->getBearerToken();
        if (!$token) {
            Response::error('Token manquant', null, 401);
            return;
        }
        
        $userData = $this->authService->validateToken($token);
        if (!$userData) {
            Response::error('Token invalide', null, 401);
            return;
        }
        
        $userId = $userData['user_id'];
        
        try {
            // Vérifier que la clé existe et appartient à l'utilisateur
            $model = new ApiKey();
            $keyData = $model->findById($keyId);
            
            if (!$keyData) {
                Response::error('Clé API non trouvée', null, 404);
                return;
            }
            
            if ($keyData['user_id'] != $userId) {
                Response::error('Accès refusé', null, 403);
                return;
            }
            
            if ($keyData['revoked_at']) {
                Response::error('Clé déjà révoquée', [
                    'revoked_at' => $keyData['revoked_at'],
                    'reason' => $keyData['revoked_reason']
                ], 400);
                return;
            }
            
            // Récupérer la raison de révocation (optionnelle)
            $data = json_decode(file_get_contents('php://input'), true);
            $reason = $data['reason'] ?? 'Révoquée par l\'utilisateur';
            
            // Révoquer la clé
            $success = ApiKey::revoke($keyId, $reason);
            
            if ($success) {
                Response::success('Clé API révoquée avec succès', [
                    'key_id' => $keyId,
                    'reason' => $reason,
                    'revoked_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                Response::error('Erreur lors de la révocation de la clé', null, 500);
            }
            
        } catch (\Exception $e) {
            Response::error('Erreur lors de la révocation de la clé', [
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Régénérer une clé API (révoquer l'ancienne et en créer une nouvelle)
     * POST /api-keys/:id/regenerate
     */
    public function regenerate($keyId)
    {
        // Authentification
        $token = $this->getBearerToken();
        if (!$token) {
            Response::error('Token manquant', null, 401);
            return;
        }
        
        $userData = $this->authService->validateToken($token);
        if (!$userData) {
            Response::error('Token invalide', null, 401);
            return;
        }
        
        $userId = $userData['user_id'];
        
        try {
            // Récupérer la clé existante
            $model = new ApiKey();
            $oldKeyData = $model->findById($keyId);
            
            if (!$oldKeyData) {
                Response::error('Clé API non trouvée', null, 404);
                return;
            }
            
            if ($oldKeyData['user_id'] != $userId) {
                Response::error('Accès refusé', null, 403);
                return;
            }
            
            // Révoquer l'ancienne clé
            ApiKey::revoke($keyId, 'Régénérée');
            
            // Créer une nouvelle clé avec les mêmes paramètres
            $scopes = json_decode($oldKeyData['scopes'], true);
            
            $options = [
                'environment' => $oldKeyData['environment'],
                'scopes' => $scopes,
                'rate_limit_per_minute' => $oldKeyData['rate_limit_per_minute'],
                'rate_limit_per_hour' => $oldKeyData['rate_limit_per_hour'],
                'notes' => $oldKeyData['notes'],
                'metadata' => $oldKeyData['metadata']
            ];
            
            if ($oldKeyData['expires_at']) {
                $expiresIn = ceil((strtotime($oldKeyData['expires_at']) - time()) / 86400);
                if ($expiresIn > 0) {
                    $options['expires_in_days'] = $expiresIn;
                }
            }
            
            $result = ApiKey::generate($userId, $oldKeyData['name'], $options);
            
            Response::success('Clé API régénérée avec succès', [
                'old_key_id' => $keyId,
                'api_key' => [
                    'id' => $result['data']['id'],
                    'name' => $result['data']['name'],
                    'key' => $result['key'], // Nouvelle clé à sauvegarder!
                    'prefix' => $result['data']['key_prefix'],
                    'last_4' => $result['data']['last_4'],
                    'environment' => $result['data']['environment'],
                    'scopes' => json_decode($result['data']['scopes'], true),
                    'rate_limit_per_minute' => $result['data']['rate_limit_per_minute'],
                    'rate_limit_per_hour' => $result['data']['rate_limit_per_hour'],
                    'expires_at' => $result['data']['expires_at'],
                    'created_at' => $result['data']['created_at']
                ],
                'warning' => 'Sauvegardez cette nouvelle clé maintenant! Elle ne sera plus jamais affichée.'
            ], 201);
            
        } catch (\Exception $e) {
            Response::error('Erreur lors de la régénération de la clé', [
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
