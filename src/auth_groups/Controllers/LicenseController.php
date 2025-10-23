<?php
namespace AuthGroups\Controllers;

use AuthGroups\Models\ApiKey;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;

class LicenseController {
    
    private $userModel;
    private $apiKeyModel;
    private $emailService;

    public function __construct()
    {
        $this->userModel = new User();
        $this->apiKeyModel = new ApiKey();
        $this->emailService = new EmailService();
    }
    
    /**
     * Générer une licence après paiement (webhook Stripe/PayPal)
     * 
     * @param int $userId ID de l'utilisateur
     * @param string $plan Plan de paiement (basic, standard, premium, lifetime)
     * @return array
     */
    public function generateLicenseAfterPayment($userId, $plan = 'standard')
    {
        try {
            // 1. Récupérer l'utilisateur via findById
            $user = $this->userModel->findById($userId);
            
            if (!$user) {
                LogService::error('Génération licence: utilisateur introuvable', ['user_id' => $userId]);
                Response::error('Utilisateur introuvable', null, 404);
                return ['success' => false, 'message' => 'Utilisateur introuvable'];
            }
            
            // 2. Vérifier si l'utilisateur a déjà une licence active et la révoquer
            $existingKeys = $this->apiKeyModel->getByUserId($userId);
            $hasActiveLicense = false;
            
            if ($existingKeys && is_array($existingKeys)) {
                foreach ($existingKeys as $key) {
                    if ($key['revoked_at'] === null && $key['environment'] === 'production') {
                        $hasActiveLicense = true;
                        // Révoquer l'ancienne clé avant d'en créer une nouvelle
                        $this->apiKeyModel->revoke($key['id'], 'Remplacée par nouveau paiement');
                    }
                }
            }
            
            // 3. Calculer la date d'expiration selon le plan
            $expiryDate = $this->calculateExpiry($plan);
            
            // 4. Mettre à jour les informations de paiement de l'utilisateur via requête SQL directe
            require_once __DIR__ . '/../database.php';
            $db = \Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                UPDATE users 
                SET payment_status = :payment_status,
                    payment_plan = :payment_plan,
                    payment_date = :payment_date,
                    license_expires_at = :license_expires_at,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND deleted_at IS NULL
            ");
            
            $stmt->execute([
                ':payment_status' => 'paid',
                ':payment_plan' => $plan,
                ':payment_date' => date('Y-m-d H:i:s'),
                ':license_expires_at' => $expiryDate,
                ':id' => $userId
            ]);
            
            // 5. Créer une nouvelle API Key pour cet utilisateur via ApiKey::generate()
            $scopes = $this->getScopesForPlan($plan);
            $rateLimit = $this->getRateLimitForPlan($plan);
            
            // Calculer le nombre de jours jusqu'à l'expiration
            $expiresInDays = $plan === 'lifetime' ? null : ceil((strtotime($expiryDate) - time()) / 86400);
            
            // Options pour la génération de la clé
            $keyOptions = [
                'scopes' => $scopes,
                'environment' => 'production',
                'rate_limit_per_minute' => $rateLimit,
                'rate_limit_per_hour' => $rateLimit * 60,
                'notes' => "License {$plan} générée après paiement"
            ];
            
            if ($expiresInDays !== null) {
                $keyOptions['expires_in_days'] = (int)$expiresInDays;
            }
            
            // Générer la clé API
            $apiKeyResult = ApiKey::generate(
                $userId,
                "License {$plan} - User {$userId}",
                $keyOptions
            );
            
            $apiKey = $apiKeyResult['key']; // Clé complète (ag_live_xxx...)
            
            // 6. Envoyer l'email avec la clé API
            $this->sendLicenseEmail(
                $user['email'],
                $user['name'],
                $apiKey,
                $plan
            );
            
            // 7. Logger l'événement (SANS la clé complète!)
            LogService::info('Licence générée avec succès', [
                'user_id' => $userId,
                'plan' => $plan,
                'expires_at' => $expiryDate,
                'key_prefix' => substr($apiKey, 0, 12) . '...',
                'replaced_license' => $hasActiveLicense
            ]);
            
            return [
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'plan' => $plan,
                    'expires_at' => $expiryDate,
                    'scopes' => $scopes,
                    'rate_limit_per_minute' => $rateLimit,
                    // NE JAMAIS retourner la clé complète dans les logs/réponses!
                    'api_key_prefix' => substr($apiKey, 0, 12) . '...',
                    'api_key_last4' => substr($apiKey, -4)
                ],
                'message' => 'Licence générée avec succès'
            ];
            
        } catch (\Exception $e) {
            LogService::error('Erreur génération licence', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'plan' => $plan,
                'trace' => $e->getTraceAsString()
            ]);
            Response::error('Erreur génération licence', $e->getMessage(), 500);
            return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }
    
    /**
     * Calculer la date d'expiration selon le plan
     */
    private function calculateExpiry($plan) {
        switch ($plan) {
            case 'monthly': 
                return date('Y-m-d H:i:s', strtotime('+1 month'));
            case 'basic':
            case 'standard':
            case 'yearly': 
                return date('Y-m-d H:i:s', strtotime('+1 year'));
            case 'premium':
                return date('Y-m-d H:i:s', strtotime('+2 years'));
            case 'lifetime': 
                return null; // Jamais expirer
            default: 
                return date('Y-m-d H:i:s', strtotime('+1 year'));
        }
    }
    
    /**
     * Obtenir les scopes selon le plan
     */
    private function getScopesForPlan($plan) {
        switch ($plan) {
            case 'basic':
                return ['read'];
            case 'standard':
            case 'yearly':
            case 'monthly':
                return ['read', 'write'];
            case 'premium':
            case 'lifetime':
                return ['read', 'write', 'delete'];
            default:
                return ['read'];
        }
    }
    
    /**
     * Obtenir le rate limit selon le plan
     */
    private function getRateLimitForPlan($plan) {
        switch ($plan) {
            case 'basic': 
                return 60;
            case 'standard':
            case 'monthly':
            case 'yearly': 
                return 200;
            case 'premium': 
                return 500;
            case 'lifetime': 
                return 1000;
            default: 
                return 100;
        }
    }
    
    /**
     * Envoyer l'email avec la clé API
     */
    private function sendLicenseEmail($email, $name, $apiKey, $plan) {
        $emailService = new EmailService();
        
        $subject = "🎉 Votre licence CMEM est activée!";
        
        $body = "
        <h2>Bienvenue {$name}!</h2>
        <p>Merci d'avoir choisi notre service. Votre licence <strong>{$plan}</strong> est maintenant active.</p>
        
        <div style='background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;'>
            <h3>Votre clé API:</h3>
            <code style='background: #fff; padding: 10px; display: block; font-size: 14px;'>{$apiKey}</code>
        </div>
        
        <h3>Comment activer votre application:</h3>
        <ol>
            <li>Ouvrez l'application mobile</li>
            <li>Entrez votre ID utilisateur et cette clé API</li>
            <li>Profitez de toutes les fonctionnalités!</li>
        </ol>
        
        <p><strong>⚠️ Important:</strong> Conservez cette clé en lieu sûr. Ne la partagez avec personne.</p>
        
        <hr>
        <p style='color: #666; font-size: 12px;'>
            Support: support@cmem.com<br>
            Documentation: https://docs.cmem.com
        </p>
        ";
        
        try {
            $this->emailService->sendEmail($email, $subject, $body, true);
        } catch (\Exception $e) {
            LogService::error('Erreur envoi email licence', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Vérifier et renouveler une licence
     */
    public function renewLicense($userId, $plan) {
        return $this->generateLicenseAfterPayment($userId, $plan);
    }
    
    /**
     * Révoquer une licence (annulation)
     */
    public function revokeLicense($userId, $reason = 'Cancelled by user') {
        try {
            $apiKeyModel = new ApiKey();
            
            // Révoquer toutes les API Keys de l'utilisateur
            $apiKeys = $apiKeyModel->getByUserId($userId);
            foreach ($apiKeys as $key) {
                $apiKeyModel->revoke($key['id'], $reason);
            }
            
            // Mettre à jour le statut utilisateur via requête SQL directe
            require_once __DIR__ . '/../database.php';
            $db = \Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                UPDATE users 
                SET payment_status = 'expired',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND deleted_at IS NULL
            ");
            
            $stmt->execute([':id' => $userId]);
            
            return Response::success(['message' => 'Licence révoquée']);
            
        } catch (\Exception $e) {
            return Response::error('Erreur révocation', $e->getMessage(), 500);
        }
    }
}