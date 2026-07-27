-- Migration pendante : table `opportunite` (CRM pipeline, Phase G-D).
-- Directive cmem_web 20260724_154618 — opportunités commerciales rattachées à un contact,
-- affichées en Kanban par étape (GET/POST /contacts/{id}/opportunites, GET/PUT/DELETE /opportunites).
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Owner-strict : user_id = propriétaire de la fiche contact. Devise par défaut CAD.
-- Soft-delete via `supprime_le` ; cascade applicative depuis la suppression du contact.

CREATE TABLE IF NOT EXISTS opportunite (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id              VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    user_id             INT(11)      NOT NULL,
    contact_id          INT UNSIGNED NOT NULL,
    titre               VARCHAR(190) NOT NULL,
    etape               ENUM('prospect','qualifie','proposition','gagne','perdu')
                            NOT NULL DEFAULT 'prospect',
    montant             DECIMAL(12,2) NULL,
    devise              VARCHAR(8)   NOT NULL DEFAULT 'CAD',
    date_cloture_prevue DATE         NULL,
    notes               TEXT         NULL,
    cree_le             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    maj_le              DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    supprime_le         DATETIME     NULL,

    INDEX idx_opportunite_owner   (app_id, user_id, supprime_le),
    INDEX idx_opportunite_contact (contact_id, supprime_le),
    INDEX idx_opportunite_etape   (app_id, user_id, etape, supprime_le),

    CONSTRAINT fk_opportunite_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
