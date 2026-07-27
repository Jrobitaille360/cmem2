-- Migration pendante : table `interaction` + opt-out courriel contacts
-- Directive cmem_web 20260724_090048 — envoi de courriel depuis une fiche contact (Phase G-B).
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- La table `interaction` est conçue GÉNÉRIQUE pour anticiper la directive crm-interactions
-- (Phase C) : historique unifié des communications d'une fiche contact. La v1 ne l'alimente
-- qu'avec des courriels sortants (type='email', direction='sortant').
--
-- Multi-tenant : app_id ('cmemweb' pour le client cmem_web, défaut serveur 'puzzle').
-- Owner-strict : user_id = propriétaire de la fiche contact (jamais un compte lié).

CREATE TABLE IF NOT EXISTS interaction (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id       VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    user_id      INT(11)      NOT NULL,
    contact_id   INT UNSIGNED NOT NULL,
    type         ENUM('email','appel','sms','note','rencontre','autre') NOT NULL DEFAULT 'email',
    direction    ENUM('sortant','entrant') NOT NULL DEFAULT 'sortant',
    canal        VARCHAR(32)  NULL,
    destinataire VARCHAR(320) NULL,
    sujet        VARCHAR(255) NULL,
    corps        TEXT         NULL,
    statut       ENUM('envoye','echec','brouillon') NOT NULL DEFAULT 'envoye',
    meta         JSON         NULL,
    envoye_le    DATETIME     NULL,
    cree_le      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_interaction_contact (app_id, contact_id, id),
    INDEX idx_interaction_owner   (app_id, user_id, id),
    CONSTRAINT fk_interaction_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_interaction_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opt-out courriel (CASL / RGPD) — RÉSERVÉ v1 : non bloquant.
-- La v1 ne concerne que du courriel transactionnel/personnel initié manuellement par le
-- propriétaire de la fiche. Le champ est prévu pour un futur usage commercial (envoi de masse),
-- où il devra bloquer l'envoi. Ajout idempotent (ignore l'erreur si la colonne existe déjà).
ALTER TABLE contacts
    ADD COLUMN optout_courriel TINYINT(1) NOT NULL DEFAULT 0 AFTER favori;
