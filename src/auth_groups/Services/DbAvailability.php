<?php

namespace AuthGroups\Services;

use PDOException;
use Throwable;

/**
 * Distingue une panne de la base de données (serveur MySQL injoignable ou perdu en cours
 * de requête) d'une autre erreur — task cmem #532.
 *
 * Les crons idempotents (rappels : reminder_log) rattrapent leur travail au passage
 * suivant : une panne de base y est un avertissement, pas une erreur fatale.
 */
class DbAvailability
{
    /** Codes client MySQL : 2002 socket/connexion, 2003 hôte, 2006 gone away, 2013 lost connection. */
    private const CLIENT_CODES = [2002, 2003, 2006, 2013];

    /** Message levé par Database::connect() (suivi du détail PDO si APP_DEBUG). */
    private const CONNECT_MESSAGE = 'Erreur de connexion à la base de données';

    public static function isUnavailable(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (str_starts_with($cur->getMessage(), self::CONNECT_MESSAGE)) {
                return true;
            }
            if ($cur instanceof PDOException) {
                $code = (int) ($cur->errorInfo[1] ?? 0);
                if (in_array($code, self::CLIENT_CODES, true)) {
                    return true;
                }
                // PDOException sans errorInfo (échec de connexion) : code dans le message.
                if (preg_match('/SQLSTATE\[HY000\](?: \[|: General error: )(\d{4})\b/', $cur->getMessage(), $m)
                    && in_array((int) $m[1], self::CLIENT_CODES, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Rapport d'échec d'un cron : niveau de journal, message, contexte et ligne console.
     *
     * @return array{level: string, message: string, context: array, line: string}
     */
    public static function cronFailure(Throwable $e, string $job): array
    {
        $stamp = date('[Y-m-d H:i:s]');
        if (self::isUnavailable($e)) {
            return [
                'level'   => 'warning',
                'message' => "{$job} — base de données indisponible",
                'context' => ['error' => $e->getMessage()],
                'line'    => "{$stamp} Base de données indisponible — reprise au prochain passage : " . $e->getMessage(),
            ];
        }
        return [
            'level'   => 'error',
            'message' => "{$job} — erreur fatale",
            'context' => ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
            'line'    => "{$stamp} ERREUR FATALE : " . $e->getMessage(),
        ];
    }
}
