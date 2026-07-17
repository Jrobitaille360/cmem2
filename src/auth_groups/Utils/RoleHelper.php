<?php

namespace AuthGroups\Utils;

/**
 * Point de vérité unique pour la hiérarchie des rôles utilisateur.
 * Valeurs espacées (10/20/30) pour permettre l'insertion future d'un rôle
 * intermédiaire (ex. INVITÉ) sans renuméroter la hiérarchie existante.
 */
class RoleHelper {
    private const HIERARCHY = [
        'UTILISATEUR' => 10,
        'ADMINISTRATEUR' => 20,
        'SUPERADMINISTRATEUR' => 30,
    ];

    public static function isAtLeast(?string $role, string $minRole): bool {
        return ($role !== null && array_key_exists($role, self::HIERARCHY) ? self::HIERARCHY[$role] : -1)
            >= (self::HIERARCHY[$minRole] ?? PHP_INT_MAX);
    }
}
