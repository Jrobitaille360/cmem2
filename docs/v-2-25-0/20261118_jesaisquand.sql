-- ============================================================
-- Migration 2026-11-18 — plugin jesaisquand (directives #524 et #526)
-- Paquets de cartes chronologiques, parties multijoueur au tour par tour,
-- journal des modifications admin des cartes.
--
-- users.id est INT(11) SIGNÉ : les colonnes user_id le sont aussi (sinon errno 150).
-- Idempotent : CREATE TABLE IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS `jsq_paquets` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(64)  NOT NULL,
  `titre`       VARCHAR(255) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `source`      VARCHAR(255) DEFAULT NULL,
  `owner_id`    INT(11)      DEFAULT NULL,
  `public`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jsq_paquets_slug` (`slug`),
  KEY `idx_jsq_paquets_owner` (`owner_id`),
  CONSTRAINT `fk_jsq_paquets_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- uid = identifiant texte de la carte côté client (ex. « histoire-quebec-012 »)
CREATE TABLE IF NOT EXISTS `jsq_cartes` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `paquet_id`     INT(11)      NOT NULL,
  `uid`           VARCHAR(100) NOT NULL,
  `ordre`         INT(11)      NOT NULL DEFAULT 0,
  `titre`         VARCHAR(255) NOT NULL,
  `annee`         INT(11)      NOT NULL,
  `mois`          TINYINT(4)   DEFAULT NULL,
  `jour`          TINYINT(4)   DEFAULT NULL,
  `date_texte`    VARCHAR(100) DEFAULT NULL,
  `periode`       VARCHAR(100) DEFAULT NULL,
  `approx`        TINYINT(1)   NOT NULL DEFAULT 0,
  `essentiel`     TINYINT(1)   NOT NULL DEFAULT 0,
  `categorie`     VARCHAR(100) DEFAULT NULL,
  `description`   TEXT         DEFAULT NULL,
  `image_url`     VARCHAR(500) DEFAULT NULL,
  `image_file_id` INT(11)      DEFAULT NULL,
  `image_source`  VARCHAR(500) DEFAULT NULL,
  `credit`        VARCHAR(500) DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jsq_cartes_paquet_uid` (`paquet_id`, `uid`),
  KEY `idx_jsq_cartes_file` (`image_file_id`),
  CONSTRAINT `fk_jsq_cartes_paquet` FOREIGN KEY (`paquet_id`) REFERENCES `jsq_paquets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jsq_cartes_file` FOREIGN KEY (`image_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des modifications admin (#526) : qui, quand, ancienne et nouvelle valeur.
-- user_id sans clé étrangère : l'historique survit à la suppression du compte.
CREATE TABLE IF NOT EXISTS `jsq_cartes_journal` (
  `id`         INT(11)   NOT NULL AUTO_INCREMENT,
  `carte_id`   INT(11)   NOT NULL,
  `user_id`    INT(11)   DEFAULT NULL,
  `ancien`     JSON      NOT NULL,
  `nouveau`    JSON      NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jsq_journal_carte` (`carte_id`),
  CONSTRAINT `fk_jsq_journal_carte` FOREIGN KEY (`carte_id`) REFERENCES `jsq_cartes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- frise / pioche : listes JSON d'ids jsq_cartes.id (frise triée, pioche mélangée)
CREATE TABLE IF NOT EXISTS `jsq_parties` (
  `id`             INT(11)    NOT NULL AUTO_INCREMENT,
  `code`           CHAR(6)    NOT NULL,
  `paquet_id`      INT(11)    NOT NULL,
  `statut`         ENUM('attente','en_cours','terminee','expiree') NOT NULL DEFAULT 'attente',
  `taille_main`    TINYINT(4) NOT NULL DEFAULT 4,
  `tour_joueur_id` INT(11)    DEFAULT NULL,
  `version`        INT(11)    NOT NULL DEFAULT 1,
  `frise`          JSON       DEFAULT NULL,
  `pioche`         JSON       DEFAULT NULL,
  `created_at`     TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jsq_parties_code` (`code`),
  KEY `idx_jsq_parties_maj` (`statut`, `updated_at`),
  CONSTRAINT `fk_jsq_parties_paquet` FOREIGN KEY (`paquet_id`) REFERENCES `jsq_paquets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- jeton_hash = SHA-256 du jeton joueur (le jeton clair n'est jamais stocké)
-- main = liste JSON d'ids jsq_cartes.id ; ordre 1 = créateur du salon
CREATE TABLE IF NOT EXISTS `jsq_joueurs` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `partie_id`  INT(11)     NOT NULL,
  `user_id`    INT(11)     DEFAULT NULL,
  `pseudo`     VARCHAR(40) NOT NULL,
  `jeton_hash` CHAR(64)    NOT NULL,
  `ordre`      TINYINT(4)  NOT NULL,
  `main`       JSON        DEFAULT NULL,
  `gagnant`    TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jsq_joueurs_jeton` (`jeton_hash`),
  UNIQUE KEY `uq_jsq_joueurs_ordre` (`partie_id`, `ordre`),
  KEY `idx_jsq_joueurs_user` (`user_id`),
  CONSTRAINT `fk_jsq_joueurs_partie` FOREIGN KEY (`partie_id`) REFERENCES `jsq_parties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jsq_joueurs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jsq_coups` (
  `id`            INT(11)    NOT NULL AUTO_INCREMENT,
  `partie_id`     INT(11)    NOT NULL,
  `joueur_id`     INT(11)    NOT NULL,
  `carte_id`      INT(11)    NOT NULL,
  `index_demande` INT(11)    NOT NULL,
  `index_final`   INT(11)    NOT NULL,
  `correct`       TINYINT(1) NOT NULL,
  `penalite_carte_id` INT(11) DEFAULT NULL,
  `created_at`    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jsq_coups_partie` (`partie_id`, `id`),
  CONSTRAINT `fk_jsq_coups_partie` FOREIGN KEY (`partie_id`) REFERENCES `jsq_parties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jsq_coups_joueur` FOREIGN KEY (`joueur_id`) REFERENCES `jsq_joueurs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
