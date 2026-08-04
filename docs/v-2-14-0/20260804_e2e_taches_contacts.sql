-- =====================================================================
-- Migration — Tâches (VTODO) et contacts chiffrés de bout en bout (E2E)
-- Directive : 20260804_090000_cmem_web_vers_cmem2_API__e2e-taches-contacts.md
-- Date      : 2026-08-04
--
-- Le chiffrement est fait entièrement côté client (WebCrypto : PBKDF2-SHA256
-- pour la dérivation, AES-GCM 256 pour le contenu). Le serveur ne stocke que
-- des octets opaques : aucune clé, aucune passphrase ne transite vers l'API.
--
-- Impact sur les données existantes : AUCUN.
--   - enc_alg / enc_iv / enc_payload sont ajoutées à NULL → toutes les lignes
--     actuelles restent en clair et se comportent à l'identique.
--   - calendar_todos.title VARCHAR(255) → VARCHAR(2000) : élargissement.
--   - calendar_todos.description TEXT → MEDIUMTEXT : élargissement.
--
-- Pourquoi ces élargissements : le base64 gonfle le contenu d'environ 4/3.
-- La directive exige title ≥ 2 000 caractères et description ≥ 200 000.
-- TEXT plafonne à 65 535 octets, donc insuffisant pour 200 000 caractères.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Tâches (VTODO) — mêmes colonnes que les journaux
--    Champs chiffrés quand enc_alg est renseigné : title et description.
--    Restent en clair : due, dtstart, status, priority, percent_complete,
--    categories, recurrence_rule, calendar_id, uid.
-- ---------------------------------------------------------------------
ALTER TABLE calendar_todos
    MODIFY COLUMN title       VARCHAR(2000) NOT NULL
                  COMMENT 'Clair, ou base64 opaque si enc_alg renseigné',
    MODIFY COLUMN description MEDIUMTEXT    DEFAULT NULL
                  COMMENT 'Clair, ou base64 opaque si enc_alg renseigné',
    ADD COLUMN    enc_alg     VARCHAR(32)   DEFAULT NULL
                  COMMENT 'Algorithme de chiffrement client, ex. AES-GCM-256. NULL = tâche en clair'
                  AFTER description,
    ADD COLUMN    enc_iv      VARCHAR(32)   DEFAULT NULL
                  COMMENT 'Vecteur d''initialisation base64 (12 octets → 16 caractères). NULL = tâche en clair'
                  AFTER enc_alg;

-- ---------------------------------------------------------------------
-- 2. Contacts — une charge utile chiffrée, le nom en clair
--    enc_payload contient le JSON chiffré de : organisation, fonction,
--    courriels, telephones, adresses, sites, reseaux, notes, anniversaire,
--    motif_relance. Décision « C2 » : prenom, nom, categories, favori,
--    photo_file_id, partage_scope, date_relance, relance_faite_le et les
--    horodatages restent en clair (tri, pagination et rappel push serveur).
-- ---------------------------------------------------------------------
ALTER TABLE contacts
    ADD COLUMN enc_alg     VARCHAR(32) DEFAULT NULL
               COMMENT 'Algorithme de chiffrement client, ex. AES-GCM-256. NULL = contact en clair'
               AFTER partage_scope,
    ADD COLUMN enc_iv      VARCHAR(32) DEFAULT NULL
               COMMENT 'Vecteur d''initialisation base64. NULL = contact en clair'
               AFTER enc_alg,
    ADD COLUMN enc_payload MEDIUMTEXT  DEFAULT NULL
               COMMENT 'Base64 opaque : JSON chiffré des champs sensibles. Jamais lu ni normalisé par le serveur'
               AFTER enc_iv;
