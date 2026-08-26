-- Migration Phase 1.4 — Repos hors combat + Régénération passive
-- Ajouter deux colonnes à traque_players

ALTER TABLE traque_players
  ADD COLUMN rest_available_at DATETIME NULL DEFAULT NULL
    COMMENT 'Cooldown repos actif — NULL = disponible maintenant',
  ADD COLUMN last_combat_at DATETIME NULL DEFAULT NULL
    COMMENT 'Timestamp du dernier combat (fin) pour régén passive 1 HP/5 min';
