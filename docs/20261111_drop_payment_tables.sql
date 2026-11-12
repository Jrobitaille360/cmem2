-- ============================================================
-- Migration 20261111 : suppression des tables de paiement
-- Plan accès sur approbation #269, Phase 4, tâche #302
-- ============================================================
--
-- Stripe et Play Store sont retirés du code (tâche #300). Les tables
-- ci-dessous ne sont plus lues ni écrites par l'API.
--
-- À FAIRE AVANT D'APPLIQUER (données sandbox, mais on garde une trace) :
--
--   mysqldump --single-transaction --no-create-db \
--     -u <user> -p <base> \
--     stripe_subscriptions stripe_processed_events \
--     playstore_subscriptions subscriptions \
--     > payment_tables_export_YYYYMMDD.sql
--
-- Conserver l'export hors dépôt (PRIVATE-DATA).
--
-- Conservé volontairement :
--   - billing_archive : registres déjà anonymisés (obligation fiscale) ;
--     plus alimentée depuis #300.
--   - android_devices : lien d'appareil (module WebDevice), pas du paiement.
--   - users.cmem_plan_override : seul « ami » reste assignable, plan exposé
--     pour le contrat client (/auth/me, GET /modules).
--
-- Idempotent : IF EXISTS partout.
-- ============================================================

DROP TABLE IF EXISTS `stripe_processed_events`;
DROP TABLE IF EXISTS `stripe_subscriptions`;
DROP TABLE IF EXISTS `playstore_subscriptions`;
DROP TABLE IF EXISTS `subscriptions`;
