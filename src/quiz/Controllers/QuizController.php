<?php

namespace Quiz\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use AuthGroups\Models\Tag;
use Quiz\Models\Quiz;
use Quiz\Models\Question;
use Quiz\Models\Choice;
use Quiz\Models\Session;
use Quiz\Validators\QuizValidator;

/**
 * QuizController — CRUD quiz, CRUD questions/choix, historique
 */
class QuizController
{
    // -----------------------------------------------------------------------
    // Quiz CRUD
    // -----------------------------------------------------------------------

    public function listQuizzes(array $user): void
    {
        LoggingMiddleware::logEntry();

        $quizzes = (new Quiz())->listByUser($user['user_id']);

        LoggingMiddleware::logExit(200);
        Response::success('Quiz récupérés', ['quizzes' => $quizzes]);
    }

    public function createQuiz(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = QuizValidator::validateCreate($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $input['user_id'] = $user['user_id'];
        $id = (new Quiz())->createFromData($input);

        LoggingMiddleware::logExit(201);
        Response::success('Quiz créé', ['id' => $id], 201);
    }

    public function getQuiz(int $quizId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $quiz = (new Quiz())->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        $quiz = $this->attachQuestionsWithChoices($quiz);

        LoggingMiddleware::logExit(200);
        Response::success('Quiz récupéré', ['quiz' => $quiz]);
    }

    public function updateQuiz(int $quizId, array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $quizModel = new Quiz();
        $quiz = $quizModel->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        $validation = QuizValidator::validateUpdate($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $quizModel->updateFromData($quizId, $input);

        LoggingMiddleware::logExit(200);
        Response::success('Quiz mis à jour');
    }

    public function deleteQuiz(int $quizId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $quizModel = new Quiz();
        $quiz = $quizModel->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        $quizModel->deleteById($quizId);

        LoggingMiddleware::logExit(200);
        Response::success('Quiz supprimé');
    }

    // -----------------------------------------------------------------------
    // Questions CRUD
    // -----------------------------------------------------------------------

    public function addQuestion(int $quizId, array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $quiz = (new Quiz())->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        $validation = QuizValidator::validateQuestion($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $input['quiz_id'] = $quizId;
        $questionModel    = new Question();
        $questionId       = $questionModel->createFromData($input);

        // Insérer les choix si fournis
        if (!empty($input['choices']) && is_array($input['choices'])) {
            $choiceModel = new Choice();
            foreach ($input['choices'] as $pos => $choiceData) {
                $choiceData['question_id'] = $questionId;
                $choiceData['position']    = $choiceData['position'] ?? $pos;
                $choiceModel->createFromData($choiceData);
            }
        }

        LoggingMiddleware::logExit(201);
        Response::success('Question ajoutée', ['id' => $questionId], 201);
    }

    public function updateQuestion(int $quizId, int $questionId, array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $quiz = (new Quiz())->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        $questionModel = new Question();
        $question = $questionModel->findByIdAndQuiz($questionId, $quizId);
        if (!$question) {
            LoggingMiddleware::logExit(404);
            Response::error('Question introuvable', null, 404);
            return;
        }

        // Valider seulement les champs présents (update partiel) — fournir type + content existants comme base
        $toValidate = array_merge(
            ['type' => $question['type'], 'content' => $question['content']],
            $input
        );
        $validation = QuizValidator::validateQuestion($toValidate, true);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $questionModel->updateFromData($questionId, $input);

        // Remplacer les choix si fournis
        if (isset($input['choices']) && is_array($input['choices'])) {
            $choiceModel = new Choice();
            $choiceModel->deleteByQuestionId($questionId);
            foreach ($input['choices'] as $pos => $choiceData) {
                $choiceData['question_id'] = $questionId;
                $choiceData['position']    = $choiceData['position'] ?? $pos;
                $choiceModel->createFromData($choiceData);
            }
        }

        LoggingMiddleware::logExit(200);
        Response::success('Question mise à jour');
    }

    public function deleteQuestion(int $quizId, int $questionId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $quiz = (new Quiz())->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        $questionModel = new Question();
        $question = $questionModel->findByIdAndQuiz($questionId, $quizId);
        if (!$question) {
            LoggingMiddleware::logExit(404);
            Response::error('Question introuvable', null, 404);
            return;
        }

        $questionModel->deleteById($questionId);

        LoggingMiddleware::logExit(200);
        Response::success('Question supprimée');
    }

    // -----------------------------------------------------------------------
    // Historique
    // -----------------------------------------------------------------------

    public function history(array $user): void
    {
        LoggingMiddleware::logEntry();

        $sessions = (new Session())->listByHost($user['user_id']);

        LoggingMiddleware::logExit(200);
        Response::success('Historique récupéré', ['sessions' => $sessions]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function attachQuestionsWithChoices(array $quiz): array
    {
        $questions = (new Question())->findByQuizId((int) $quiz['id']);

        if (!empty($questions)) {
            $questionIds = array_column($questions, 'id');
            $choicesByQ  = (new Choice())->findByQuestionIds($questionIds);
            $tagsByQ     = (new Tag())->findTagsByQuestionIds($questionIds);

            foreach ($questions as &$q) {
                $q['content'] = is_string($q['content'])
                    ? json_decode($q['content'], true)
                    : $q['content'];
                $q['choices'] = $choicesByQ[$q['id']] ?? [];
                foreach ($q['choices'] as &$c) {
                    $c['content'] = is_string($c['content'])
                        ? json_decode($c['content'], true)
                        : $c['content'];
                }
                unset($c);
                $q['tags'] = $tagsByQ[$q['id']] ?? [];
            }
            unset($q);
        }

        $quiz['questions'] = $questions;
        return $quiz;
    }
}
