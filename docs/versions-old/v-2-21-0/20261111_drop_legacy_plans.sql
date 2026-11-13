-- ============================================================
-- Migration 20261111 : suppression du système de plans legacy
-- Plan accès sur approbation #269, Phase 6, tâche #480
-- ============================================================
--
-- Le catalogue /plans (Gratuit / Bronze / Argent / Platine, avec prix),
-- l'assignation d'un plan « free » de 7 jours à l'inscription et les
-- invitations à choisir un plan payant (plan_invitations, courriel envoyé à
-- la vérification du courriel) sont retirés du code. Rien ne lit plus ces
-- tables ni ces colonnes (users.plan_auto_renew : jamais lue).
--
-- À FAIRE AVANT D'APPLIQUER : exporter plans, plan_invitations et
-- users(id, plan_id, plan_expires_at, plan_auto_renew) hors dépôt (PRIVATE-DATA).
--
-- Conservé : users.cmem_plan_override (« ami », exposé dans data.user.plan).
--
-- Syntaxe MariaDB (IF EXISTS sur FK / index / colonne) — idempotent.
-- ============================================================

ALTER TABLE `users` DROP FOREIGN KEY IF EXISTS `fk_users_plan`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_users_plan_expires`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `plan_id`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `plan_expires_at`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `plan_auto_renew`;

DROP TABLE IF EXISTS `plan_invitations`;
DROP TABLE IF EXISTS `plans`;
