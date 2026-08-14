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
        $userData = (new User())->findById((int) $page['owner_id']);
        $override = $userData['cmem_plan_override'] ?? null;
        $plan = EntitlementService::getEffectivePlanForCmem((int) $page['owner_id'], $override)['code'];

        return CmemModules::isAvailable($plan, self::MODULE_KEY);
    }
}
