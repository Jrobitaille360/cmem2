-- Correctif : ajouter updated_at à quiz_question_tag_relations
-- (manquant dans la migration initiale du même jour)
ALTER TABLE `quiz_question_tag_relations`
  ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp();
