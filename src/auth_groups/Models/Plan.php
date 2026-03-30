<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Modèle pour la gestion des plans de paiement
 */
class Plan extends BaseModel
{
    protected $table = 'plans';
    
    // Plans disponibles
    const PLAN_FREE = 'free';
    const PLAN_BRONZE = 'bronze';
    const PLAN_ARGENT = 'argent';
    const PLAN_PLATINE = 'platine';
    
    public $id;
    public $name;
    public $display_name;
    public $description;
    public $price;
    public $currency;
    public $duration_days;
    public $api_rate_limit;
    public $features;
    public $is_active;
    public $created_at;
    public $updated_at;
    
    /**
     * Obtenir tous les plans disponibles
     */
    public static function getAllActive()
    {
        $model = new self();
        $db = $model->getDb();
        
        $stmt = $db->prepare("
            SELECT * FROM plans 
            WHERE is_active = 1 
            ORDER BY price ASC
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtenir un plan actif par son ID
     */
    public static function getActiveById(int $id)
    {
        $model = new self();
        $db = $model->getDb();

        $stmt = $db->prepare("
            SELECT * FROM plans
            WHERE id = :id AND is_active = 1
        ");

        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir un plan par son nom
     */
    public static function findByName(string $planName)
    {
        $model = new self();
        $db = $model->getDb();
        
        $stmt = $db->prepare("
            SELECT * FROM plans 
            WHERE name = :name AND is_active = 1
        ");
        
        $stmt->execute([':name' => $planName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Initialiser les plans par défaut dans la base de données
     */
    public static function initializeDefaultPlans()
    {
        $defaultPlans = [
            [
                'name' => self::PLAN_FREE,
                'display_name' => 'Plan Gratuit',
                'description' => 'Plan gratuit avec limitations pour tester l\'API',
                'price' => 0.00,
                'currency' => 'EUR',
                'duration_days' => 30,
                'api_rate_limit' => 10,
                'features' => json_encode([
                    'scopes' => ['read'],
                    'max_requests_per_day' => 1000,
                    'expires_in_days' => 7, // Expire rapidement pour encourager upgrade
                    'email_support' => false,
                    'priority_support' => false
                ]),
                'is_active' => 1
            ],
            [
                'name' => self::PLAN_BRONZE,
                'display_name' => 'Plan Bronze',
                'description' => 'Plan bronze avec fonctionnalités essentielles',
                'price' => 9.99,
                'currency' => 'EUR',
                'duration_days' => 30,
                'api_rate_limit' => 100,
                'features' => json_encode([
                    'scopes' => ['read', 'write'],
                    'max_requests_per_day' => 10000,
                    'expires_in_days' => null, // Pas d'expiration
                    'email_support' => true,
                    'priority_support' => false
                ]),
                'is_active' => 1
            ],
            [
                'name' => self::PLAN_ARGENT,
                'display_name' => 'Plan Argent',
                'description' => 'Plan argent avec fonctionnalités avancées',
                'price' => 19.99,
                'currency' => 'EUR',
                'duration_days' => 30,
                'api_rate_limit' => 300,
                'features' => json_encode([
                    'scopes' => ['read', 'write', 'delete'],
                    'max_requests_per_day' => 50000,
                    'expires_in_days' => null,
                    'email_support' => true,
                    'priority_support' => true,
                    'webhook_support' => true
                ]),
                'is_active' => 1
            ],
            [
                'name' => self::PLAN_PLATINE,
                'display_name' => 'Plan Platine',
                'description' => 'Plan platine avec toutes les fonctionnalités premium',
                'price' => 49.99,
                'currency' => 'EUR',
                'duration_days' => 30,
                'api_rate_limit' => 1000,
                'features' => json_encode([
                    'scopes' => ['read', 'write', 'delete', 'admin'],
                    'max_requests_per_day' => 'unlimited',
                    'expires_in_days' => null,
                    'email_support' => true,
                    'priority_support' => true,
                    'webhook_support' => true,
                    'custom_integrations' => true,
                    'dedicated_support' => true
                ]),
                'is_active' => 1
            ]
        ];
        
        $model = new self();
        $db = $model->getDb();
        
        foreach ($defaultPlans as $planData) {
            // Vérifier si le plan existe déjà
            $stmt = $db->prepare("SELECT id FROM plans WHERE name = :name");
            $stmt->execute([':name' => $planData['name']]);
            
            if (!$stmt->fetch()) {
                // Insérer le plan
                $stmt = $db->prepare("
                    INSERT INTO plans 
                    (name, display_name, description, price, currency, duration_days, 
                     api_rate_limit, features, is_active)
                    VALUES 
                    (:name, :display_name, :description, :price, :currency, :duration_days,
                     :api_rate_limit, :features, :is_active)
                ");
                
                $stmt->execute($planData);
            }
        }
    }
    
    /**
     * Créer un plan
     */
    public function create()
    {
        $db = $this->getDb();
        $stmt = $db->prepare("
            INSERT INTO plans 
            (name, display_name, description, price, currency, duration_days, 
             api_rate_limit, features, is_active)
            VALUES 
            (:name, :display_name, :description, :price, :currency, :duration_days,
             :api_rate_limit, :features, :is_active)
        ");
        
        $result = $stmt->execute([
            ':name' => $this->name,
            ':display_name' => $this->display_name,
            ':description' => $this->description,
            ':price' => $this->price,
            ':currency' => $this->currency,
            ':duration_days' => $this->duration_days,
            ':api_rate_limit' => $this->api_rate_limit,
            ':features' => $this->features,
            ':is_active' => $this->is_active ?? 1
        ]);
        
        if ($result) {
            $this->id = $db->lastInsertId();
        }
        
        return $result;
    }
    
    /**
     * Mettre à jour un plan
     */
    public function update()
    {
        $db = $this->getDb();
        $stmt = $db->prepare("
            UPDATE plans SET 
                display_name = :display_name,
                description = :description,
                price = :price,
                currency = :currency,
                duration_days = :duration_days,
                api_rate_limit = :api_rate_limit,
                features = :features,
                is_active = :is_active
            WHERE id = :id
        ");
        
        return $stmt->execute([
            ':id' => $this->id,
            ':display_name' => $this->display_name,
            ':description' => $this->description,
            ':price' => $this->price,
            ':currency' => $this->currency,
            ':duration_days' => $this->duration_days,
            ':api_rate_limit' => $this->api_rate_limit,
            ':features' => $this->features,
            ':is_active' => $this->is_active
        ]);
    }
}