-- Migration : contrainte UNIQUE sur traque_players.character_name
-- Date : 2026-06-07
--
-- STOP : vérifier l'absence de doublons avant d'exécuter :
-- SELECT character_name, COUNT(*) FROM traque_players GROUP BY character_name HAVING COUNT(*) > 1;
-- Si 0 ligne → procéder.

ALTER TABLE traque_players
    ADD CONSTRAINT uq_traque_players_character_name UNIQUE (character_name);
