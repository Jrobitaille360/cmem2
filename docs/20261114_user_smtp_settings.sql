-- =====================================================================
-- Module Assistant — SMTP par usager (tâches cmem #252, #253, #254)
--
-- Une ligne par usager : serveur SMTP personnel utilisé pour ses courriels
-- de rappel (send_reminders.php, repli sur le SMTP système en cas d'échec).
-- Mot de passe chiffré par sodium_crypto_secretbox (clé SMTP_ENCRYPTION_KEY
-- du .env) : base64(nonce || chiffré), jamais stocké ni retourné en clair.
--
-- FK : users.id est int(11) SIGNÉ — user_id reste int(11) signé (errno 150 sinon).
-- Idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `user_smtp_settings` (
  `user_id`      int(11) NOT NULL,
  `host`         varchar(255) NOT NULL,
  `port`         smallint(5) unsigned NOT NULL,
  `encryption`   enum('tls','ssl','none') NOT NULL DEFAULT 'tls' COMMENT 'tls = STARTTLS, ssl = TLS implicite',
  `username`     varchar(255) NOT NULL DEFAULT '',
  `password_enc` text NOT NULL COMMENT 'base64(nonce || secretbox) — clé SMTP_ENCRYPTION_KEY',
  `from_email`   varchar(255) NOT NULL,
  `from_name`    varchar(255) NOT NULL DEFAULT '',
  `verified_at`  datetime DEFAULT NULL COMMENT 'Dernier POST /assistant/smtp/test réussi sur ces paramètres',
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_smtp_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
