-- Script de création de la base de données cmem2_db
CREATE DATABASE IF NOT EXISTS cmem2_db 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE cmem2_db;

-- Exécuter la procédure de reset
-- SOURCE create_proc_reset_auth_groups.sql;

SELECT 'Base de données cmem2_db créée avec succès' as message;