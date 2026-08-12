<?php

namespace AuthGroups\Utils;

/**
 * Versioning optimiste (directive 20260812_113000) : garde If-Unmodified-Since sur les
 * écritures rejouées depuis une file offline. Header absent = aucune vérification
 * (rétrocompatibilité avec les appelants existants).
 */
class ConditionalRequest
{
    /**
     * @param string|null $dbUpdatedAt   Valeur brute MySQL ('Y-m-d H:i:s', UTC) de la ressource
     *                                   en base, avant écriture.
     * @param callable $fetchCurrentState Retourne l'état serveur courant complet (mêmes clés
     *                                    que le GET) — appelé seulement en cas de conflit.
     */
    public static function enforce(?string $dbUpdatedAt, callable $fetchCurrentState): void
    {
        $header = $_SERVER['HTTP_IF_UNMODIFIED_SINCE'] ?? null;
        if ($header === null || $header === '' || $dbUpdatedAt === null) {
            return;
        }

        $headerTs = strtotime($header);
        // $dbUpdatedAt n'a pas de suffixe de fuseau : forcer UTC (session DB en UTC).
        $dbTs = strtotime($dbUpdatedAt . ' UTC');

        if ($headerTs !== false && $dbTs !== false && $headerTs === $dbTs) {
            return;
        }

        Response::error(
            'Conflit de version : la ressource a été modifiée entretemps',
            null,
            409,
            ['current' => $fetchCurrentState()]
        );
    }
}
