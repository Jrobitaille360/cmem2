<?php

namespace Booking\Services;

use AuthGroups\Models\User;
use Stripe\Config\CmemModules;
use Stripe\Services\EntitlementService;

/**
 * Vérifie qu'une page de booking est utilisable côté public : existe, active, et le plan de
 * l'hôte inclut toujours le module booking (pas de rétrogradation depuis la dernière activation).
 * Partagé entre BookingPublicController et BookingReservationController.
 */
class BookingGateService
{
    private const MODULE_KEY = 'booking';

    public static function isPageUsable(?array $page): bool
    {
        if ($page === null || (int) $page['active'] !== 1) {
            return false;
        }
        // Modules cochés de l'hôte (Phase 3) : plus de lecture de plan.
        return (new \Access\Services\ModuleGrantService())->isAvailable((int) $page['owner_id'], self::MODULE_KEY);
    }
}
