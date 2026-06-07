ALTER TABLE `traque_players`
  ADD COLUMN `character_name` VARCHAR(50) NOT NULL DEFAULT '' AFTER `player_id`;
