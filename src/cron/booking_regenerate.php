<?php

/**
 * Cron — Roulement d'horizon et resynchronisation des zones de réservation publique
 *
 * À planifier : 1x/jour (ex. 04:00)
 *   crontab : 0 4 * * * php /path/to/src/cron/booking_regenerate.php >> /path/to/logs/booking-$(date +\%Y-\%m-\%d).log 2>&1
 *
 * Pour chaque booking_pages.active = 1 :
 *   - Supprime les zones non réservées futures, régénère sur l'horizon configuré (même moteur
 *     que PUT /booking/page — Booking\Services\BookingSlotService::regenerate()).
 *   - Un événement ajouté manuellement par l'hôte après une génération précédente finit ainsi
 *     par bloquer la zone correspondante au prochain passage (délai acceptable : jusqu'au
 *     prochain cron, pas temps réel — décision actée dans la directive booking-public).
 *   - Les zones réservées ne sont jamais touchées (garanti par BookingSlotService).
 */

// Sécurité : refuser l'exécution depuis le web
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.');
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';
require_once $rootDir . '/src/ics/autoloader.php';
require_once $rootDir . '/src/booking/autoloader.php';

use AuthGroups\Services\LogService;
use Booking\Services\BookingSlotService;

$startedAt = date('Y-m-d H:i:s');
$regenerated = 0;
$errors      = 0;

try {
    $db = \Database::getInstance()->getConnection();

    $stmt = $db->query("SELECT id, slug FROM booking_pages WHERE active = 1");
    $pages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $service = new BookingSlotService();

    foreach ($pages as $page) {
        try {
            $inserted = $service->regenerate((int) $page['id']);
            LogService::info('booking_regenerate: page régénérée', [
                'booking_page_id' => $page['id'],
                'slug'             => $page['slug'],
                'zones_generees'   => $inserted,
            ]);
            $regenerated++;
        } catch (\Throwable $e) {
            LogService::error('booking_regenerate: erreur sur page', [
                'booking_page_id' => $page['id'],
                'slug'             => $page['slug'],
                'error'            => $e->getMessage(),
            ]);
            $errors++;
        }
    }
} catch (\Throwable $e) {
    LogService::error('booking_regenerate: erreur fatale', ['error' => $e->getMessage()]);
    echo "[{$startedAt}] booking_regenerate.php — ERREUR FATALE : {$e->getMessage()}\n";
    exit(1);
}

// Rapport
echo "[{$startedAt}] booking_regenerate.php\n";
echo "  pages régénérées : {$regenerated}\n";
echo "  erreurs          : {$errors}\n";
echo "\n";
