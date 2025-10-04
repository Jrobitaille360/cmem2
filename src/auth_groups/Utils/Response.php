<?php

namespace Memories\Utils;

use Memories\Services\LogService;

class Response {
    
    /**
     * Envoyer une réponse JSON de succès
     */
    public static function success($message = 'Success', $data = null, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Envoyer une réponse JSON d'erreur
     */
    public static function error($message = 'Error', $errors = null, $status = 400) {
        http_response_code($status);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Envoyer une réponse JSON paginée
     */
    public static function paginated($data, $pagination, $message = 'Success', $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => $pagination,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Définir les en-têtes CORS
     */
    public static function setCorsHeaders() {
        $allowedOrigins = ALLOWED_ORIGINS ?? ['*'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        
        // Gérer les requêtes OPTIONS (preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
    
    /**
     * Valider le Content-Type JSON
     */
    public static function validateJsonRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'PATCH') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') === false) {
                self::error('Content-Type doit être application/json', null, 415);
            }
        }
    }
       
    /**
     * Obtenir les paramètres de pagination depuis l'URL (GET) ou le body JSON (POST/PUT/PATCH/DELETE)
     */
    public static function getPaginationParams() {
        $params=Response::getRequestParams();
        $page = max(1, intval($params['page'] ?? 1));
        $limit = intval($params['limit'] ?? 0);
        
        // Utiliser des valeurs par défaut si les constantes ne sont pas définies
        $defaultPageSize = defined('DEFAULT_PAGE_SIZE') ? DEFAULT_PAGE_SIZE : 20;
        $maxPageSize = defined('MAX_PAGE_SIZE') ? MAX_PAGE_SIZE : 100;
        
        if ($limit <= 0) {
            $limit = $defaultPageSize;
        }
        $limit = min($limit, $maxPageSize);
        
        return ['page' => $page, 'limit' => $limit];
    }
    
    /**
     * Obtenir tous les paramètres de la requête selon la méthode HTTP
     */
    public static function getRequestParams() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            // Si des fichiers sont transférés, utiliser $_POST
            if (!empty($_FILES)) {
                return $_POST;
            }

            // Sinon, récupérer depuis le body JSON avec validation stricte
            $jsonContent = file_get_contents('php://input');
            
            // Si pas de contenu, retourner tableau vide
            if (empty($jsonContent) || trim($jsonContent) === '') {
                return [];
            }
            
            // Décoder et vérifier les erreurs JSON
            $input = json_decode($jsonContent, true);
            
            // Si erreur JSON, lever une exception ou retourner une erreur
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Option 1: Lever une exception (plus strict)
                // throw new \InvalidArgumentException("JSON malformé: " . json_last_error_msg());
                
                // Option 2: Logger l'erreur et retourner tableau vide (comportement actuel amélioré)
                LogService::warning("JSON malformé détecté", [
                    'json_error' => json_last_error_msg(),
                    'raw_content' => substr($jsonContent, 0, 200) // Premiers 200 caractères pour debug
                ]);
                
                // Retourner tableau vide maintient la compatibilité
                return [];
            }
            
            return $input ?? [];
        } else {
            // Pour GET et autres, essayer d'abord $_GET, puis parser l'URL complète si $_GET est vide
            $params = $_GET;
            
            // Si $_GET est vide, essayer de parser l'URL complète
            if (empty($params) && isset($_SERVER['REQUEST_URI'])) {
                $urlParts = parse_url($_SERVER['REQUEST_URI']);
                if (isset($urlParts['query'])) {
                    parse_str($urlParts['query'], $params);
                }
            }
            
            return $params;
        }
    }
    
    /**
     * Créer un objet de pagination
     */
    public static function createPagination($page, $limit, $total) {
        $totalPages = ceil($total / $limit);
        
        return [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ];
    }
    
    /**
     * Envoyer un fichier
     */
    public static function sendFile($filePath, $fileName = null, $mimeType = null) {
        if (!file_exists($filePath)) {
            self::error('Fichier non trouvé', null, 404);
        }
        
        $fileName = $fileName ?: basename($filePath);
        $mimeType = $mimeType ?: mime_content_type($filePath);
        
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=3600');
        
        readfile($filePath);
        exit;
    }
    
    /**
     * Redirection
     */
    public static function redirect($url, $status = 302) {
        http_response_code($status);
        header("Location: $url");
        exit;
    }
}
