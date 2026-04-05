<?php

namespace Quiz\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Quiz\Models\Quiz;
use Quiz\Models\Question;
use Quiz\Models\Session;
use Quiz\Models\Participant;
use Quiz\Models\ParticipantAnswer;
use Quiz\Services\SessionService;

/**
 * SessionController — lancement, avancement, fermeture de session et résultats
 */
class SessionController
{
    // -----------------------------------------------------------------------
    // POST /quiz/{quiz_id}/sessions  (JWT)
    // -----------------------------------------------------------------------

    public function createSession(int $quizId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $quiz = (new Quiz())->findByUserAndId($user['user_id'], $quizId);
        if (!$quiz) {
            LoggingMiddleware::logExit(404);
            Response::error('Quiz introuvable', null, 404);
            return;
        }

        if ((new Question())->countByQuiz($quizId) === 0) {
            LoggingMiddleware::logExit(422);
            Response::error('Le quiz ne contient aucune question', null, 422);
            return;
        }

        $service     = new SessionService();
        $sessionCode = $service->generateSessionCode();

        $sessionId = (new Session())->createFromData([
            'quiz_id'      => $quizId,
            'host_user_id' => $user['user_id'],
            'session_code' => $sessionCode,
        ]);

        LoggingMiddleware::logExit(201);
        Response::success('Session créée', [
            'session_id'   => $sessionId,
            'session_code' => $sessionCode,
        ], 201);
    }

    // -----------------------------------------------------------------------
    // POST /quiz/sessions/{sid}/next  (JWT)
    // -----------------------------------------------------------------------

    public function nextQuestion(int $sessionId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $sessionModel = new Session();
        $session      = $sessionModel->findByIdAndHost($sessionId, $user['user_id']);
        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Session introuvable', null, 404);
            return;
        }

        if ($session['status'] === 'ended') {
            LoggingMiddleware::logExit(409);
            Response::error('La session est déjà terminée', null, 409);
            return;
        }

        $questions  = (new Question())->findByQuizId((int) $session['quiz_id']);
        $nextIdx    = (int) $session['current_question_idx'] + 1;

        if ($nextIdx >= count($questions)) {
            // Plus de questions → on ferme automatiquement
            $sessionModel->end($sessionId);
            (new SessionService())->updateRankings($sessionId);

            LoggingMiddleware::logExit(200);
            Response::success('Toutes les questions ont été jouées — session terminée', [
                'status' => 'ended',
            ]);
            return;
        }

        $sessionModel->advanceQuestion($sessionId, $nextIdx);

        LoggingMiddleware::logExit(200);
        Response::success('Question suivante', [
            'current_question_idx' => $nextIdx,
            'total_questions'      => count($questions),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /quiz/sessions/{sid}/end  (JWT)
    // -----------------------------------------------------------------------

    public function endSession(int $sessionId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $sessionModel = new Session();
        $session      = $sessionModel->findByIdAndHost($sessionId, $user['user_id']);
        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Session introuvable', null, 404);
            return;
        }

        if ($session['status'] === 'ended') {
            LoggingMiddleware::logExit(409);
            Response::error('La session est déjà terminée', null, 409);
            return;
        }

        $sessionModel->end($sessionId);
        (new SessionService())->updateRankings($sessionId);

        LoggingMiddleware::logExit(200);
        Response::success('Session terminée');
    }

    // -----------------------------------------------------------------------
    // GET /quiz/sessions/{sid}/results  (JWT)
    // -----------------------------------------------------------------------

    public function getResults(int $sessionId, array $user): void
    {
        LoggingMiddleware::logEntry();

        $session = (new Session())->findByIdAndHost($sessionId, $user['user_id']);
        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Session introuvable', null, 404);
            return;
        }

        $leaderboard   = (new Participant())->getLeaderboard($sessionId);
        $questionStats = (new ParticipantAnswer())->getResultsBySession($sessionId);
        $totalPart     = (new Participant())->countBySession($sessionId);

        LoggingMiddleware::logExit(200);
        Response::success('Résultats', [
            'session'        => $session,
            'total_participants' => $totalPart,
            'leaderboard'    => $leaderboard,
            'question_stats' => $questionStats,
        ]);
    }
}
