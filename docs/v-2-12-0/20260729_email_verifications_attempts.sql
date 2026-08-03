-- ============================================================
-- 2026-07-29 — Vérification de courriel : compteur de tentatives
-- Directive : 20260728_211346_jdb_vers_cmem2_API__verification-courriel-token-securite
--
-- Ajoute un compteur de tentatives sur les tokens de vérification de courriel
-- (max 5 essais par token, aligné sur password_resets / OTP_MAX_ATTEMPTS),
-- afin que le token à 8 chiffres ne soit plus brute-forçable.
--
-- Non destructif : ADD COLUMN uniquement, aucune donnée existante modifiée.
-- ============================================================

ALTER TABLE `email_verifications`
  ADD COLUMN `attempts` INT(11) NOT NULL DEFAULT 0 AFTER `token`,
  ADD COLUMN `max_attempts` INT(11) NOT NULL DEFAULT 5 AFTER `attempts`;
