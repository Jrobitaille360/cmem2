-- Migration pendante : relance de contact (`contacts.date_relance`).
-- Plan docs/PLAN_relance-contact.md — volet A de la directive 20260726_161400.
-- Modèle A1 retenu par cmem_web : la relance est portée par la FICHE, pas par l'interaction.
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Colonnes nullables sans valeur par défaut : une fiche existante n'a aucune relance.
-- Une fiche est « à relancer » quand date_relance IS NOT NULL AND relance_faite_le IS NULL.
-- Une relance traitée est marquée faite (historique conservé), jamais effacée par l'API.
--
-- L'index sert au balayage quotidien du cron push (kind contact_followup, 2e source
-- après les échéances d'opportunité).

ALTER TABLE contacts
    ADD COLUMN date_relance DATE NULL DEFAULT NULL
        COMMENT 'Date de la prochaine relance à faire sur cette fiche'
        AFTER anniversaire,
    ADD COLUMN motif_relance VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Motif libre affiché sur la fiche ; jamais inclus dans le corps du push'
        AFTER date_relance,
    ADD COLUMN relance_faite_le DATETIME NULL DEFAULT NULL
        COMMENT 'Horodatage du traitement de la relance ; NULL = relance encore en cours'
        AFTER motif_relance,
    ADD INDEX idx_contacts_relance (user_id, date_relance, relance_faite_le);
