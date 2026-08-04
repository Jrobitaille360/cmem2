-- =====================================================================
-- Migration — Métadonnées de clé du chiffrement de bout en bout (E2E)
-- Directive : 20260803_205805_cmem_web_vers_cmem2_API__e2e-metadonnees-de-cle.md
-- Date      : 2026-08-03
--
-- Une ligne au plus par couple (owner_id, app_id). Le serveur stocke des
-- octets opaques : sel PBKDF2 (public par conception), nombre d'itérations,
-- clé maîtresse enveloppée deux fois (passphrase / code de secours) et un
-- vérificateur chiffré. Ni la passphrase, ni le code de secours, ni la clé
-- maîtresse en clair ne transitent vers l'API.
--
-- ON DELETE CASCADE : la suppression définitive d'un compte (purge à 30 jours)
-- emporte la ligne. AUCUNE autre opération serveur ne doit la supprimer —
-- sans elle, tous les journaux chiffrés de l'usager deviennent illisibles.
--
-- Pas de deleted_at : la suppression est réelle. Une ligne fantôme casserait
-- l'unicité et ferait échouer le 404 attendu par le client.
-- =====================================================================

CREATE TABLE IF NOT EXISTS user_e2e_keys (
    id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    owner_id               INT           NOT NULL
                           COMMENT 'Propriétaire de la clé — portée owner-strict',
    app_id                 VARCHAR(50)   NOT NULL
                           COMMENT "Application cliente, ex. 'cmemweb'. Toujours transmis, aucun défaut",
    kdf                    VARCHAR(32)   NOT NULL
                           COMMENT 'Fonction de dérivation, ex. PBKDF2-SHA256',
    kdf_salt               VARCHAR(64)   NOT NULL
                           COMMENT 'Sel base64 — public par conception, unique par usager',
    kdf_iterations         INT UNSIGNED  NOT NULL
                           COMMENT 'Nombre d''itérations, ex. 310000',
    wrapped_key_passphrase VARCHAR(4096) NOT NULL
                           COMMENT 'Clé maîtresse enveloppée par la passphrase, base64 opaque',
    wrapped_key_recovery   VARCHAR(4096) DEFAULT NULL
                           COMMENT 'Idem par le code de secours ; NULL si l''usager a refusé',
    verifier               VARCHAR(4096) NOT NULL
                           COMMENT 'Blob chiffré court, valide la passphrase sans toucher un journal',
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_e2e_keys_owner_app (owner_id, app_id),
    CONSTRAINT fk_user_e2e_keys_owner FOREIGN KEY (owner_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Métadonnées de clé e2e — blobs opaques, jamais déchiffrables par le serveur';
