-- Migration : table subscriptions
-- Date : 2026-04-09
-- Description : Gestion des abonnements Premium par utilisateur et par application.
--               Le statut Premium est stocké par app_id.
--               La table users N'EST PAS modifiée.

CREATE TABLE IF NOT EXISTS subscriptions (
    id             INT(11)          AUTO_INCREMENT PRIMARY KEY,
    user_id        INT(11)          NOT NULL,
    app_id         VARCHAR(50)      NOT NULL                    COMMENT 'ex: puzzle, pomo, quiz',
    provider       ENUM('stripe','google_play','apple','microsoft') NOT NULL,
    product_id     VARCHAR(100)     NOT NULL,
    purchase_token VARCHAR(500)     NULL,
    stripe_sub_id  VARCHAR(100)     NULL,
    status         ENUM('active','cancelled','expired','past_due') NOT NULL DEFAULT 'active',
    plan           ENUM('monthly','yearly') NOT NULL,
    started_at     DATETIME         NOT NULL,
    expires_at     DATETIME         NOT NULL,
    cancelled_at   DATETIME         NULL,
    created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_app_provider (user_id, app_id, provider),
    KEY idx_expires_status (expires_at, status),
    KEY idx_user_id (user_id),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
