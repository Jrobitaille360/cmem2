<?php

namespace AuthGroups\Services;

/**
 * Service de logging avancé avec rotation automatique et archivage
 * 
 * Fonctionnalités :
 * - Logs par niveau (debug, info, warning, error, critical)
 * - Rotation quotidienne des fichiers de log
 * - Archivage automatique en ZIP après X jours
 * - Suppression automatique des anciens archives
 * - Log des points d'entrée de l'API
 * - Configuration via .env
 * - Accessible globalement comme singleton
 */
class LogService
{
    private static ?LogService $instance = null;
    
    // Niveaux de log avec priorités
    const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    ];
    
    // Couleurs pour les logs en console (si nécessaire)
    const COLORS = [
        'debug' => "\033[36m",    // Cyan
        'info' => "\033[32m",     // Vert
        'warning' => "\033[33m",  // Jaune
        'error' => "\033[31m",    // Rouge
        'critical' => "\033[35m", // Magenta
        'reset' => "\033[0m"      // Reset
    ];
    
    private bool $enabled;
    private string $logLevel;
    private string $logDir;
    private int $maxFileSize;
    private int $archiveAfterDays;
    private int $deleteAfterWeeks;
    private string $timezone;
    private ?\DateTimeZone $timezoneObj = null;
    
    /**
     * Constructeur privé pour pattern Singleton
     */
    private function __construct()
    {
        $this->enabled = LOG_ENABLED ?? true;
        $this->logLevel = LOG_LEVEL ?? 'debug';
        $this->logDir = LOG_DIR ?? __DIR__ . '/../../logs/';
        $this->maxFileSize = LOG_MAX_FILE_SIZE ?? 10485760; // 10MB
        $this->archiveAfterDays = LOG_ARCHIVE_AFTER_DAYS ?? 7;
        $this->deleteAfterWeeks = LOG_DELETE_AFTER_WEEKS ?? 12;
        $this->timezone = LOG_TIMEZONE ?? 'America/Toronto';
        
        try {
            $this->timezoneObj = new \DateTimeZone($this->timezone);
        } catch (\Exception $e) {
            $this->timezoneObj = new \DateTimeZone('UTC');
        }
        
        // Créer le dossier de logs s'il n'existe pas
        if ($this->enabled && !is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        
        // Effectuer la maintenance des logs au démarrage
        if ($this->enabled) {
            $this->performMaintenance();
        }
    }
    
    /**
     * Obtenir l'instance singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Log un message de debug
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->log('debug', $message, $context);
    }
    
    /**
     * Log un message d'information
     */
    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->log('info', $message, $context);
    }
    
    /**
     * Log un message d'avertissement
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->log('warning', $message, $context);
    }
    
    /**
     * Log un message d'erreur
     */
    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->log('error', $message, $context);
    }
    
    /**
     * Log un message critique
     */
    public static function critical(string $message, array $context = []): void
    {
        self::getInstance()->log('critical', $message, $context);
    }
    
    /**
     * Log l'entrée dans un endpoint de l'API
     */
    public static function logEntry(string $method, string $endpoint, array $data = []): void
    {
        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'data' => $data
        ];
        
        self::getInstance()->log('info', "API Entry: {$method} {$endpoint}", $context);
    }
    
    
    /**
     * Log la sortie d'un endpoint de l'API
     */
    public static function logExit(string $method, string $endpoint, int $statusCode, ?float $executionTime = null): void
    {
        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'execution_time_ms' => $executionTime ? round($executionTime * 1000, 2) : null,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');
        self::getInstance()->log($level, "API Exit: {$method} {$endpoint} - {$statusCode}", $context);
    }
    
    /**
     * Log une exception
     */
    public static function logException(\Throwable $exception, array $context = []): void
    {
        $context = array_merge($context, [
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
        
        self::getInstance()->log('error', "Exception: " . $exception->getMessage(), $context);
    }
    
    /**
     * Méthode principale de logging
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        
        // Vérifier si le niveau de log est autorisé
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $timestamp = new \DateTime('now', $this->timezoneObj);
        $logFile = $this->getLogFilePath($timestamp);
        
        // Construire l'entrée de log
        $logEntry = $this->buildLogEntry($timestamp, $level, $message, $context);
        
        // Écrire dans le fichier
        $this->writeToFile($logFile, $logEntry);
        
        // Vérifier si le fichier doit être rotaté
        $this->checkRotation($logFile);
    }
    
    /**
     * Vérifie si un niveau de log doit être enregistré
     */
    private function shouldLog(string $level): bool
    {
        $currentLevelPriority = self::LEVELS[$this->logLevel] ?? 0;
        $messageLevelPriority = self::LEVELS[$level] ?? 0;
        
        return $messageLevelPriority >= $currentLevelPriority;
    }
    
    /**
     * Construit le chemin du fichier de log pour une date donnée
     */
    private function getLogFilePath(\DateTime $date): string
    {
        return $this->logDir . 'app-' . $date->format('Y-m-d') . '.log';
    }
    
    /**
     * Construit l'entrée de log formatée
     */
    private function buildLogEntry(\DateTime $timestamp, string $level, string $message, array $context): string
    {
        $levelUpper = strtoupper($level);
        $time = $timestamp->format('Y-m-d H:i:s');
        
        // Informations sur la requête actuelle
        $requestInfo = [
            'pid' => getmypid(),
            'memory' => $this->formatBytes(memory_get_usage(true))
        ];
        
        // Construire l'entrée de base
        $entry = "[{$time}] {$levelUpper}: {$message}";
        
        // Ajouter le contexte s'il y en a un
        if (!empty($context)) {
            $entry .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        // Ajouter les informations de la requête
        $entry .= " | Request: " . json_encode($requestInfo, JSON_UNESCAPED_UNICODE);
        
        return $entry . PHP_EOL;
    }
    
    /**
     * Écrit l'entrée dans le fichier de log
     */
    private function writeToFile(string $filePath, string $content): void
    {
        // Utiliser un verrou pour éviter les corruptions en cas d'accès concurrent
        $handle = fopen($filePath, 'a');
        if ($handle) {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $content);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }
    
    /**
     * Vérifie si le fichier de log doit être rotaté
     */
    private function checkRotation(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }
        
        // Vérifier la taille du fichier
        if (filesize($filePath) > $this->maxFileSize) {
            $this->rotateFile($filePath);
        }
    }
    
    /**
     * Effectue la rotation d'un fichier de log
     */
    private function rotateFile(string $filePath): void
    {
        $pathInfo = pathinfo($filePath);
        $timestamp = date('H-i-s');
        $rotatedPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . $timestamp . '.' . $pathInfo['extension'];
        
        if (rename($filePath, $rotatedPath)) {
            $this->log('info', "Log file rotated: {$rotatedPath}");
        }
    }
    
    /**
     * Effectue la maintenance des logs (archivage et nettoyage)
     */
    private function performMaintenance(): void
    {
        try {
            $this->archiveOldLogs();
            $this->cleanupOldArchives();
        } catch (\Exception $e) {
            // En cas d'erreur dans la maintenance, on continue sans bloquer l'application
            error_log("Log maintenance error: " . $e->getMessage());
        }
    }
    
    /**
     * Archive les anciens fichiers de log en ZIP
     */
    private function archiveOldLogs(): void
    {
        if (!class_exists('ZipArchive')) {
            return; // ZipArchive n'est pas disponible
        }
        
        $cutoffDate = new \DateTime("-{$this->archiveAfterDays} days", $this->timezoneObj);
        $pattern = $this->logDir . 'app-*.log';
        
        foreach (glob($pattern) as $logFile) {
            $fileName = basename($logFile);
            
            // Extraire la date du nom du fichier
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $fileName, $matches)) {
                $fileDate = \DateTime::createFromFormat('Y-m-d', $matches[1], $this->timezoneObj);
                
                if ($fileDate && $fileDate < $cutoffDate) {
                    $this->archiveLogFile($logFile, $fileDate);
                }
            }
        }
    }
    
    /**
     * Archive un fichier de log spécifique
     */
    private function archiveLogFile(string $logFile, \DateTime $fileDate): void
    {
        $weekNumber = $fileDate->format('W');
        $year = $fileDate->format('Y');
        $archiveName = "logs-{$year}-week{$weekNumber}.zip";
        $archivePath = $this->logDir . $archiveName;
        
        $zip = new \ZipArchive();
        $result = $zip->open($archivePath, \ZipArchive::CREATE);
        
        if ($result === TRUE) {
            $zip->addFile($logFile, basename($logFile));
            $zip->close();
            
            // Supprimer le fichier original après archivage
            unlink($logFile);
            
            $this->log('info', "Log file archived: {$logFile} -> {$archiveName}");
        }
    }
    
    /**
     * Nettoie les anciennes archives
     */
    private function cleanupOldArchives(): void
    {
        $cutoffDate = new \DateTime("-{$this->deleteAfterWeeks} weeks", $this->timezoneObj);
        $pattern = $this->logDir . 'logs-*-week*.zip';
        
        foreach (glob($pattern) as $archiveFile) {
            $fileTime = filemtime($archiveFile);
            $fileDate = new \DateTime("@{$fileTime}");
            
            if ($fileDate < $cutoffDate) {
                unlink($archiveFile);
                $this->log('info', "Old archive deleted: " . basename($archiveFile));
            }
        }
    }
    
    /**
     * Formate les octets en format lisible
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Obtient des statistiques sur les logs
     */
    public function getStats(): array
    {
        $stats = [
            'enabled' => $this->enabled,
            'log_level' => $this->logLevel,
            'log_dir' => $this->logDir,
            'current_logs' => [],
            'archives' => [],
            'total_size' => 0
        ];
        
        if (!$this->enabled || !is_dir($this->logDir)) {
            return $stats;
        }
        
        // Analyser les fichiers de log actuels
        foreach (glob($this->logDir . 'app-*.log') as $logFile) {
            $size = filesize($logFile);
            $stats['current_logs'][] = [
                'file' => basename($logFile),
                'size' => $this->formatBytes($size),
                'modified' => date('Y-m-d H:i:s', filemtime($logFile))
            ];
            $stats['total_size'] += $size;
        }
        
        // Analyser les archives
        foreach (glob($this->logDir . 'logs-*-week*.zip') as $archiveFile) {
            $size = filesize($archiveFile);
            $stats['archives'][] = [
                'file' => basename($archiveFile),
                'size' => $this->formatBytes($size),
                'created' => date('Y-m-d H:i:s', filemtime($archiveFile))
            ];
            $stats['total_size'] += $size;
        }
        
        $stats['total_size_formatted'] = $this->formatBytes($stats['total_size']);
        
        return $stats;
    }
    
    /**
     * Force l'archivage immédiat des logs
     */
    public function forceArchive(): void
    {
        $this->archiveOldLogs();
    }
    
    /**
     * Force le nettoyage des anciennes archives
     */
    public function forceCleanup(): void
    {
        $this->cleanupOldArchives();
    }
    
    /**
     * Empêche le clonage de l'instance
     */
    private function __clone() {}
    
    /**
     * Empêche la désérialisation de l'instance
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
