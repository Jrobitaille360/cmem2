<?php

namespace Quiz\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Quiz\Controllers\QuizController;
use Quiz\Controllers\SessionController;
use Quiz\Controllers\ParticipantController;
use Quiz\Models\Participant;
use Quiz\Models\Session;

/**
 * QuizRouteHandler — gestionnaire unique pour toutes les routes /quiz/*
 *
 * Auth conditionnelle :
 *  - POST /quiz/join                            → sans auth
 *  - /quiz/session/{id}[/answer|/leaderboard]   → participant_token (Bearer)
 *  - toutes les autres                          → JWT Bearer
 *
 * requiresAuth = false : le middleware de base est ignoré ; chaque branche
 * gère elle-même son niveau d'authentification.
 */
class QuizRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['quiz'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // segments[0] = 'quiz'
        $s1 = $segments[1] ?? '';   // action / resource / id
        $s2 = $segments[2] ?? '';   // sous-ressource / id
        $s3 = $segments[3] ?? '';   // action (answer, leaderboard, next, end, results, q_id)
        $s4 = $segments[4] ?? '';   // q_id (PUT/DELETE questions)

        // -------------------------------------------------------------------
        // POST /quiz/join — sans auth
        // -------------------------------------------------------------------
        if ($s1 === 'join' && $method === 'POST') {
            (new ParticipantController())->join();
            return;
        }

        // -------------------------------------------------------------------
        // Routes participant_token : /quiz/session/{session_id}[/...]
        // -------------------------------------------------------------------
        if ($s1 === 'session') {
            $sessionId  = (int) $s2;
            $participant = $this->requireParticipantToken($sessionId);
            if ($participant === null) {
                return; // réponse d'erreur déjà envoyée
            }

            if ($method === 'GET' && $s3 === '') {
                (new ParticipantController())->getSession($sessionId, $participant);
            } elseif ($method === 'POST' && $s3 === 'answer') {
                (new ParticipantController())->submitAnswer($sessionId, $participant);
            } elseif ($method === 'GET' && $s3 === 'leaderboard') {
                (new ParticipantController())->getLeaderboard($sessionId, $participant);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // Routes JWT — authentifier via authService
        // -------------------------------------------------------------------
        $user = $this->requireJwt();
        if ($user === null) {
            return; // réponse 401 déjà envoyée
        }

        // GET /quiz  |  POST /quiz
        if ($s1 === '') {
            match ($method) {
                'GET'  => (new QuizController())->listQuizzes($user),
                'POST' => (new QuizController())->createQuiz($user),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // GET /quiz/history
        if ($s1 === 'history' && $method === 'GET') {
            (new QuizController())->history($user);
            return;
        }

        // /quiz/sessions/{sid}/next|end|results
        if ($s1 === 'sessions') {
            $sid = (int) $s2;
            match (true) {
                ($method === 'POST' && $s3 === 'next')    => (new SessionController())->nextQuestion($sid, $user),
                ($method === 'POST' && $s3 === 'end')     => (new SessionController())->endSession($sid, $user),
                ($method === 'GET'  && $s3 === 'results') => (new SessionController())->getResults($sid, $user),
                default => Response::error('Endpoint non trouvé', null, 404),
            };
            return;
        }

        // /quiz/{id} — id numérique
        if (is_numeric($s1)) {
            $quizId = (int) $s1;

            // /quiz/{id}
            if ($s2 === '') {
                match ($method) {
                    'GET'    => (new QuizController())->getQuiz($quizId, $user),
                    'PUT'    => (new QuizController())->updateQuiz($quizId, $user),
                    'DELETE' => (new QuizController())->deleteQuiz($quizId, $user),
                    default  => Response::error('Méthode non autorisée', null, 405),
                };
                return;
            }

            // /quiz/{id}/questions[/{q_id}]
            if ($s2 === 'questions') {
                if ($method === 'POST' && $s3 === '') {
                    (new QuizController())->addQuestion($quizId, $user);
                } elseif ($method === 'PUT' && is_numeric($s3)) {
                    (new QuizController())->updateQuestion($quizId, (int) $s3, $user);
                } elseif ($method === 'DELETE' && is_numeric($s3)) {
                    (new QuizController())->deleteQuestion($quizId, (int) $s3, $user);
                } else {
                    Response::error('Endpoint non trouvé', null, 404);
                }
                return;
            }

            // POST /quiz/{id}/sessions
            if ($s2 === 'sessions' && $method === 'POST' && $s3 === '') {
                (new SessionController())->createSession($quizId, $user);
                return;
            }

            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }

    // -----------------------------------------------------------------------
    // Helpers d'authentification
    // -----------------------------------------------------------------------

    /**
     * Valide le JWT et retourne les données utilisateur, ou envoie HTTP 401.
     */
    private function requireJwt(): ?array
    {
        $user = $this->authService?->authenticate();
        if (!$user) {
            Response::error('Authentification requise', null, 401);
            return null;
        }
        return $user;
    }

    /**
     * Valide le participant_token depuis le header Authorization: Bearer <token>.
     * Vérifie que la session n'est pas terminée et que le token correspond à la session.
     */
    private function requireParticipantToken(int $sessionId): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::error('participant_token requis (Authorization: Bearer <token>)', null, 401);
            return null;
        }

        $token       = substr($authHeader, 7);
        $participant = (new Participant())->findByToken($token);

        if (!$participant) {
            Response::error('Token invalide', null, 403);
            return null;
        }

        // Vérifier que le token appartient à la bonne session
        if ((int) $participant['session_id'] !== $sessionId) {
            Response::error('Token invalide pour cette session', null, 403);
            return null;
        }

        // Vérifier que la session n'est pas terminée
        $session = (new Session())->findById($sessionId);
        if (!$session || $session['status'] === 'ended') {
            Response::error('Session terminée — token expiré', null, 403);
            return null;
        }

        return $participant;
    }
}
