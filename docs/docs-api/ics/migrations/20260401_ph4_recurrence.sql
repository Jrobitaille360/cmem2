-- Phase 4 — Récurrence avancée & VALARM
-- Items 4.2 (RDATE), 4.3 (RELATED-TO), 4.5 (DURATION)
-- Note : VALARM (4.4) est dérivé de la colonne notifications existante — aucune colonne supplémentaire.
-- Note : EXDATE (4.1) est dérivé de event_occurrences.is_cancelled — aucune colonne supplémentaire.

ALTER TABLE calendar_events

    -- 4.2 — RDATE : dates additionnelles (ISO datetimes locales séparées par virgule)
    ADD COLUMN rdate TEXT NULL
        COMMENT 'RFC 5545 RDATE — dates additionnelles (ISO datetimes CSV, ex: 2026-04-15 14:00:00,2026-04-22 14:00:00)'
        AFTER organizer_name,

    -- 4.3 — RELATED-TO : UID de l'événement parent (lien hiérarchique RFC 5545 §3.8.4.5)
    ADD COLUMN related_to VARCHAR(255) NULL
        COMMENT 'RFC 5545 RELATED-TO — UID de l événement parent'
        AFTER rdate,

    -- 4.5 — DURATION : durée ISO 8601, exclusif avec DTEND (RFC 5545 §3.8.2.5)
    ADD COLUMN duration VARCHAR(20) NULL
        COMMENT 'RFC 5545 DURATION — format ISO 8601 (ex: PT1H30M) — si défini, DTEND n est pas exporté'
        AFTER related_to;
