-- ============================================================
-- 20260813_group_billing.sql
-- Abonnement Stripe porté par un groupe — directive cmem_web 20260813_143000 (plan-equipe).
--
-- stripe_subscriptions n'avait aucune notion de groupe (contrairement à tenant_modules,
-- déjà préparé le 2026-07-27). Même principe XOR que tenant_modules :
--   exactement un des deux porteurs par ligne — user_id (perso) OU group_id (groupe).
--
-- Rétrocompatible : toutes les lignes existantes ont déjà user_id renseigné, group_id NULL
-- pour elles ne change rien à leur lecture/écriture. MySQL traite les NULL comme distincts
-- dans une clé unique, donc uq_user_app et uq_group_app coexistent sans collision (même
-- comportement que uq_owner_module / uq_group_module sur tenant_modules).
-- ============================================================

ALTER TABLE `stripe_subscriptions`
    MODIFY `user_id` INT(11) NULL,
    ADD COLUMN `group_id` INT(11) NULL AFTER `user_id`,
    ADD CONSTRAINT `fk_ss_group`
        FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    -- Exactement un des deux porteurs : usager OU groupe.
    ADD CONSTRAINT `chk_ss_user_xor_group`
        CHECK ((`user_id` IS NULL) <> (`group_id` IS NULL)),
    DROP INDEX `uq_user_app`,
    ADD UNIQUE KEY `uq_user_app` (`user_id`, `app_id`),
    ADD UNIQUE KEY `uq_group_app` (`group_id`, `app_id`);
