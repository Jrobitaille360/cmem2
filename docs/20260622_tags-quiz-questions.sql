-- Migration : tags pour quiz_questions
-- Ajouter quiz_questions à l'enum table_associate + table de relation

ALTER TABLE `tags`
  MODIFY `table_associate`
    ENUM('groups','memories','elements','files','all','quiz_questions') DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `quiz_question_tag_relations` (
  `quiz_question_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`quiz_question_id`, `tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `fk_qqtr_question` FOREIGN KEY (`quiz_question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qqtr_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
