-- =====================================================================
-- Migration — Suivi du temps par tâche : sessions start/stop (D3)
-- Directive : 20260814_143000_cmem_web_vers_cmem2_API__time-tracking-sessions.md
-- Date      : 2026-08-14
--
-- Une session de temps est rattachée à une tâche (calendar_todos, VTODO) et à
-- l'usager qui l'a démarrée. `note` suit la convention e2e des VTODO/journaux :
-- enc_alg/enc_iv nullable, NULL = note en clair, opaque quand renseigné.
--
-- Contrainte « un seul minuteur actif par usager » (globale, pas par tâche) :
-- colonne générée active_user_id = user_id quand ended_at IS NULL, sinon NULL,
-- avec un index UNIQUE dessus. MySQL autorise plusieurs NULL dans un index
-- UNIQUE mais un seul user_id non-NULL — la garde-fou est donc au niveau DB,
-- pas seulement applicatif (résiste aux requêtes concurrentes).
--
-- Pas de gating tenant_modules (cf. réponse dans le fichier de directive) :
-- calendar_todos lui-même n'est gaté par aucune module_key, les sessions
-- suivent le même régime.
-- =====================================================================

CREATE TABLE time_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    todo_id        INT UNSIGNED NOT NULL,
    user_id        INT NOT NULL,
    started_at     DATETIME NOT NULL,
    ended_at       DATETIME DEFAULT NULL,
    note           VARCHAR(2000) DEFAULT NULL
                   COMMENT 'Clair, ou base64 opaque si enc_alg renseigné',
    enc_alg        VARCHAR(32) DEFAULT NULL
                   COMMENT 'Algorithme de chiffrement client, ex. AES-GCM-256. NULL = note en clair',
    enc_iv         VARCHAR(32) DEFAULT NULL
                   COMMENT 'Vecteur d''initialisation base64. NULL = note en clair',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_user_id INT GENERATED ALWAYS AS (IF(ended_at IS NULL, user_id, NULL)) STORED,
    CONSTRAINT fk_time_sessions_todo FOREIGN KEY (todo_id) REFERENCES calendar_todos(id) ON DELETE CASCADE,
    CONSTRAINT fk_time_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_time_sessions_active_user (active_user_id),
    INDEX idx_time_sessions_todo (todo_id),
    INDEX idx_time_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
