<?php

namespace ICS\Controllers;

use ICS\Services\CalDAVServer;
use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;

/**
 * Contrôleur pour gérer les requêtes CalDAV/WebDAV
 */
class CalDAVController
{
    /**
     * Point d'entrée principal pour toutes les requêtes CalDAV
     * Gère l'authentification et délègue au serveur CalDAV
     */
    public function handleRequest($userId = null): void
    {
        LoggingMiddleware::logEntry();
        
        // Définir les headers CORS pour CalDAV
        $this->setCaldavHeaders();
        
        // Pour OPTIONS, pas besoin d'authentification
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->handleOptions();
            return;
        }
        
        try {
            // Créer une instance du serveur CalDAV
            require_once __DIR__ . '/../../../config/database.php';
            $db = \Database::getInstance()->getConnection();
            $caldavServer = new CalDAVServer($db, $userId);
            
            LogService::info("CalDAV request", [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI'],
                'user_id' => $userId
            ]);
            
            // Déléguer au serveur CalDAV
            $caldavServer->handleRequest();
            
        } catch (\Exception $e) {
            LogService::error("Erreur CalDAV", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            LoggingMiddleware::logExit(500);
            http_response_code(500);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<d:error xmlns:d="DAV:">';
            echo '<d:exception>' . htmlspecialchars($e->getMessage()) . '</d:exception>';
            echo '</d:error>';
            exit;
        }
    }

    /**
     * Gère les requêtes CalDAV publiques (sans authentification)
     * Utile pour les calendriers publics en lecture seule
     */
    public function handlePublicRequest(): void
    {
        LoggingMiddleware::logEntry();
        
        $this->setCaldavHeaders();
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->handleOptions();
            return;
        }
        
        // Vérifier que c'est une requête en lecture seule
        $readOnlyMethods = ['GET', 'PROPFIND', 'REPORT', 'OPTIONS'];
        
        if (!in_array($_SERVER['REQUEST_METHOD'], $readOnlyMethods)) {
            LogService::warning("Tentative de modification sur calendrier public", [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI']
            ]);
            
            http_response_code(403);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<d:error xmlns:d="DAV:">';
            echo '<d:need-privileges><d:privilege><d:write/></d:privilege></d:need-privileges>';
            echo '</d:error>';
            exit;
        }
        
        try {
            require_once __DIR__ . '/../../../config/database.php';
            $db = \Database::getInstance()->getConnection();
            $caldavServer = new CalDAVServer($db, null);
            
            LogService::info("CalDAV public request", [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI']
            ]);
            
            $caldavServer->handleRequest();
            
        } catch (\Exception $e) {
            LogService::error("Erreur CalDAV public", [
                'exception' => $e->getMessage()
            ]);
            
            LoggingMiddleware::logExit(500);
            http_response_code(500);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<d:error xmlns:d="DAV:">';
            echo '<d:exception>' . htmlspecialchars($e->getMessage()) . '</d:exception>';
            echo '</d:error>';
            exit;
        }
    }

    /**
     * Retourne les informations de configuration CalDAV pour un utilisateur
     * Utile pour la configuration automatique des clients
     */
    public function getServiceInfo($userId): void
    {
        LoggingMiddleware::logEntry();
        
        try {
            $serviceInfo = [
                'caldav_url' => BASE_URL . '/caldav/',
                'caldav_principal' => BASE_URL . '/caldav/principals/' . $userId,
                'caldav_version' => '1.0',
                'supported_features' => [
                    'calendar-access',
                    'calendar-schedule',
                    'calendar-auto-schedule',
                    'calendar-availability',
                    'sync-collection'
                ],
                'supported_components' => ['VEVENT'],
                'supported_methods' => [
                    'OPTIONS',
                    'GET',
                    'PUT',
                    'DELETE',
                    'PROPFIND',
                    'REPORT',
                    'MKCALENDAR',
                    'LOCK',
                    'UNLOCK',
                    'PROPPATCH'
                ],
                'authentication' => 'Bearer token required (JWT)',
                'instructions' => [
                    'thunderbird' => [
                        'url' => BASE_URL . '/caldav/',
                        'username' => 'Use your email',
                        'password' => 'Use your JWT token'
                    ],
                    'apple_calendar' => [
                        'account_type' => 'CalDAV',
                        'server' => str_replace(['http://', 'https://'], '', BASE_URL) . '/caldav/',
                        'username' => 'Use your email',
                        'password' => 'Use your JWT token',
                        'port' => parse_url(BASE_URL, PHP_URL_SCHEME) === 'https' ? 443 : 80
                    ],
                    'outlook' => [
                        'note' => 'Outlook ne supporte pas CalDAV nativement. Utilisez l\'export ICS ou un plugin tiers.'
                    ],
                    'android' => [
                        'app' => 'DAVx⁵ (recommandé)',
                        'url' => BASE_URL . '/caldav/',
                        'username' => 'Use your email',
                        'password' => 'Use your JWT token'
                    ]
                ],
                'example_urls' => [
                    'calendar_list' => BASE_URL . '/caldav/',
                    'specific_calendar' => BASE_URL . '/caldav/{share_token}/',
                    'specific_event' => BASE_URL . '/caldav/{share_token}/{event_uid}.ics'
                ]
            ];
            
            LoggingMiddleware::logExit(200);
            Response::success('Informations du service CalDAV', $serviceInfo);
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des infos CalDAV", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des informations', 500);
        }
    }

    /**
     * Génère un fichier de configuration pour les clients CalDAV
     * Format: .mobileconfig pour iOS/macOS
     */
    public function generateMobileConfig($userId): void
    {
        LoggingMiddleware::logEntry();
        
        try {
            // Récupérer les informations de l'utilisateur
            require_once __DIR__ . '/../../../config/database.php';
            $stmt = \Database::getInstance()->getConnection()->prepare("
                SELECT email, firstname, lastname FROM users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                Response::error('Utilisateur non trouvé', 404);
                return;
            }
            
            $domain = parse_url(BASE_URL, PHP_URL_HOST);
            $uuid = strtoupper(bin2hex(random_bytes(16)));
            
            $config = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $config .= '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n";
            $config .= '<plist version="1.0">' . "\n";
            $config .= '<dict>' . "\n";
            $config .= '    <key>PayloadContent</key>' . "\n";
            $config .= '    <array>' . "\n";
            $config .= '        <dict>' . "\n";
            $config .= '            <key>CalDAVAccountDescription</key>' . "\n";
            $config .= '            <string>CMEM2 Calendar</string>' . "\n";
            $config .= '            <key>CalDAVHostName</key>' . "\n";
            $config .= '            <string>' . $domain . '</string>' . "\n";
            $config .= '            <key>CalDAVPort</key>' . "\n";
            $config .= '            <integer>' . (parse_url(BASE_URL, PHP_URL_SCHEME) === 'https' ? 443 : 80) . '</integer>' . "\n";
            $config .= '            <key>CalDAVPrincipalURL</key>' . "\n";
            $config .= '            <string>' . BASE_URL . '/caldav/principals/' . $userId . '</string>' . "\n";
            $config .= '            <key>CalDAVUseSSL</key>' . "\n";
            $config .= '            <' . (parse_url(BASE_URL, PHP_URL_SCHEME) === 'https' ? 'true' : 'false') . '/>' . "\n";
            $config .= '            <key>CalDAVUsername</key>' . "\n";
            $config .= '            <string>' . htmlspecialchars($user['email']) . '</string>' . "\n";
            $config .= '            <key>PayloadDescription</key>' . "\n";
            $config .= '            <string>Configure CMEM2 CalDAV account</string>' . "\n";
            $config .= '            <key>PayloadDisplayName</key>' . "\n";
            $config .= '            <string>CMEM2 Calendar</string>' . "\n";
            $config .= '            <key>PayloadIdentifier</key>' . "\n";
            $config .= '            <string>com.cmem2.caldav.' . $uuid . '</string>' . "\n";
            $config .= '            <key>PayloadType</key>' . "\n";
            $config .= '            <string>com.apple.caldav.account</string>' . "\n";
            $config .= '            <key>PayloadUUID</key>' . "\n";
            $config .= '            <string>' . $uuid . '</string>' . "\n";
            $config .= '            <key>PayloadVersion</key>' . "\n";
            $config .= '            <integer>1</integer>' . "\n";
            $config .= '        </dict>' . "\n";
            $config .= '    </array>' . "\n";
            $config .= '    <key>PayloadDisplayName</key>' . "\n";
            $config .= '    <string>CMEM2 Calendar Configuration</string>' . "\n";
            $config .= '    <key>PayloadIdentifier</key>' . "\n";
            $config .= '    <string>com.cmem2.caldav.profile</string>' . "\n";
            $config .= '    <key>PayloadRemovalDisallowed</key>' . "\n";
            $config .= '    <false/>' . "\n";
            $config .= '    <key>PayloadType</key>' . "\n";
            $config .= '    <string>Configuration</string>' . "\n";
            $config .= '    <key>PayloadUUID</key>' . "\n";
            $config .= '    <string>' . strtoupper(bin2hex(random_bytes(16))) . '</string>' . "\n";
            $config .= '    <key>PayloadVersion</key>' . "\n";
            $config .= '    <integer>1</integer>' . "\n";
            $config .= '</dict>' . "\n";
            $config .= '</plist>';
            
            LoggingMiddleware::logExit(200);
            
            header('Content-Type: application/x-apple-aspen-config; charset=utf-8');
            header('Content-Disposition: attachment; filename="cmem2-caldav.mobileconfig"');
            header('Content-Length: ' . strlen($config));
            echo $config;
            exit;
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération de la config mobile", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la génération de la configuration', 500);
        }
    }

    /**
     * Définit les headers nécessaires pour CalDAV
     */
    private function setCaldavHeaders(): void
    {
        // Headers CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, MKCALENDAR, LOCK, UNLOCK, PROPPATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Depth, If-Match, If-None-Match, Lock-Token, Timeout, Prefer');
        header('Access-Control-Expose-Headers: DAV, ETag, Lock-Token');
        
        // Headers CalDAV
        header('DAV: 1, 2, calendar-access, calendar-schedule');
    }

    /**
     * Gère la requête OPTIONS
     */
    private function handleOptions(): void
    {
        header('Allow: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, MKCALENDAR, LOCK, UNLOCK, PROPPATCH');
        header('Content-Length: 0');
        http_response_code(200);
        exit;
    }
}
