<?php

namespace AuthGroups\Models;

/**
 * Modèle spécifique pour les opérations admin secretes
 */
class AdminModel extends BaseModel 
{
    protected $table = null; // Pas de table spécifique
    
    public function create() {
        // Non implémenté pour ce modèle
        return false;
    }
    
    public function update() {
        // Non implémenté pour ce modèle  
        return false;
    }
}