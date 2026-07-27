-- Migration pendante : notifications push web (VAPID) — Phase F.
-- Directive cmem_web 20260726_140426 — /push/*, cron d'envoi et purge des endpoints morts.
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Trois tables :
--   push_subscriptions    — un appareil abonné (endpoint + clés client), unique par (owner_id, endpoint)
--   notification_prefs    — préférences PAR COMPTE (pas par appareil), une ligne par kind
--   push_notification_log — trace d'idempotence : une échéance = une ligne, quel que soit le
--                           nombre d'appareils destinataires

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id       VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    owner_id     INT(11)      NOT NULL,
    endpoint     TEXT         NOT NULL,
    endpoint_hash CHAR(64)    NOT NULL COMMENT 'SHA-256 de endpoint — support de l''unicité (TEXT non indexable en entier)',
    p256dh       VARCHAR(255) NOT NULL,
    auth         VARCHAR(255) NOT NULL,
    device_label VARCHAR(190) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at DATETIME     NULL COMMENT 'Dernier envoi réussi vers cet endpoint',

    UNIQUE KEY uq_push_sub_owner_endpoint (owner_id, endpoint_hash),
    INDEX idx_push_sub_owner (app_id, owner_id),

    CONSTRAINT fk_push_sub_owner
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_prefs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id       VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    owner_id     INT(11)      NOT NULL,
    kind         ENUM('event','task_due','recurring','contact_followup') NOT NULL,
    lead_minutes INT UNSIGNED NOT NULL DEFAULT 15 COMMENT 'Valeurs permises : 5, 15, 60, 1440',
    quiet_from   TIME         NULL,
    quiet_to     TIME         NULL,
    enabled      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_notif_prefs_owner_kind (owner_id, app_id, kind),

    CONSTRAINT fk_notif_prefs_owner
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_notification_log (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id         VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    owner_id       INT(11)      NOT NULL,
    kind           ENUM('event','task_due','recurring','contact_followup') NOT NULL,
    entity_id      INT UNSIGNED NOT NULL,
    occurrence_key VARCHAR(64)  NOT NULL DEFAULT '-' COMMENT 'Occurrence visée (récurrence) ou ''-'' si sans objet',
    fire_at        DATETIME     NOT NULL COMMENT 'Échéance ciblée, en UTC',
    sent_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    devices        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre d''appareils visés',
    delivered      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre d''envois acceptés par le service de push',
    status         ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
    error          VARCHAR(255) NULL,

    UNIQUE KEY uq_push_log_echeance (owner_id, kind, entity_id, occurrence_key),
    INDEX idx_push_log_sent (app_id, owner_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
