<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\UserAppSetup;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur pour la gestion des configurations d'applications utilisateur
 */
class UserAppController
{
    /**
     * Créer une nouvelle configuration d'application
     * POST /users/app/
     */
    public function create($userId)
    {
        try {
            LoggingMiddleware::logEntry();

            $input = Response::getRequestParams();

            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'app_id' => 'required|string|max:255',
                'json_data' => 'nullable|json'
            ]);

            if (!$validation['valid']) {
                LogService::warning("Données de création d'app invalides", [
                    'errors' => $validation['errors'],
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }

            // Vérifier si la configuration existe déjà
            $existing = new UserAppSetup();
            if ($existing->findByUserAndApp($userId, $input['app_id'])) {
                LogService::warning("Configuration d'app déjà existante", [
                    'user_id' => $userId,
                    'app_id' => $input['app_id']
                ]);
                LoggingMiddleware::logExit(409);
                Response::error('Une configuration existe déjà pour cette application', null, 409);
                return false;
            }

            // Créer la nouvelle configuration
            $appSetup = new UserAppSetup();
            $appSetup->user_id = $userId;
            $appSetup->app_id = $input['app_id'];
            $appSetup->json_data = $input['json_data'] ?? null;

            if ($appSetup->create()) {
                LogService::info("Configuration d'app créée", [
                    'user_id' => $userId,
                    'app_id' => $input['app_id'],
                    'id' => $appSetup->id
                ]);
                LoggingMiddleware::logExit(201);
                Response::success('Configuration d\'application créée', [
                    'id' => $appSetup->id,
                    'user_id' => $appSetup->user_id,
                    'app_id' => $appSetup->app_id,
                    'json_data' => json_decode($appSetup->json_data, true),
                    'created_at' => $appSetup->created_at
                ], 201);
                return true;
            } else {
                LogService::error("Échec de création de configuration d'app", [
                    'user_id' => $userId,
                    'app_id' => $input['app_id']
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la création de la configuration');
                return false;
            }

        } catch (Exception $e) {
            LogService::error("Erreur lors de la création de configuration d'app", [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la création de la configuration');
            return false;
        }
    }

    /**
     * Récupérer toutes les configurations d'un utilisateur
     * GET /users/app/
     */
    public function getAll($userId)
    {
        try {
            LoggingMiddleware::logEntry();

            $appSetup = new UserAppSetup();
            $configs = $appSetup->findByUserId($userId);

            // Formater les données pour la réponse
            $formattedConfigs = array_map(function($config) {
                return [
                    'id' => $config['id'],
                    'user_id' => $config['user_id'],
                    'app_id' => $config['app_id'],
                    'json_data' => json_decode($config['json_data'], true),
                    'created_at' => $config['created_at'],
                    'updated_at' => $config['updated_at']
                ];
            }, $configs);

            LoggingMiddleware::logExit(200);
            Response::success('Configurations d\'applications récupérées', [
                'configs' => $formattedConfigs,
                'total' => count($formattedConfigs)
            ]);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des configurations d'app", [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des configurations');
            return false;
        }
    }

    /**
     * Récupérer une configuration spécifique
     * GET /users/app/{app_id}
     */
    public function getByAppId($userId, $appId)
    {
        try {
            LoggingMiddleware::logEntry();

            $appSetup = new UserAppSetup();
            $config = $appSetup->findByUserAndApp($userId, $appId);

            if (!$config) {
                LoggingMiddleware::logExit(404);
                Response::error('Configuration d\'application non trouvée', null, 404);
                return false;
            }

            LoggingMiddleware::logExit(200);
            Response::success('Configuration d\'application récupérée', [
                'id' => $config['id'],
                'user_id' => $config['user_id'],
                'app_id' => $config['app_id'],
                'json_data' => json_decode($config['json_data'], true),
                'created_at' => $config['created_at'],
                'updated_at' => $config['updated_at']
            ]);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération de la configuration d'app", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'app_id' => $appId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération de la configuration');
            return false;
        }
    }

    /**
     * Mettre à jour une configuration d'application
     * PUT /users/app/{app_id}
     */
    public function updateByAppId($userId, $appId)
    {
        try {
            LoggingMiddleware::logEntry();

            $input = Response::getRequestParams();

            // Validation
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'json_data' => 'nullable|json'
            ]);

            if (!$validation['valid']) {
                LogService::warning("Données de mise à jour d'app invalides", [
                    'errors' => $validation['errors'],
                    'user_id' => $userId,
                    'app_id' => $appId
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }

            // Vérifier si la configuration existe
            $appSetup = new UserAppSetup();
            $existing = $appSetup->findByUserAndApp($userId, $appId);

            if (!$existing) {
                LoggingMiddleware::logExit(404);
                Response::error('Configuration d\'application non trouvée', null, 404);
                return false;
            }

            // Mettre à jour
            $appSetup->id = $existing['id'];
            $appSetup->user_id = $userId;
            $appSetup->app_id = $appId;
            $appSetup->json_data = $input['json_data'] ?? null;

            if ($appSetup->update()) {
                LogService::info("Configuration d'app mise à jour", [
                    'user_id' => $userId,
                    'app_id' => $appId,
                    'id' => $appSetup->id
                ]);
                LoggingMiddleware::logExit(200);
                Response::success('Configuration d\'application mise à jour', [
                    'id' => $appSetup->id,
                    'user_id' => $appSetup->user_id,
                    'app_id' => $appSetup->app_id,
                    'json_data' => json_decode($appSetup->json_data, true),
                    'updated_at' => $appSetup->updated_at
                ]);
                return true;
            } else {
                LogService::error("Échec de mise à jour de configuration d'app", [
                    'user_id' => $userId,
                    'app_id' => $appId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour de la configuration');
                return false;
            }

        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour de configuration d'app", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'app_id' => $appId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la mise à jour de la configuration');
            return false;
        }
    }

    /**
     * Supprimer (soft delete) une configuration d'application
     * DELETE /users/app/{app_id}
     */
    public function deleteByAppId($userId, $appId)
    {
        try {
            LoggingMiddleware::logEntry();

            // Vérifier si la configuration existe
            $appSetup = new UserAppSetup();
            $existing = $appSetup->findByUserAndApp($userId, $appId);

            if (!$existing) {
                LoggingMiddleware::logExit(404);
                Response::error('Configuration d\'application non trouvée', null, 404);
                return false;
            }

            // Soft delete
            if ($appSetup->softDeleteByUserAndApp($userId, $appId)) {
                LogService::info("Configuration d'app supprimée (soft)", [
                    'user_id' => $userId,
                    'app_id' => $appId,
                    'id' => $existing['id']
                ]);
                LoggingMiddleware::logExit(200);
                Response::success('Configuration d\'application supprimée');
                return true;
            } else {
                LogService::error("Échec de suppression de configuration d'app", [
                    'user_id' => $userId,
                    'app_id' => $appId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la suppression de la configuration');
                return false;
            }

        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression de configuration d'app", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'app_id' => $appId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la suppression de la configuration');
            return false;
        }
    }
}