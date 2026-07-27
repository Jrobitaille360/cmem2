-- Migration pendante : extension CRM de la table `interaction` (Phase G-C).
-- Directive cmem_web 20260724_143353 — historique d'interactions par contact
-- (GET/POST/DELETE /contacts/{id}/interactions).
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Réutilise la table `interaction` créée en Phase G-B (20260724_interactions.sql).
-- Ajoute la saisie manuelle (appel/note/rdv/sms), le soft-delete et la pièce jointe.
-- Owner-strict : user_id = propriétaire de la fiche contact.

-- 1. Nouveau type d'interaction 'rdv' (garde les valeurs existantes).
ALTER TABLE interaction
    MODIFY COLUMN type ENUM('email','appel','sms','note','rencontre','rdv','autre')
        NOT NULL DEFAULT 'email';

-- 2. statut nullable : les saisies manuelles n'ont pas de statut d'envoi.
ALTER TABLE interaction
    MODIFY COLUMN statut ENUM('envoye','echec','brouillon') NULL DEFAULT NULL;

-- 3. Champs CRM (ajouts idempotents — ignorer l'erreur si la colonne existe déjà).
ALTER TABLE interaction
    ADD COLUMN resume               TEXT         NULL AFTER corps;
ALTER TABLE interaction
    ADD COLUMN date_interaction     DATETIME     NULL AFTER resume;
ALTER TABLE interaction
    ADD COLUMN piece_jointe_file_id INT(11)      NULL AFTER date_interaction;
ALTER TABLE interaction
    ADD COLUMN maj_le               DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP AFTER cree_le;
ALTER TABLE interaction
    ADD COLUMN supprime_le          DATETIME     NULL AFTER maj_le;

-- 4. Pièce jointe → module files (nullable, purge côté fichier indépendante).
ALTER TABLE interaction
    ADD CONSTRAINT fk_interaction_piece_jointe
        FOREIGN KEY (piece_jointe_file_id) REFERENCES files(id) ON DELETE SET NULL;
