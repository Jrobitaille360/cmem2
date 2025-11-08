<?php

namespace ICS\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use ICS\Controllers\CalDAVController;

/**
 * Gestionnaire de routes pour CalDAV
 * Gère les requêtes WebDAV/CalDAV pour la synchronisation des calendriers
 */
class CalDAVRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false; // Gère l'auth en interne
    
    protected function getSupportedControllers(): array
    {
        return ['caldav'];
    }

    /**
     * Gère les requêtes CalDAV
     */
    protected function handleRoute(array $request): bool
    {
        $controller = new CalDAVController();
        $path = $request['path'] ?? $_SERVER['REQUEST_URI'] ?? '';
        $method = $request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Extraire le chemin après /caldav (en gardant le slash initial)
        $caldavPath = preg_replace('#^.*/caldav#', '', parse_url($path, PHP_URL_PATH));
        
        // Debug: Log le chemin extrait
        \AuthGroups\Services\LogService::debug("CalDAV path extracted", [
            'original_path' => $path,
            'caldav_path' => $caldavPath,
            'method' => $method
        ]);
        
        // OPTIONS ne nécessite pas d'authentification (discovery)
        if ($method === 'OPTIONS') {
            $controller->handleRequest(null); // null = pas d'auth pour OPTIONS
            return true;
        }
        
        // Routes spéciales pour l'API JSON
        // Normaliser le chemin pour correspondre avec ou sans slash initial
        $normalizedPath = '/' . trim($caldavPath, '/');
        
        if ($method === 'GET' && ($normalizedPath === '/service-info' || $caldavPath === '/service-info')) {
            // GET /caldav/service-info - Informations sur le service
            $userId = $this->getUserIdFromRequest($request);
            if (!$userId) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return true;
            }
            $controller->getServiceInfo($userId);
            return true;
        }
        
        if ($method === 'GET' && ($normalizedPath === '/mobile-config' || $caldavPath === '/mobile-config')) {
            // GET /caldav/mobile-config - Configuration mobile
            $userId = $this->getUserIdFromRequest($request);
            if (!$userId) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return true;
            }
            $controller->generateMobileConfig($userId);
            return true;
        }
        
        // Vérifier si c'est un calendrier public (lecture seule)
        if ($this->isPublicCalendarRequest($caldavPath)) {
            $controller->handlePublicRequest();
        } else {
            // Nécessite l'authentification
            $userId = $this->getUserIdFromRequest($request);
            if (!$userId) {
                http_response_code(401);
                header('WWW-Authenticate: Bearer realm="CalDAV"');
                echo '<?xml version="1.0" encoding="UTF-8"?>';
                echo '<d:error xmlns:d="DAV:">';
                echo '<d:need-privileges><d:privilege><d:read/></d:privilege></d:need-privileges>';
                echo '</d:error>';
                return true;
            }
            
            $controller->handleRequest($userId);
        }
        
        return true;
    }

    /**
     * Vérifie si la requête est pour un calendrier public
     */
    private function isPublicCalendarRequest($path): bool
    {
        // Les calendriers publics peuvent être accédés via leur share_token
        // Format: /caldav/{share_token}/...
        
        // Pour l'instant, on considère que toutes les requêtes nécessitent l'auth
        // sauf si on détecte explicitement un share_token public
        
        // TODO: Implémenter la logique de détection de calendrier public
        return false;
    }

    /**
     * Extrait l'ID utilisateur de la requête (API Key ou session)
     */
    private function getUserIdFromRequest($request): ?int
    {
        // 1. Vérifier d'abord les API Keys (X-API-Key ou Authorization: Bearer ag_xxx)
        $apiKey = $this->getApiKeyFromRequest();
        if ($apiKey) {
            // Valider la clé API
            $keyData = \AuthGroups\Models\ApiKey::validate($apiKey);
            
            if ($keyData && is_array($keyData)) {
                // Clé valide, retourner l'ID utilisateur
                \AuthGroups\Services\LogService::info("CalDAV: Authentification API Key réussie", [
                    'user_id' => $keyData['user_id'],
                    'api_key_id' => $keyData['id']
                ]);
                return $keyData['user_id'];
            } else {
                // Clé invalide ou révoquée
                \AuthGroups\Services\LogService::warning("CalDAV: API Key invalide ou révoquée", [
                    'api_key_prefix' => substr($apiKey, 0, 20) . '...'
                ]);
                return null;
            }
        }
        
        // 2. Vérifier la session
        if (isset($_SESSION['user_id'])) {
            \AuthGroups\Services\LogService::info("CalDAV: Authentification par session", [
                'user_id' => $_SESSION['user_id']
            ]);
            return $_SESSION['user_id'];
        }
        
        // 3. Vérifier le user_id dans la requête (pour les tests)
        if (isset($request['user_id'])) {
            \AuthGroups\Services\LogService::info("CalDAV: Authentification de test", [
                'user_id' => $request['user_id']
            ]);
            return $request['user_id'];
        }
        
        \AuthGroups\Services\LogService::warning("CalDAV: Aucune méthode d'authentification détectée");
        return null;
    }
    
    /**
     * Extrait la clé API depuis les headers
     */
    private function getApiKeyFromRequest(): ?string
    {
        // 1. Header X-API-Key (recommandé pour CalDAV)
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        // 2. Header Authorization: Bearer ag_xxx
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = trim($matches[1]);
                
                // Vérifier si c'est une API key (commence par ag_)
                if (str_starts_with($token, 'ag_live_') || str_starts_with($token, 'ag_test_')) {
                    return $token;
                }
            }
        }
        
        return null;
    }

    /**
     * Vérifie si ce handler peut gérer la requête
     */
    public function canHandle(string $controller): bool
    {
        return $controller === 'caldav';
    }

    /**
     * Priorité haute pour CalDAV (traiter avant les autres)
     */
    public function getPriority(): int
    {
        return 100;
    }
}
