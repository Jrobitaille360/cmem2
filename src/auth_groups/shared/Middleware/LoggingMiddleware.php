<?php

namespace Memories\Middleware;

use Memories\Services\LogService;

/**
 * Middleware pour le logging automatique des requêtes API
 */
class LoggingMiddleware
{
    private static ?float $startTime = null;
    
    /**
     * Log l'entrée d'une requête API
     */
    public static function logEntry(): void
    {
        self::$startTime = microtime(true);
        
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Nettoyer l'URI pour ne garder que le path
        $endpoint = parse_url($uri, PHP_URL_PATH) ?? $uri;
        
        // Collecter les données de la requête (sans les mots de passe)
        $data = [];
        
        // Headers importants
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('HTTP_', '', $key);
                $headerName = str_replace('_', '-', strtolower($headerName));
                $headers[$headerName] = $value;
            }
        }
        
        // Filtrer les headers sensibles
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key'];
        foreach ($sensitiveHeaders as $sensitive) {
            if (isset($headers[$sensitive])) {
                $headers[$sensitive] = '[REDACTED]';
            }
        }
        
        $data['headers'] = $headers;
        
        // Query parameters
        if (!empty($_GET)) {
            $data['query'] = $_GET;
        }
        
        // Body data (pour POST, PUT, PATCH)
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $input = file_get_contents('php://input');
                if ($input) {
                    $bodyData = json_decode($input, true);
                    if ($bodyData) {
                        // Masquer les champs sensibles
                        $bodyData = self::maskSensitiveData($bodyData);
                        $data['body'] = $bodyData;
                    }
                }
            } elseif (strpos($contentType, 'multipart/form-data') !== false || 
                      strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                if (!empty($_POST)) {
                    $data['form'] = self::maskSensitiveData($_POST);
                }
                if (!empty($_FILES)) {
                    $data['files'] = array_map(function($file) {
                        return [
                            'name' => $file['name'] ?? '',
                            'type' => $file['type'] ?? '',
                            'size' => $file['size'] ?? 0
                        ];
                    }, $_FILES);
                }
            }
        }
        
        LogService::logEntry($method, $endpoint, $data);
    }
    
    /**
     * Log la sortie d'une requête API
     */
    public static function logExit(int $statusCode = 200): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $endpoint = parse_url($uri, PHP_URL_PATH) ?? $uri;
        
        $executionTime = null;
        if (self::$startTime !== null) {
            $executionTime = microtime(true) - self::$startTime;
        }
        
        LogService::logExit($method, $endpoint, $statusCode, $executionTime);
    }
    
    /**
     * Masque les données sensibles dans un tableau
     */
    private static function maskSensitiveData(array $data): array
    {
        $sensitiveFields = [
            'password',
            'password_confirmation', 
            'current_password',
            'new_password',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'private_key',
            'credit_card',
            'card_number',
            'cvv',
            'ssn',
            'social_security'
        ];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Vérifier si la clé contient un champ sensible
            foreach ($sensitiveFields as $sensitiveField) {
                if (strpos($lowerKey, $sensitiveField) !== false) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
            
            // Si c'est un tableau, masquer récursivement
            if (is_array($value)) {
                $data[$key] = self::maskSensitiveData($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Middleware pour capturer et logger les erreurs
     */
    public static function handleError(int $statusCode, string $message = '', array $context = []): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $endpoint = parse_url($uri, PHP_URL_PATH) ?? $uri;
        
        $errorContext = array_merge($context, [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        if ($statusCode >= 500) {
            LogService::error("API Error {$statusCode}: {$message}", $errorContext);
        } elseif ($statusCode >= 400) {
            LogService::warning("API Warning {$statusCode}: {$message}", $errorContext);
        }
        
        // Logger aussi la sortie
        self::logExit($statusCode);
    }
    
    /**
     * Log une action utilisateur spécifique
     */
    public static function logUserAction(string $action, ?int $userId = null, array $data = []): void
    {
        $context = [
            'user_id' => $userId,
            'action' => $action,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id() ?: null,
            'data' => $data
        ];
        
        LogService::info("User Action: {$action}", $context);
    }
    
    /**
     * Log une tentative d'authentification
     */
    public static function logAuthAttempt(string $email, bool $success, string $method = 'password'): void
    {
        $context = [
            'email' => $email,
            'success' => $success,
            'method' => $method,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $level = $success ? 'info' : 'warning';
        $message = $success ? "Authentication successful" : "Authentication failed";
        
        if ($success) {
            LogService::info($message, $context);
        } else {
            LogService::warning($message, $context);
        }
    }
    
    /**
     * Log les opérations sur la base de données
     */
    public static function logDatabaseQuery(string $query, array $params = [], float $executionTime = null): void
    {
        if (!LogService::getInstance()) {
            return;
        }
        
        $context = [
            'query' => $query,
            'params' => $params,
            'execution_time_ms' => $executionTime ? round($executionTime * 1000, 2) : null
        ];
        
        LogService::debug("Database Query", $context);
    }
}
