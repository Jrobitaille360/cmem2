<?php

namespace Access\Controllers;

use Access\Services\AccessService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;

class AccessController
{
    private const VALID_PLATFORMS = ['android', 'web', 'windows'];

    public function getStatus(array $user): void
    {
        LoggingMiddleware::logEntry();

        $appId    = trim($_GET['app_id'] ?? '');
        $platform = trim($_GET['platform'] ?? '');

        if ($appId === '') {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', ['app_id' => 'Paramètre manquant'], 422);
            return;
        }

        if ($platform !== '' && !\in_array($platform, self::VALID_PLATFORMS, true)) {
            LoggingMiddleware::logExit(422);
            Response::error(
                'platform invalide',
                ['platform' => 'Valeurs acceptées : ' . implode(', ', self::VALID_PLATFORMS)],
                422
            );
            return;
        }

        $userId = (int) $user['user_id'];
        $result = AccessService::getMatrix($userId, $appId);
        $matrix = $result['matrix'];

        if ($platform !== '') {
            $isPremium = $matrix[$platform];
            LoggingMiddleware::logExit(200);
            Response::success('Accès vérifié', [
                'is_premium' => $isPremium,
                'platform'   => $platform,
                'sources'    => $result['sources'],
            ]);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Accès vérifié', [
            'is_premium' => $matrix['android'] || $matrix['web'] || $matrix['windows'],
            'platforms'  => $matrix,
            'sources'    => $result['sources'],
        ]);
    }
}
