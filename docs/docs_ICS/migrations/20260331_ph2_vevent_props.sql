-- Phase 2 — Propriétés VEVENT manquantes
-- Items : 2.1 CATEGORIES, 2.2 PRIORITY, 2.3 CLASS, 2.4 TRANSP,
--          2.5 URL/GEO, 2.6 ATTACH
-- Cible : table calendar_events

ALTER TABLE calendar_events
    -- 2.2 PRIORITY : RFC 5545 §3.8.1.9 — 0=non défini, 1=haute, 5=normale, 9=basse
    ADD COLUMN priority TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'RFC 5545 PRIORITY: 0=non défini, 1=haute, 5=normale, 9=basse'
        AFTER uid,

    -- 2.3 CLASS : confidentialité de l'événement
    ADD COLUMN class ENUM('PUBLIC','PRIVATE','CONFIDENTIAL') NOT NULL DEFAULT 'PUBLIC'
        COMMENT 'RFC 5545 CLASS'
        AFTER priority,

    -- 2.4 TRANSP : indique si l'événement bloque le temps libre
    ADD COLUMN transp ENUM('OPAQUE','TRANSPARENT') NOT NULL DEFAULT 'OPAQUE'
        COMMENT 'RFC 5545 TRANSP — OPAQUE bloque le temps libre'
        AFTER class,

    -- 2.1 CATEGORIES : tableau JSON de chaînes (ex. ["Travail","Réunion"])
    ADD COLUMN categories JSON NULL
        COMMENT 'RFC 5545 CATEGORIES — tableau de chaînes'
        AFTER transp,

    -- 2.5 GEO latitude
    ADD COLUMN geo_lat DECIMAL(10,7) NULL
        COMMENT 'RFC 5545 GEO latitude'
        AFTER categories,

    -- 2.5 GEO longitude
    ADD COLUMN geo_lng DECIMAL(10,7) NULL
        COMMENT 'RFC 5545 GEO longitude'
        AFTER geo_lat,

    -- 2.6 ATTACH : [{url, encoding, mime_type}] ou [{data_base64, mime_type}]
    ADD COLUMN attachments JSON NULL
        COMMENT 'RFC 5545 ATTACH — [{url, mime_type}] ou [{data_base64, mime_type}]'
        AFTER geo_lng;
