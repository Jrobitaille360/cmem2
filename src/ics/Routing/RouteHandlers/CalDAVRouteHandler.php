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
        $path = $request['path'] ?? '';
        $method = $request['method'] ?? 'GET';
        
        // Extraire le chemin après /caldav
        $caldavPath = preg_replace('#^.*/caldav#', '', $path);
        
        // OPTIONS ne nécessite pas d'authentification (discovery)
        if ($method === 'OPTIONS') {
            $controller->handleRequest(null); // null = pas d'auth pour OPTIONS
            return true;
        }
        
        // Routes spéciales pour l'API JSON
        if ($method === 'GET' && preg_match('#^/?service-info/?$#', $caldavPath)) {
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
        
        if ($method === 'GET' && preg_match('#^/?mobile-config/?$#', $caldavPath)) {
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
     * Extrait l'ID utilisateur de la requête (JWT ou session)
     */
    private function getUserIdFromRequest($request): ?int
    {
        // Vérifier le header Authorization
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            // Token JWT
            try {
                $token = $matches[1];
                // Utiliser AuthService pour valider le token
                $userData = \AuthGroups\Services\AuthService::validateToken($token);
                return $userData['user_id'] ?? null;
            } catch (\Exception $e) {
                return null;
            }
        }
        
        // Vérifier la session
        if (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        
        // Vérifier le user_id dans la requête (pour les tests)
        if (isset($request['user_id'])) {
            return $request['user_id'];
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
