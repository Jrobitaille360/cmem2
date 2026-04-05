-- Migration : paramètres de quiz (visibilité résultats + mode temps)
-- Date : 2026-04-05

ALTER TABLE `quiz_quizzes`
    ADD COLUMN `result_visibility` ENUM('immediate','simultaneous','end_only')
        NOT NULL DEFAULT 'immediate'
        COMMENT 'Quand les joueurs voient leur résultat par question',
    ADD COLUMN `time_mode` ENUM('per_question','total','unlimited')
        NOT NULL DEFAULT 'per_question'
        COMMENT 'Mode de gestion du temps : par question, total quiz, ou illimité',
    ADD COLUMN `total_time_sec` INT NULL DEFAULT NULL
        COMMENT 'Durée totale du quiz en secondes (time_mode=total uniquement)';
