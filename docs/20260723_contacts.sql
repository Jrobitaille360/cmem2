-- Migration pendante : pilier Contacts (directive cmem_web 20260723_084409)
-- Réf. directive 20260723_084409_cmem_web_vers_cmem2_API__contacts-table-crud.md
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Renommages par rapport à la directive (conventions du dépôt) :
--   contact         → contacts        (tables au pluriel)
--   contact_partage → contact_shares  (noms de tables en anglais)
-- Les colonnes restent en français : elles forment le contrat JSON attendu par le front.
--
-- Multi-tenant : app_id ('cmemweb' pour le client cmem_web, défaut serveur 'puzzle').
-- Un contact n'est PAS un compte : user_id = propriétaire de la fiche.
-- Soft-delete via supprime_le (purge RGPD par le cron existant).

CREATE TABLE IF NOT EXISTS contacts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id        VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    user_id       INT(11)      NOT NULL,
    prenom        VARCHAR(190) NOT NULL DEFAULT '',
    nom           VARCHAR(190) NOT NULL DEFAULT '',
    organisation  VARCHAR(190) NULL,
    fonction      VARCHAR(190) NULL,
    courriels     JSON         NOT NULL,
    telephones    JSON         NOT NULL,
    adresses      JSON         NOT NULL,
    sites         JSON         NOT NULL,
    reseaux       JSON         NOT NULL,
    notes         TEXT         NULL,
    categories    JSON         NOT NULL,
    anniversaire  DATE         NULL,
    photo_file_id INT(11)      NULL,
    favori        TINYINT(1)   NOT NULL DEFAULT 0,
    -- Réservé partage futur (P1) — inactif en v1, prévu pour éviter une migration cassante.
    partage_scope ENUM('prive','groupe','utilisateurs') NOT NULL DEFAULT 'prive',
    cree_le       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    maj_le        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    supprime_le   DATETIME     NULL,
    INDEX idx_contacts_owner    (app_id, user_id, supprime_le),
    INDEX idx_contacts_nom      (app_id, user_id, nom, prenom),
    INDEX idx_contacts_favori   (app_id, user_id, favori),
    CONSTRAINT fk_contacts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_contacts_file FOREIGN KEY (photo_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de partage réservée : créée mais non exploitée en v1 (aucune route ne l'appelle).
CREATE TABLE IF NOT EXISTS contact_shares (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contact_id INT UNSIGNED NOT NULL,
    groupe_id  INT(11)      NULL,
    user_id    INT(11)      NULL,
    droit      ENUM('lecture','ecriture') NOT NULL DEFAULT 'lecture',
    cree_le    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contact_shares (contact_id, groupe_id, user_id),
    INDEX idx_contact_shares_contact (contact_id),
    CONSTRAINT fk_contact_shares_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_shares_group   FOREIGN KEY (groupe_id)  REFERENCES groups(id)   ON DELETE CASCADE,
    CONSTRAINT fk_contact_shares_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
