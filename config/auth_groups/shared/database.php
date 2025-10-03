<?php

use Memories\Utils\Response;
use Memories\Services\LogService;

/**
 * Configuration de la base de données partagée
 * Module: shared - connexion commune à tous les modules
 */

class Database {
    private static ?Database $instance = null;
    private ?PDO $connection = null;
    
    // Configuration de la base de données
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    private string $charset = 'utf8mb4';
    
    private function __construct() {
        // Configuration depuis les variables d'environnement
        $this->host = $_ENV['DB_HOST'] ?? 'localhost';
        $this->db_name = $_ENV['DB_NAME'] ?? 'cmem1_db';
        $this->username = $_ENV['DB_USER'] ?? 'root';
        $this->password = $_ENV['DB_PASS'] ?? '';
        
        $this->connect();
    }
    
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect(): void {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => filter_var($_ENV['DB_PERSISTENT'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE {$this->charset}_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            
            // Configuration additionnelle pour MySQL
            $this->connection->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $this->connection->exec("SET time_zone = '" . ($_ENV['DB_TIMEZONE'] ?? '+00:00') . "'");
            
        } catch (PDOException $e) {
            $error_message = "Erreur de connexion à la base de données: " . $e->getMessage();
            
            // Log l'erreur si le service de log est disponible
            if (class_exists('\Memories\Services\LogService')) {
                LogService::error('database_connection', ['error' => $error_message]);
            }
            
            // Réponse d'erreur standardisée
            if (APP_DEBUG) {
                throw new Exception($error_message);
            } else {
                throw new Exception("Erreur de connexion à la base de données");
            }
        }
    }
    
    public function getConnection(): PDO {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }
    
    public function ping(): bool {
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function reconnect(): void {
        $this->connection = null;
        $this->connect();
    }
    
    public function beginTransaction(): bool {
        return $this->connection->beginTransaction();
    }
    
    public function commit(): bool {
        return $this->connection->commit();
    }
    
    public function rollback(): bool {
        return $this->connection->rollback();
    }
    
    public function inTransaction(): bool {
        return $this->connection->inTransaction();
    }
    
    public function lastInsertId(): string {
        return $this->connection->lastInsertId();
    }
    
    public function quote(string $string): string {
        return $this->connection->quote($string);
    }
    
    public function prepare(string $statement): PDOStatement {
        return $this->connection->prepare($statement);
    }
    
    public function query(string $statement): PDOStatement {
        return $this->connection->query($statement);
    }
    
    public function exec(string $statement): int {
        return $this->connection->exec($statement);
    }
    
    public function __destruct() {
        $this->connection = null;
    }
}