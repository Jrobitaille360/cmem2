<?php

namespace Projets\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Projets\Controllers\ProjectController;
use Projets\Controllers\TaskController;

/**
 * ProjetsRouteHandler — routes /projets/*
 *
 *   GET    /projets/projects                              → list
 *   POST   /projets/projects                               → create
 *   GET    /projets/projects/{id}                          → show
 *   PATCH  /projets/projects/{id}                          → update
 *   DELETE /projets/projects/{id}                          → delete
 *   GET    /projets/projects/{id}/tasks                     → tasks list
 *   POST   /projets/projects/{id}/tasks                     → task create
 *   GET    /projets/projects/{id}/export.json                → export JSON
 *   POST   /projets/projects/{id}/import.json                → diff (dry-run)
 *   POST   /projets/projects/{id}/import.json/confirm        → écriture confirmée
 *   GET    /projets/projects/{id}/export.ics                 → export .ics VEVENT
 *   PATCH  /projets/tasks/{id}                               → task update
 *   DELETE /projets/tasks/{id}                               → task delete
 *
 * Toutes les routes exigent un JWT valide.
 */
class ProjetsRouteHandler extends BaseRouteHandler
{
    protected function getSupportedControllers(): array
    {
        return ['projets'];
    }

    protected function handleRoute(array $request): void
    {
        $user   = $request['user'];
        $method = $request['method'] ?? 'GET';
        $segs   = $request['segments'] ?? [];

        // segs[0] = 'projets'
        $s1 = $segs[1] ?? ''; // 'projects' | 'tasks'
        $s2 = $segs[2] ?? ''; // id
        $s3 = $segs[3] ?? ''; // 'tasks' | 'export.json' | 'import.json' | 'export.ics'
        $s4 = $segs[4] ?? ''; // 'confirm'

        // -------------------------------------------------
        // /projets/tasks/{id}
        // -------------------------------------------------
        if ($s1 === 'tasks') {
            if (!is_numeric($s2)) {
                Response::error('task id doit être numérique', null, 400);
                return;
            }
            $taskId = (int) $s2;
            match ($method) {
                'GET'    => (new TaskController())->show($user, $taskId),
                'PATCH'  => (new TaskController())->update($user, $taskId),
                'DELETE' => (new TaskController())->delete($user, $taskId),
                default  => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        if ($s1 !== 'projects') {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        // -------------------------------------------------
        // /projets/projects
        // -------------------------------------------------
        if ($s2 === '') {
            match ($method) {
                'GET'  => (new ProjectController())->list($user),
                'POST' => (new ProjectController())->create($user),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        if (!is_numeric($s2)) {
            Response::error('project id doit être numérique', null, 400);
            return;
        }
        $projectId = (int) $s2;

        // /projets/projects/{id}
        if ($s3 === '') {
            match ($method) {
                'GET'    => (new ProjectController())->show($user, $projectId),
                'PATCH'  => (new ProjectController())->update($user, $projectId),
                'DELETE' => (new ProjectController())->delete($user, $projectId),
                default  => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // /projets/projects/{id}/tasks
        if ($s3 === 'tasks') {
            match ($method) {
                'GET'  => (new TaskController())->list($user, $projectId),
                'POST' => (new TaskController())->create($user, $projectId),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // /projets/projects/{id}/export.json
        if ($s3 === 'export.json' && $method === 'GET') {
            (new ProjectController())->exportJson($user, $projectId);
            return;
        }

        // /projets/projects/{id}/import.json[/confirm]
        if ($s3 === 'import.json' && $method === 'POST') {
            if ($s4 === 'confirm') {
                (new ProjectController())->importJsonConfirm($user, $projectId);
            } else {
                (new ProjectController())->importJsonDryRun($user, $projectId);
            }
            return;
        }

        // /projets/projects/{id}/export.ics
        if ($s3 === 'export.ics' && $method === 'GET') {
            (new ProjectController())->exportIcs($user, $projectId);
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
