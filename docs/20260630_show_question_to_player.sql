ALTER TABLE quiz_quizzes
  ADD COLUMN show_question_to_player TINYINT(1) NOT NULL DEFAULT 1
  AFTER show_leaderboard;
