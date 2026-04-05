<?php

namespace Quiz\Services;

use Quiz\Models\Session;
use Quiz\Models\Participant;

/**
 * SessionService — logique de session : session_code, participant_token, scoring, ranking
 */
class SessionService
{
    private const CODE_CHARS   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans I, O, 0, 1 (ambiguïté)
    private const CODE_LENGTH  = 6;
    private const MAX_ATTEMPTS = 20;

    /**
     * Génère un session_code à 6 caractères alphanumériques unique en DB.
     *
     * @throws \RuntimeException si aucun code unique n'est généré après MAX_ATTEMPTS
     */
    public function generateSessionCode(): string
    {
        $model = new Session();

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = $this->randomCode();
            if (!$model->codeExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Impossible de générer un session_code unique');
    }

    /**
     * Génère le participant_token :
     * HMAC-SHA256(session_id|participant_id|device_id, JWT_SECRET)
     */
    public function generateParticipantToken(int $sessionId, int $participantId, string $deviceId): string
    {
        $secret  = defined('JWT_SECRET') ? JWT_SECRET : '';
        $payload = "{$sessionId}|{$participantId}|{$deviceId}";
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Calcule les points gagnés selon la formule :
     * floor(points * max(0, 1 - elapsed_ms / (time_limit_sec * 1000)))
     */
    public function calculatePoints(int $basePoints, int $elapsedMs, int $timeLimitSec): int
    {
        $timeWindow = $timeLimitSec * 1000;
        if ($timeWindow <= 0) {
            return $basePoints;
        }
        $ratio = max(0.0, 1.0 - ($elapsedMs / $timeWindow));
        return (int) floor($basePoints * $ratio);
    }

    /**
     * Met à jour les rangs de tous les participants d'une session.
     */
    public function updateRankings(int $sessionId): void
    {
        (new Participant())->updateRanks($sessionId);
    }

    // -----------------------------------------------------------------------
    private function randomCode(): string
    {
        $chars  = self::CODE_CHARS;
        $max    = strlen($chars) - 1;
        $result = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }
}
