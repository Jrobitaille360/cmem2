-- =====================================================================
-- Migration — Journaux chiffrés de bout en bout (E2E)
-- Directive : 20260803_165946_cmem_web_vers_cmem2_API__e2e-journaux-champs-chiffres.md
-- Date      : 2026-08-03
--
-- Le chiffrement est fait entièrement côté client (WebCrypto : PBKDF2-SHA256
-- pour la dérivation, AES-GCM 256 pour le contenu). Le serveur ne stocke que
-- des octets opaques : aucune clé, aucune passphrase ne transite vers l'API.
--
-- Impact sur les données existantes : AUCUN.
--   - enc_alg / enc_iv sont ajoutées à NULL → tous les journaux actuels restent
--     en clair et se comportent à l'identique.
--   - summary VARCHAR(255) → VARCHAR(2000) : élargissement, aucune troncature.
--   - description TEXT → MEDIUMTEXT : élargissement, aucune troncature.
--
-- Pourquoi ces élargissements : le base64 gonfle le contenu d'environ 4/3.
-- La directive exige summary ≥ 2 000 caractères et description ≥ 200 000.
-- TEXT plafonne à 65 535 octets, donc insuffisant pour 200 000 caractères.
-- =====================================================================

ALTER TABLE calendar_journals
    MODIFY COLUMN summary     VARCHAR(2000) NOT NULL
                  COMMENT 'Clair, ou base64 opaque si enc_alg renseigné',
    MODIFY COLUMN description MEDIUMTEXT    DEFAULT NULL
                  COMMENT 'Clair, ou base64 opaque si enc_alg renseigné',
    ADD COLUMN    enc_alg     VARCHAR(32)   DEFAULT NULL
                  COMMENT 'Algorithme de chiffrement client, ex. AES-GCM-256. NULL = journal en clair'
                  AFTER description,
    ADD COLUMN    enc_iv      VARCHAR(32)   DEFAULT NULL
                  COMMENT 'Vecteur d''initialisation base64 (12 octets → 16 caractères). NULL = journal en clair'
                  AFTER enc_alg;
