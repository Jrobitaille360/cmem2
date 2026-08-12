-- Migration 2026-08-04 — normalisation des surcharges d'occurrence
-- Directive : 20260729_174500_cmem_web_vers_cmem2_API__effacement-lieu-description-occurrence.md
--
-- Nouvelle sémantique de event_occurrences :
--   NULL  = l'occurrence ne surcharge pas le champ (hérite de l'événement parent)
--   ''    = l'occurrence a volontairement vidé le champ
--   texte = l'occurrence remplace la valeur du parent
--
-- Les lignes portant déjà une chaîne vide datent de l'ancienne sémantique
-- (« non modifié ») et doivent être remises à NULL AVANT le déploiement du code.

UPDATE event_occurrences
   SET modified_location = NULL
 WHERE modified_location = '';

UPDATE event_occurrences
   SET modified_description = NULL
 WHERE modified_description = '';
