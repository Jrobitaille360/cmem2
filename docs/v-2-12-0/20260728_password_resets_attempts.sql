-- ============================================================
-- 2026-07-28 — Reset de mot de passe : compteur de tentatives
-- Directive : 20260728_142154_jdb_vers_cmem2_API__reset-password-code-6-chiffres-securite
--
-- Ajoute un compteur de tentatives sur les codes de réinitialisation
-- (max 5 essais par code, aligné sur OTP_MAX_ATTEMPTS), afin de rendre
-- le code à 6 chiffres non brute-forçable.
--
-- Non destructif : ADD COLUMN uniquement, aucune donnée existante modifiée.
-- ============================================================

ALTER TABLE `password_resets`
  ADD COLUMN `attempts` INT(11) NOT NULL DEFAULT 0 AFTER `token`,
  ADD COLUMN `max_attempts` INT(11) NOT NULL DEFAULT 5 AFTER `attempts`;
