<?php

namespace Quiz\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Quiz\Models\Session;
use Quiz\Models\Question;
use Quiz\Models\Choice;
use Quiz\Models\Participant;
use Quiz\Models\ParticipantAnswer;
use Quiz\Models\Quiz;
use Quiz\Services\SessionService;
use Quiz\Validators\SessionValidator;

/**
 * ParticipantController — join, état session, réponse, leaderboard
 *
 * Ces routes utilisent participant_token (pas JWT).
 * L'authentification est vérifiée dans QuizRouteHandler avant l'appel.
 */
class ParticipantController
{
    // -----------------------------------------------------------------------
    // POST /quiz/join  (no auth)
    // -----------------------------------------------------------------------

    public function join(): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = SessionValidator::validateJoin($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $sessionCode  = strtoupper(trim($input['session_code']));
        $sessionModel = new Session();
        $session      = $sessionModel->findByCode($sessionCode);

        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Code de session invalide', null, 404);
            return;
        }

        if (!in_array($session['status'], ['waiting', 'active'], true)) {
            LoggingMiddleware::logExit(409);
            Response::error('Cette session n\'accepte plus de nouveaux participants', null, 409);
            return;
        }

        $deviceId        = $input['device_id'];
        $participantModel = new Participant();

        // Vérifier si ce device a déjà rejoint cette session
        $existing = $participantModel->findBySessionAndDevice((int) $session['id'], $deviceId);
        if ($existing) {
            // Renvoyer le token existant (reconnexion)
            LoggingMiddleware::logExit(200);
            Response::success('Reconnexion à la session', [
                'participant_token' => $existing['participant_token'],
                'session_id'        => (int) $session['id'],
                'display_name'      => $existing['display_name'],
            ]);
            return;
        }

        // Créer le participant (sans token pour l'instant — on a besoin de son ID)
        $participantId = $participantModel->createFromData([
            'session_id'        => (int) $session['id'],
            'display_name'      => trim($input['display_name']),
            'device_id'         => $deviceId,
            'participant_token' => '', // temporaire
        ]);

        // Générer le token maintenant qu'on a l'ID
        $service = new SessionService();
        $token   = $service->generateParticipantToken(
            (int) $session['id'],
            $participantId,
            $deviceId
        );

        // Mettre à jour le token en base
        $participantModel->updateToken($participantId, $token);

        LoggingMiddleware::logExit(201);
        Response::success('Participant inscrit', [
            'participant_token' => $token,
            'session_id'        => (int) $session['id'],
            'display_name'      => trim($input['display_name']),
        ], 201);
    }

    // -----------------------------------------------------------------------
    // GET /quiz/session/{session_id}  (participant_token)
    // -----------------------------------------------------------------------

    public function getSession(int $sessionId, array $participant): void
    {
        LoggingMiddleware::logEntry();

        $session = (new Session())->findById($sessionId);
        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Session introuvable', null, 404);
            return;
        }

        $quiz = (new Quiz())->findById((int) $session['quiz_id']);
        $quizSettings = [
            'result_visibility' => $quiz['result_visibility'] ?? 'immediate',
            'time_mode'         => $quiz['time_mode']         ?? 'per_question',
            'total_time_sec'    => isset($quiz['total_time_sec']) ? (int) $quiz['total_time_sec'] : null,
            'show_leaderboard'  => (bool) ($quiz['show_leaderboard'] ?? true),
        ];

        $data = [
            'session_id'           => (int) $session['id'],
            'status'               => $session['status'],
            'current_question_idx' => (int) $session['current_question_idx'],
            'current_question'     => null,
            'quiz_settings'        => $quizSettings,
        ];

        // Retourner la question courante si la session est active
        if ($session['status'] === 'active' && $session['current_question_idx'] >= 0) {
            $questions = (new Question())->findByQuizId((int) $session['quiz_id']);
            $idx       = (int) $session['current_question_idx'];

            if (isset($questions[$idx])) {
                $q = $questions[$idx];
                $q['content'] = is_string($q['content'])
                    ? json_decode($q['content'], true)
                    : $q['content'];

                // Fournir les choix pour MCQ/truefalse — sans révéler is_correct
                if (in_array($q['type'], ['mcq', 'truefalse'], true)) {
                    $choices = (new Choice())->findByQuestionId((int) $q['id']);
                    foreach ($choices as &$c) {
                        unset($c['is_correct']); // non transmis avant fermeture
                        $c['content'] = is_string($c['content'])
                            ? json_decode($c['content'], true)
                            : $c['content'];
                    }
                    unset($c);
                    $q['choices'] = $choices;
                }

                $data['current_question'] = [
                    'id'             => (int) $q['id'],
                    'type'           => $q['type'],
                    'content'        => $q['content'],
                    'points'         => (int) $q['points'],
                    'time_limit_sec' => (int) $q['time_limit_sec'],
                    'choices'        => $q['choices'] ?? [],
                    'total'          => count($questions),
                    'index'          => $idx,
                ];
            }
        }

        LoggingMiddleware::logExit(200);
        Response::success('État de la session', $data);
    }

    // -----------------------------------------------------------------------
    // POST /quiz/session/{session_id}/answer  (participant_token)
    // -----------------------------------------------------------------------

    public function submitAnswer(int $sessionId, array $participant): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = SessionValidator::validateAnswer($input);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $validation['errors'], 422);
            return;
        }

        $session = (new Session())->findById($sessionId);
        if (!$session || $session['status'] !== 'active') {
            LoggingMiddleware::logExit(409);
            Response::error('La session n\'est pas active', null, 409);
            return;
        }

        $questionId     = (int) $input['question_id'];
        $participantId  = (int) $participant['id'];
        $answerModel    = new ParticipantAnswer();

        // Unicité : une seule réponse par (participant, question)
        if ($answerModel->existsByParticipantAndQuestion($participantId, $questionId)) {
            LoggingMiddleware::logExit(409);
            Response::error('Vous avez déjà répondu à cette question', null, 409);
            return;
        }

        // Vérifier que la question appartient au quiz de la session
        $questionModel = new Question();
        $question      = $questionModel->findByIdAndQuiz($questionId, (int) $session['quiz_id']);
        if (!$question) {
            LoggingMiddleware::logExit(404);
            Response::error('Question introuvable dans cette session', null, 404);
            return;
        }

        // Vérifier que c'est bien la question courante
        $questions  = $questionModel->findByQuizId((int) $session['quiz_id']);
        $currentIdx = (int) $session['current_question_idx'];
        if (!isset($questions[$currentIdx]) || (int) $questions[$currentIdx]['id'] !== $questionId) {
            LoggingMiddleware::logExit(409);
            Response::error("Cette question n'est pas la question courante", null, 409);
            return;
        }

        // Évaluer la réponse
        $responseTimeMs = max(0, (int) $input['response_time_ms']);
        $value          = (string) $input['value'];
        $isCorrect      = false;
        $pointsEarned   = 0;

        if (in_array($question['type'], ['mcq', 'truefalse'], true)) {
            $correct = (new Choice())->findCorrectByQuestion($questionId);
            if ($correct && (string) $correct['id'] === $value) {
                $isCorrect    = true;
                $service      = new SessionService();
                $pointsEarned = $service->calculatePoints(
                    (int) $question['points'],
                    $responseTimeMs,
                    (int) $question['time_limit_sec']
                );
            }
        }
        // type=numerical est réservé à la Phase 3 (VariableService)

        // Persister la réponse
        $answerModel->createFromData([
            'participant_id'   => $participantId,
            'session_id'       => $sessionId,
            'question_id'      => $questionId,
            'value'            => $value,
            'is_correct'       => $isCorrect,
            'points_earned'    => $pointsEarned,
            'response_time_ms' => $responseTimeMs,
        ]);

        // Mettre à jour le score du participant
        if ($pointsEarned > 0) {
            (new Participant())->addScore($participantId, $pointsEarned);
        }

        LoggingMiddleware::logExit(200);
        Response::success('Réponse enregistrée', [
            'is_correct'    => $isCorrect,
            'points_earned' => $pointsEarned,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /quiz/session/{session_id}/leaderboard  (participant_token)
    // -----------------------------------------------------------------------

    public function getLeaderboard(int $sessionId, array $participant): void
    {
        LoggingMiddleware::logEntry();

        $session = (new Session())->findById($sessionId);
        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Session introuvable', null, 404);
            return;
        }

        $leaderboard  = (new Participant())->getLeaderboard($sessionId);
        $totalPart    = count($leaderboard);

        // Trouver le rang du participant courant
        $myRank = null;
        foreach ($leaderboard as $entry) {
            if ((int) $entry['id'] === (int) $participant['id']) {
                $myRank = $entry['rank'] ?? null;
                break;
            }
        }

        LoggingMiddleware::logExit(200);
        Response::success('Classement', [
            'total_participants' => $totalPart,
            'my_rank'            => $myRank,
            'my_score'           => (int) $participant['score'],
            'leaderboard'        => $leaderboard,
        ]);
    }
}
