<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\Plan;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur pour la gestion de la sélection de plans
 */
class PlanController
{
    /**
     * Afficher tous les plans disponibles
     * GET /plans
     */
    public function listPlans()
    {
        try {
            LoggingMiddleware::logEntry();
            
            $plans = Plan::getAllActive();
            
            // Formater les données pour la réponse
            $formattedPlans = array_map(function($plan) {
                $features = json_decode($plan['features'], true) ?? [];
                return [
                    'id' => $plan['id'],
                    'name' => $plan['name'],
                    'display_name' => $plan['display_name'],
                    'description' => $plan['description'],
                    'price' => (float)$plan['price'],
                    'currency' => $plan['currency'],
                    'duration_days' => $plan['duration_days'],
                    'api_rate_limit' => $plan['api_rate_limit'],
                    'features' => $features,
                    'is_recommended' => $plan['name'] === 'argent', // Recommander le plan argent
                    'created_at' => $plan['created_at']
                ];
            }, $plans);
            
            LoggingMiddleware::logExit(200);
            Response::success('Plans disponibles récupérés', [
                'plans' => $formattedPlans,
                'total' => count($formattedPlans)
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des plans", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des plans');
            return false;
        }
    }
    
    /**
     * Afficher un plan spécifique
     * GET /plans/{id}
     */
    public function getPlan(int $id)
    {
        try {
            LoggingMiddleware::logEntry();

            $plan = Plan::getActiveById($id);

            if (!$plan) {
                LoggingMiddleware::logExit(404);
                Response::error('Plan non trouvé', null, 404);
                return false;
            }

            $features = json_decode($plan['features'], true) ?? [];
            $formatted = [
                'id'            => $plan['id'],
                'name'          => $plan['name'],
                'display_name'  => $plan['display_name'],
                'description'   => $plan['description'],
                'price'         => (float)$plan['price'],
                'currency'      => $plan['currency'],
                'duration_days' => $plan['duration_days'],
                'api_rate_limit'=> $plan['api_rate_limit'],
                'features'      => $features,
                'is_recommended'=> $plan['name'] === 'argent',
                'created_at'    => $plan['created_at']
            ];

            LoggingMiddleware::logExit(200);
            Response::success('Plan récupéré', $formatted);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération du plan", [
                'error' => $e->getMessage(),
                'id'    => $id
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération du plan');
            return false;
        }
    }

    /**
     * Traiter la sélection d'un plan via token d'invitation
     * POST /users/choose-plan
     */
    public function choosePlan()
    {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            
            // Validation
            $validation = Validator::validate($input, [
                'token' => 'required|string',
                'plan' => 'required|string|in:bronze,argent,platine'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données de sélection de plan invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }
            
            // Vérifier le token d'invitation
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("
                SELECT user_id FROM plan_invitations 
                WHERE invitation_token = :token 
                AND expires_at > NOW() 
                AND status = 'pending'
            ");
            $stmt->execute(['token' => $input['token']]);
            $invitation = $stmt->fetch();
            
            if (!$invitation) {
                LogService::warning("Token d'invitation plan invalide ou expiré", [
                    'token' => substr($input['token'], 0, 8) . '...'
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Token d\'invitation invalide ou expiré', null, 404);
                return false;
            }
            
            $userId = $invitation['user_id'];
            
            // Vérifier que le plan existe
            $selectedPlan = Plan::findByName($input['plan']);
            if (!$selectedPlan) {
                LogService::warning("Plan sélectionné non trouvé", [
                    'plan' => $input['plan']
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Plan sélectionné non trouvé', null, 404);
                return false;
            }
            
            // Marquer l'invitation comme sélectionnée
            $stmt = $pdo->prepare("
                UPDATE plan_invitations 
                SET status = 'selected', 
                    selected_plan = :plan, 
                    selected_at = NOW() 
                WHERE invitation_token = :token
            ");
            $stmt->execute([
                'token' => $input['token'],
                'plan' => $input['plan']
            ]);
            
            LogService::info("Plan sélectionné par l'utilisateur", [
                'user_id' => $userId,
                'selected_plan' => $input['plan'],
                'plan_price' => $selectedPlan['price']
            ]);
            
            // Réponse avec informations sur le plan sélectionné et les prochaines étapes
            $features = json_decode($selectedPlan['features'], true) ?? [];
            
            LoggingMiddleware::logExit(200);
            Response::success('Plan sélectionné avec succès', [
                'selected_plan' => [
                    'name' => $selectedPlan['name'],
                    'display_name' => $selectedPlan['display_name'],
                    'description' => $selectedPlan['description'],
                    'price' => (float)$selectedPlan['price'],
                    'currency' => $selectedPlan['currency'],
                    'features' => $features
                ],
                'next_steps' => [
                    'message' => 'Merci d\'avoir sélectionné le plan ' . $selectedPlan['display_name'] . ' !',
                    'actions' => [
                        'payment' => 'Un email avec les instructions de paiement vous sera envoyé prochainement',
                        'activation' => 'Votre nouveau plan sera activé dès le paiement confirmé',
                        'current_plan' => 'Votre plan gratuit reste actif en attendant'
                    ]
                ],
                'user_id' => $userId
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la sélection de plan", [
                'error' => $e->getMessage(),
                'input' => $input ?? []
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la sélection de plan');
            return false;
        }
    }
    
    /**
     * Afficher les détails d'une invitation plan via token
     * GET /users/choose-plan?token=xxx
     */
    public function viewPlanInvitation()
    {
        try {
            LoggingMiddleware::logEntry();
            
            $token = $_GET['token'] ?? null;
            
            if (!$token) {
                LoggingMiddleware::logExit(400);
                Response::error('Token d\'invitation requis', null, 400);
                return false;
            }
            
            // Vérifier le token d'invitation
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("
                SELECT pi.*, u.name, u.email 
                FROM plan_invitations pi
                JOIN users u ON pi.user_id = u.id
                WHERE pi.invitation_token = :token 
                AND pi.expires_at > NOW()
            ");
            $stmt->execute(['token' => $token]);
            $invitation = $stmt->fetch();
            
            if (!$invitation) {
                LoggingMiddleware::logExit(404);
                Response::error('Invitation non trouvée ou expirée', null, 404);
                return false;
            }
            
            // Marquer l'invitation comme cliquée
            if ($invitation['status'] === 'pending') {
                $stmt = $pdo->prepare("
                    UPDATE plan_invitations 
                    SET status = 'clicked', clicked_at = NOW() 
                    WHERE invitation_token = :token
                ");
                $stmt->execute(['token' => $token]);
            }
            
            // Récupérer les plans disponibles (exclure le gratuit)
            $plans = Plan::getAllActive();
            $availablePlans = array_filter($plans, function($plan) {
                return $plan['name'] !== 'free';
            });
            
            $formattedPlans = array_map(function($plan) {
                $features = json_decode($plan['features'], true) ?? [];
                return [
                    'id' => $plan['id'],
                    'name' => $plan['name'],
                    'display_name' => $plan['display_name'],
                    'description' => $plan['description'],
                    'price' => (float)$plan['price'],
                    'currency' => $plan['currency'],
                    'duration_days' => $plan['duration_days'],
                    'api_rate_limit' => $plan['api_rate_limit'],
                    'features' => $features,
                    'is_recommended' => $plan['name'] === 'argent'
                ];
            }, $availablePlans);
            
            LoggingMiddleware::logExit(200);
            Response::success('Invitation valide', [
                'invitation' => [
                    'token' => $token,
                    'user_name' => $invitation['name'],
                    'user_email' => $invitation['email'],
                    'expires_at' => $invitation['expires_at'],
                    'status' => $invitation['status']
                ],
                'available_plans' => array_values($formattedPlans),
                'message' => 'Sélectionnez le plan qui vous convient le mieux'
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la visualisation de l'invitation plan", [
                'error' => $e->getMessage(),
                'token' => $token ?? null
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la visualisation de l\'invitation');
            return false;
        }
    }
}