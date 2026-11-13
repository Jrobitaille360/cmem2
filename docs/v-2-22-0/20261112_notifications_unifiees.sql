-- ============================================================
-- Migration 20261112 : rappels et notifications unifiés — SCHÉMA
-- Directive cmem_web #487 (contrat #236), sous-tâche #488
-- ============================================================
--
-- Structure seulement : aucune donnée n'est déplacée ici. La reprise des
-- données (§7 du contrat : notification_prefs → notification_defaults,
-- kinds des appareils, normalisation des événements, remind_minutes_before)
-- est un script séparé (#493), exécuté après déploiement du code.
--
-- Contenu :
--   1. notification_defaults : rappels par défaut par type (kind), par compte
--      et par app. Remplace notification_prefs (gardée une version pour la
--      compatibilité de /push/preferences, puis retirée).
--   2. push_subscriptions : filtre par appareil (kinds + plage silencieuse
--      propre). last_seen_at existant = last_success_at du contrat.
--   3. Colonne notifications (liste par élément) sur calendar_todos (tâches
--      ET tâches de projet, project_id), contacts et opportunite.
--      calendar_events.notifications existe déjà.
--      NULL = défaut du type ; [] = aucun rappel ; [...] = liste explicite.
--   4. reminder_log : journal d'envoi unifié (courriel + push), une ligne par
--      (élément, occurrence, canal, délai, appareil). Remplace
--      push_notification_log et email_notification_queue (gardées en lecture
--      une version).
--
-- Syntaxe MariaDB (IF NOT EXISTS sur tables / colonnes) — idempotent.
-- FK vers users(id) : INT(11) signé, comme users.id (sinon errno 150).
-- ============================================================

-- ------------------------------------------------------------
-- 1. Défauts par type
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_defaults (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id             VARCHAR(64)  NOT NULL,
    owner_id           INT(11)      NOT NULL,
    kind               ENUM('event','recurring','task_due','contact_followup') NOT NULL,
    notifications      JSON         NOT NULL
                       COMMENT 'Liste [{type: EMAIL|DISPLAY, minutes_before}] ; délais dans 0,5,10,15,30,60,120,1440,2880',
    quiet_from         TIME         NULL COMMENT 'Plage silencieuse du type, fuseau de l''usager — vaut pour les deux canaux',
    quiet_to           TIME         NULL,
    show_entity_detail TINYINT(1)   NOT NULL DEFAULT 0
                       COMMENT 'Opt-in : titre réel (push et courriel) ; fiche chiffrée e2e toujours générique',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_defaults_owner_kind (owner_id, app_id, kind),
    CONSTRAINT fk_notif_defaults_owner
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Appareils : filtre par type + plage silencieuse propre
-- ------------------------------------------------------------
ALTER TABLE push_subscriptions
    ADD COLUMN IF NOT EXISTS kinds JSON NULL
        COMMENT 'Types acceptés par l''appareil ; NULL = les 4 types'
        AFTER device_label,
    ADD COLUMN IF NOT EXISTS quiet_from TIME NULL
        COMMENT 'Plage silencieuse propre à l''appareil, en plus de celle du type'
        AFTER kinds,
    ADD COLUMN IF NOT EXISTS quiet_to TIME NULL
        AFTER quiet_from;

-- ------------------------------------------------------------
-- 3. Liste de rappels par élément
-- ------------------------------------------------------------
ALTER TABLE calendar_todos
    ADD COLUMN IF NOT EXISTS notifications JSON NULL
        COMMENT 'Rappels de la tâche ; NULL = défaut du type task_due, [] = aucun'
        AFTER remind_minutes_before;

ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS notifications JSON NULL
        COMMENT 'Rappels de la relance (date_relance) ; NULL = défaut contact_followup, [] = aucun'
        AFTER relance_faite_le;

ALTER TABLE opportunite
    ADD COLUMN IF NOT EXISTS notifications JSON NULL
        COMMENT 'Rappels de la clôture prévue ; NULL = défaut contact_followup, [] = aucun'
        AFTER date_cloture_prevue;

-- ------------------------------------------------------------
-- 4. Journal d'envoi unifié (idempotence)
-- ------------------------------------------------------------
-- device_id = 0 pour le courriel (NULL du contrat) : une clé unique MariaDB
-- ne considère pas deux NULL comme égaux, ce qui casserait l'idempotence.
CREATE TABLE IF NOT EXISTS reminder_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id         VARCHAR(64)  NOT NULL,
    owner_id       INT(11)      NOT NULL,
    kind           ENUM('event','recurring','task_due','contact_followup') NOT NULL,
    entity_type    ENUM('event','todo','project_task','contact','opportunite') NOT NULL,
    entity_id      INT UNSIGNED NOT NULL,
    occurrence_key VARCHAR(64)  NOT NULL DEFAULT '-'
                   COMMENT 'Occurrence visée (date de récurrence, date de relance) ou ''-''',
    channel        ENUM('EMAIL','DISPLAY') NOT NULL,
    minutes_before INT UNSIGNED NOT NULL,
    device_id      INT UNSIGNED NOT NULL DEFAULT 0
                   COMMENT 'push_subscriptions.id ; 0 = courriel',
    due_at         DATETIME     NOT NULL COMMENT 'Échéance de l''élément, UTC',
    fire_at        DATETIME     NOT NULL COMMENT 'Moment prévu du rappel (due_at - minutes_before), UTC',
    sent_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status         ENUM('claimed','sent','failed','skipped') NOT NULL DEFAULT 'claimed',
    error          VARCHAR(255) NULL,
    UNIQUE KEY uq_reminder_log (owner_id, entity_type, entity_id, occurrence_key,
                                channel, minutes_before, device_id),
    INDEX idx_reminder_log_sent (app_id, owner_id, sent_at),
    INDEX idx_reminder_log_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
