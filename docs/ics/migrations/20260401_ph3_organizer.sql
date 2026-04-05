-- Phase 3 — ATTENDEE & ORGANIZER complets
-- Item 3.2 : colonnes ORGANIZER optionnelles sur calendar_events
-- Permet de surcharger l'organisateur déduit du user_id de l'événement.

ALTER TABLE calendar_events
    -- Adresse email de l'organisateur (override de l'email du user_id propriétaire)
    ADD COLUMN organizer_email VARCHAR(255) NULL
        COMMENT 'RFC 5545 ORGANIZER — email (override du user_id propriétaire)'
        AFTER attachments,

    -- Nom affiché de l'organisateur (CN)
    ADD COLUMN organizer_name VARCHAR(255) NULL
        COMMENT 'RFC 5545 ORGANIZER CN — nom affiché'
        AFTER organizer_email;
