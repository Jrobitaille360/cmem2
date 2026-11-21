-- ============================================================
-- 20261120 — Retrait des dépréciations des rappels unifiés (task #497, suite de #487 / v2.22.0)
-- ============================================================
--
-- Supprime les objets remplacés par les rappels unifiés (#487) et qui ne sont plus lus
-- par le code depuis #497 :
--   - table notification_prefs        (remplacée par notification_defaults)
--   - table push_notification_log     (remplacée par reminder_log)
--   - table email_notification_queue  (remplacée par le cron unifié send_reminders.php)
--   - colonne calendar_todos.remind_minutes_before (remplacée par calendar_todos.notifications)
--   - colonne users.notification_email (les rappels partent à users.email)
--
-- ORDRE IMPÉRATIF : déployer le code #497 AVANT cette migration (l'ancien code écrit
-- remind_minutes_before et lit notification_email/notification_prefs).
--
-- EXPORT PRÉALABLE (obligatoire, hors dépôt) :
--   mysqldump --single-transaction <base> notification_prefs push_notification_log email_notification_queue > 497_tables.sql
--   mysqldump --single-transaction --no-create-info --where="remind_minutes_before IS NOT NULL" <base> calendar_todos > 497_todos.sql
--   mysql <base> -e "SELECT id, notification_email FROM users WHERE notification_email IS NOT NULL" > 497_users.tsv
--
-- Vérifications faites le 2026-11-20 (lecture seule) — dev et prod :
--   calendar_todos avec remind_minutes_before seul (notifications NULL) : 0
--   notification_prefs activées sans notification_defaults correspondant : 0
--   users.notification_email différent de users.email                     : 0
--   email_notification_queue en prod : 0 ligne ; push_notification_log en prod : 154 lignes (historique)
--
-- Seule FK concernée : notification_prefs.owner_id → users (sortante, supprimée avec la table).
-- ============================================================

DROP TABLE IF EXISTS `notification_prefs`;
DROP TABLE IF EXISTS `push_notification_log`;
DROP TABLE IF EXISTS `email_notification_queue`;

ALTER TABLE `calendar_todos` DROP COLUMN IF EXISTS `remind_minutes_before`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `notification_email`;
