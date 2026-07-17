-- Migration pendante — NON EXÉCUTÉE automatiquement.
-- Directive 20260716_113000_cmem_web_vers_cmem2_API__role-superadministrateur
--
-- Ajoute SUPERADMINISTRATEUR à l'enum users.role. Aucun endpoint API ne permet
-- d'attribuer ce rôle (voir UserManagerController::updateProfile) — il doit
-- être posé manuellement après cette migration :
--   UPDATE users SET role = 'SUPERADMINISTRATEUR' WHERE id = ...;
--
-- STOP : exécuter contre dev puis prod seulement après confirmation explicite
-- (règle projet — ALTER sur table users partagée par toutes les apps).

ALTER TABLE users MODIFY role ENUM('SUPERADMINISTRATEUR','ADMINISTRATEUR','UTILISATEUR')
  NOT NULL DEFAULT 'UTILISATEUR';
