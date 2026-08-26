-- Fix biome vide pour Dragon juvénile (slug: young_dragon)
UPDATE monsters SET biome = 'peak' WHERE asset_key = 'young_dragon' AND (biome IS NULL OR biome = '');
