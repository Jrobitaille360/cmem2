-- Migration pendante : extension des types liables de `links` (GED, Phase G-E).
-- Directive cmem_web 20260724_154619 — rattacher des fichiers et des contacts aux entités
-- via la table `links` existante (aucune nouvelle mécanique de liaison).
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Nouveaux types : file (files.id), contact (contacts.id), interaction, opportunite.
-- Le reste du contrat /links (dédup idempotent, owner-strict, cascade de purge) est inchangé.

ALTER TABLE links
    MODIFY COLUMN src_type ENUM('event','task','journal','project','project_task',
                                'file','contact','interaction','opportunite') NOT NULL,
    MODIFY COLUMN dst_type ENUM('event','task','journal','project','project_task',
                                'file','contact','interaction','opportunite') NOT NULL;
