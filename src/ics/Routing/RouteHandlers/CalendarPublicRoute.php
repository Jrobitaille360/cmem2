<?php

namespace ICS\Routing\RouteHandlers;

use ICS\Controllers\CalendarController;
use AuthGroups\Routing\BaseRouteHandler;

/**
 * CalendarPublicRoute - Gestionnaire des routes publiques pour le plugin ICS Calendar
 * 
 * Ce gestionnaire traite les requêtes publiques liées aux calendriers,
 * principalement le téléchargement des fichiers ICS via token public.
 */
class CalendarPublicRoute extends BaseRouteHandler
{
    protected bool $requiresAuth = false;
    
    private CalendarController $calendarController;
    
    public function __construct()
    {
        parent::__construct();
        $this->calendarController = new CalendarController();
    }
    
    /**
     * Retourne les contrôleurs supportés par ce gestionnaire
     */
    protected function getSupportedControllers(): array
    {
        return ['calendar'];
    }
    
    /**
     * Traite les requêtes selon l'interface BaseRouteHandler
     */
    protected function handleRoute(array $request)
    {
        $method = $request['method'] ?? 'GET';
        
        // Reconstruire le path à partir des segments
        $segments = $request['segments'] ?? [];
        $path = '/' . implode('/', $segments);
        
        // Route pour télécharger un fichier ICS public via token
        // Format: /calendar/{token}.ics
        if (preg_match('/^\/calendar\/([a-zA-Z0-9]+)\.ics$/', $path, $matches)) {
            if ($method === 'GET') {
                $this->handlePublicIcsDownload($matches[1]);
                return true; // Route traitée
            }
        }
        
        return false; // Route non gérée
    }
    
    /**
     * Traite les requêtes publiques pour le calendrier (méthode legacy)
     */
    public function handleRequest(string $method, string $path, array $params = []): array
    {
        // Route pour télécharger un fichier ICS public via token
        if (preg_match('/^\/calendar\/([a-zA-Z0-9]+)\.ics$/', $path, $matches)) {
            if ($method === 'GET') {
                $this->handlePublicIcsDownload($matches[1]);
                return [
                    'success' => true,
                    'message' => 'Fichier ICS téléchargé'
                ];
            }
        }
        
        return $this->methodNotAllowed();
    }
    
    /**
     * Télécharge un fichier ICS public via le token de partage
     */
    private function handlePublicIcsDownload(string $token): bool
    {
        try {
            // Appeler directement la méthode qui gère déjà les headers et la sortie
            $this->calendarController->getPublicCalendarIcs($token);
            return true;
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erreur lors du téléchargement du calendrier : ' . $e->getMessage(),
                'error_code' => 'DOWNLOAD_ERROR'
            ]);
            return true;
        }
    }
    
    /**
     * Retourne une erreur 405 - Méthode non autorisée
     */
    private function methodNotAllowed(): array
    {
        http_response_code(405);
        return [
            'success' => false,
            'message' => 'Méthode non autorisée pour cette route',
            'error_code' => 'METHOD_NOT_ALLOWED'
        ];
    }
}