-- Migration pendante : liens croisés polymorphes inter-entités (directive cmem_web B2)
-- Réf. directive 20260722_141845_cmem_web_vers_cmem2_API__links-inter-entites.md
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.
--
-- Entités liables (polymorphe) :
--   event        → calendar_events
--   task         → calendar_todos (project_id NULL)
--   journal      → calendar_journals
--   project      → projects
--   project_task → calendar_todos (project_id NOT NULL)
--
-- Multi-tenant : app_id ('cmemweb' pour le client cmem_web, défaut serveur 'puzzle').
-- Lien logiquement bidirectionnel : une seule ligne (src → dst). GET renvoie entrants + sortants.
-- Dédup : le doublon exact ET le sens inverse (dst→src identique) sont refusés à la création
--         (voir LinkController::create) → une seule ligne par paire logique.

CREATE TABLE IF NOT EXISTS links (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    app_id     VARCHAR(64)  NOT NULL DEFAULT 'puzzle',
    owner_id   INT(11)      NOT NULL,
    src_type   ENUM('event','task','journal','project','project_task') NOT NULL,
    src_id     INT UNSIGNED NOT NULL,
    dst_type   ENUM('event','task','journal','project','project_task') NOT NULL,
    dst_id     INT UNSIGNED NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_links_logical (app_id, owner_id, src_type, src_id, dst_type, dst_id),
    INDEX idx_links_owner (owner_id),
    INDEX idx_links_src   (app_id, src_type, src_id),
    INDEX idx_links_dst   (app_id, dst_type, dst_id),
    CONSTRAINT fk_links_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
