<?php

namespace AuthGroups\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\Link;
use AuthGroups\Utils\Response;

/**
 * LinkController — liens croisés polymorphes inter-entités (directive cmem_web B2).
 *
 *   POST   /links              → créer un lien
 *   DELETE /links/{id}         → supprimer un lien (owner-scoped)
 *   GET    /links?type=&id=    → liens entrants + sortants d'une entité
 *
 * Portée owner-strict : toutes les extrémités doivent appartenir à l'usager courant.
 * app_id multi-tenant (défaut serveur 'puzzle').
 */
class LinkController
{
    private const DEFAULT_APP_ID = 'puzzle';

    public function create(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId   = (string) ($input['app_id'] ?? self::DEFAULT_APP_ID);
        $srcType = (string) ($input['src_type'] ?? '');
        $dstType = (string) ($input['dst_type'] ?? '');
        $srcId   = isset($input['src_id']) ? (int) $input['src_id'] : 0;
        $dstId   = isset($input['dst_id']) ? (int) $input['dst_id'] : 0;
        $ownerId = (int) $user['user_id'];

        if (!Link::isValidType($srcType) || !Link::isValidType($dstType)) {
            LoggingMiddleware::logExit(422);
            Response::error('src_type et dst_type doivent être parmi : ' . implode(', ', Link::validTypes()), null, 422);
            return;
        }
        if ($srcId <= 0 || $dstId <= 0) {
            LoggingMiddleware::logExit(422);
            Response::error('src_id et dst_id sont requis', null, 422);
            return;
        }
        if ($srcType === $dstType && $srcId === $dstId) {
            LoggingMiddleware::logExit(422);
            Response::error('Une entité ne peut être liée à elle-même', null, 422);
            return;
        }

        $model = new Link();

        // Validation des extrémités : owner-scoped (404 si inexistante / non visible / autre usager).
        if (!$model->resolveEntity($ownerId, $srcType, $srcId)) {
            LoggingMiddleware::logExit(404);
            Response::error('Entité source introuvable ou non visible', null, 404);
            return;
        }
        if (!$model->resolveEntity($ownerId, $dstType, $dstId)) {
            LoggingMiddleware::logExit(404);
            Response::error('Entité cible introuvable ou non visible', null, 404);
            return;
        }

        // Dédup logique bidirectionnelle : doublon exact OU sens inverse → idempotent.
        $existing = $model->findLogical($appId, $ownerId, $srcType, $srcId, $dstType, $dstId);
        if ($existing) {
            LoggingMiddleware::logExit(200);
            Response::success('Lien déjà existant', $this->serialize($existing), 200);
            return;
        }

        $link = $model->insert($appId, $ownerId, $srcType, $srcId, $dstType, $dstId);

        LoggingMiddleware::logExit(201);
        Response::success('Lien créé', $this->serialize($link), 201);
    }

    public function delete(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $model = new Link();
        $link  = $model->getLinkById($id);

        if (!$link) {
            LoggingMiddleware::logExit(404);
            Response::error('Lien introuvable', null, 404);
            return;
        }
        if ((int) $link['owner_id'] !== (int) $user['user_id']) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès refusé', null, 403);
            return;
        }

        $model->deleteById($id);
        LoggingMiddleware::logExit(200);
        Response::success('Lien supprimé');
    }

    public function listForEntity(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId   = (string) ($input['app_id'] ?? self::DEFAULT_APP_ID);
        $type    = (string) ($input['type'] ?? '');
        $id      = isset($input['id']) ? (int) $input['id'] : 0;
        $ownerId = (int) $user['user_id'];

        if (!Link::isValidType($type)) {
            LoggingMiddleware::logExit(422);
            Response::error('type doit être parmi : ' . implode(', ', Link::validTypes()), null, 422);
            return;
        }
        if ($id <= 0) {
            LoggingMiddleware::logExit(422);
            Response::error('id est requis', null, 422);
            return;
        }

        $model = new Link();

        // L'entité interrogée doit être visible par l'usager (ne pas divulguer les liens d'autrui).
        if (!$model->resolveEntity($ownerId, $type, $id)) {
            LoggingMiddleware::logExit(404);
            Response::error('Entité introuvable ou non visible', null, 404);
            return;
        }

        $links = $model->getForEntity($appId, $ownerId, $type, $id);
        LoggingMiddleware::logExit(200);
        Response::success('Liens récupérés', $links);
    }

    private function serialize(array $link): array
    {
        return [
            'id'         => (int) $link['id'],
            'app_id'     => $link['app_id'],
            'src_type'   => $link['src_type'],
            'src_id'     => (int) $link['src_id'],
            'dst_type'   => $link['dst_type'],
            'dst_id'     => (int) $link['dst_id'],
            'created_at' => $link['created_at'],
        ];
    }
}
