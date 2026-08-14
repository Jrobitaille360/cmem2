<?php

namespace Projets\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Projets\Ical\VEventSerializer;
use Projets\Models\Project;
use Projets\Models\Task;
use Projets\Services\GraphValidator;
use Projets\Services\JsonRoundTrip;

class ProjectController
{
    private Project $model;

    public function __construct()
    {
        $this->model = new Project();
    }

    /** Retourne le projet si trouvé + appartient à l'utilisateur, sinon envoie l'erreur et retourne null. */
    private function ownedProjectOrFail(array $user, int $id): ?array
    {
        $project = $this->model->findProjectById($id);
        if (!$project) {
            Response::error('Projet non trouvé', null, 404);
            return null;
        }
        if ((int) $project['user_id'] !== (int) $user['user_id']) {
            Response::error('Accès non autorisé', null, 403);
            return null;
        }
        return $project;
    }

    private function toContract(array $project): array
    {
        return [
            'id'        => (int) $project['id'],
            'name'      => $project['name'],
            'createdAt' => $project['created_at'],
            'updatedAt' => $project['updated_at'],
        ];
    }

    // ---------------------------------------------------------------
    // GET /projets/projects
    // ---------------------------------------------------------------
    public function list(array $user): void
    {
        LoggingMiddleware::logEntry();
        $projects = $this->model->findByUser((int) $user['user_id']);
        Response::success('Projets récupérés', [
            'projects' => array_map([$this, 'toContract'], $projects),
        ]);
    }

    // ---------------------------------------------------------------
    // POST /projets/projects
    // ---------------------------------------------------------------
    public function create(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p = Response::getRequestParams();
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            Response::error('name requis', null, 422);
            return;
        }
        $id = $this->model->createProject((int) $user['user_id'], $name);
        $project = $this->model->findProjectById($id);
        Response::success('Projet créé', ['project' => $this->toContract($project)], 201);
    }

    // ---------------------------------------------------------------
    // GET /projets/projects/{id}
    // ---------------------------------------------------------------
    public function show(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }
        Response::success('Projet récupéré', ['project' => $this->toContract($project)]);
    }

    // ---------------------------------------------------------------
    // PATCH /projets/projects/{id}
    // ---------------------------------------------------------------
    public function update(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }

        $p = Response::getRequestParams();
        if (!array_key_exists('name', $p) || trim((string) $p['name']) === '') {
            Response::error('name requis', null, 422);
            return;
        }
        $this->model->renameProject($id, trim((string) $p['name']));
        $updated = $this->model->findProjectById($id);
        Response::success('Projet mis à jour', ['project' => $this->toContract($updated)]);
    }

    // ---------------------------------------------------------------
    // DELETE /projets/projects/{id}
    // ---------------------------------------------------------------
    public function delete(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }
        $this->model->deleteProject($id);
        Response::success('Projet supprimé', null, 204);
    }

    // ---------------------------------------------------------------
    // GET /projets/projects/{id}/export.json
    // ---------------------------------------------------------------
    public function exportJson(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }

        $taskModel = new Task();
        $roundTrip = new JsonRoundTrip();
        $export = $roundTrip->export($this->toContract($project), $taskModel->findByProject($id));
        Response::success('Export JSON', $export);
    }

    // ---------------------------------------------------------------
    // POST /projets/projects/{id}/import.json — diff dry-run, n'écrit rien
    // ---------------------------------------------------------------
    public function importJsonDryRun(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }

        $payload = Response::getRequestParams();
        $taskModel = new Task();
        $roundTrip = new JsonRoundTrip();
        $tachesCmem2 = $taskModel->findByProject($id);

        try {
            $diff = $roundTrip->planifier($payload, $tachesCmem2);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), null, 422);
            return;
        }

        $existantsParId = [];
        foreach ($tachesCmem2 as $t) { $existantsParId[$t['id']] = $t; }
        $diff['aMettreAJour'] = array_map(
            fn($r) => [
                'id'     => (int) $r['id'],
                'champs' => $roundTrip->diffChamps($existantsParId[(int) $r['id']] ?? [], $r),
            ],
            $diff['aMettreAJour']
        );

        Response::success('Diff calculé', $diff);
    }

    // ---------------------------------------------------------------
    // POST /projets/projects/{id}/import.json/confirm — applique la fusion
    // ---------------------------------------------------------------
    public function importJsonConfirm(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }

        $payload   = Response::getRequestParams();
        $taskModel = new Task();
        $roundTrip = new JsonRoundTrip();

        try {
            $diff = $roundTrip->planifier($payload, $taskModel->findByProject($id));
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), null, 422);
            return;
        }

        require_once dirname(__DIR__, 3) . '/src/auth_groups/database.php';
        $pdo = \Database::getInstance()->getConnection();
        $pdo->beginTransaction();

        try {
            // Passe 1 — INSERT des nouvelles tâches (parent/deps posés à null pour l'instant)
            $tmpToReal = [];
            foreach ($diff['aCreer'] as $row) {
                $tmpId = $row['id'] ?? null;
                $newId = $taskModel->createTask($id, (int) $project['calendar_id'], (int) $user['user_id'], [
                    'title'              => $row['title'],
                    'description'        => $row['description'] ?? null,
                    'dtstart'            => $row['dtstart'] ?? null,
                    'due'                => $row['due'] ?? null,
                    'allDay'             => $row['allDay'] ?? false,
                    'status'             => $row['status'] ?? 'NEEDS-ACTION',
                    'priority'           => $row['priority'] ?? 0,
                    'percentComplete'    => $row['percentComplete'] ?? 0,
                    'assignee'           => $row['assignee'] ?? null,
                    'url'                => $row['url'] ?? null,
                    'categories'         => $row['categories'] ?? null,
                    'rappelMinutesAvant' => $row['rappelMinutesAvant'] ?? null,
                ]);
                if (is_string($tmpId)) { $tmpToReal[$tmpId] = $newId; }
            }

            // Passe 2 — mise à jour, en résolvant les références temporaires
            $resolve = function ($ref) use ($tmpToReal) {
                if ($ref === null) { return null; }
                if (is_string($ref) && isset($tmpToReal[$ref])) { return $tmpToReal[$ref]; }
                return (int) $ref;
            };

            foreach ($diff['aCreer'] as $row) {
                $tmpId = $row['id'] ?? null;
                $realId = is_string($tmpId) ? ($tmpToReal[$tmpId] ?? null) : null;
                if ($realId === null) { continue; }
                $deps = array_map(fn($d) => ['taskId' => $resolve($d['taskId']), 'type' => $d['type'] ?? 'FS', 'lagDays' => $d['lagDays'] ?? 0], $row['dependsOn'] ?? []);
                $taskModel->updateTask($realId, [
                    'parentId'  => $resolve($row['parentId'] ?? null),
                    'dependsOn' => $deps,
                ]);
            }

            foreach ($diff['aMettreAJour'] as $row) {
                $realId = (int) $row['id'];
                $deps = array_map(fn($d) => ['taskId' => $resolve($d['taskId']), 'type' => $d['type'] ?? 'FS', 'lagDays' => $d['lagDays'] ?? 0], $row['dependsOn'] ?? []);
                $update = $row;
                unset($update['id']);
                $update['parentId']  = $resolve($row['parentId'] ?? null);
                $update['dependsOn'] = $deps;
                $taskModel->updateTask($realId, $update);
            }

            // Validation arbre/DAG sur l'état final avant commit
            (new GraphValidator())->assertAcyclique($taskModel->findByProject($id));

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::error('Import rejeté : ' . $e->getMessage(), null, 422);
            return;
        }

        Response::success('Import appliqué', [
            'aCreer'       => count($diff['aCreer']),
            'aMettreAJour' => count($diff['aMettreAJour']),
            'orphelins'    => count($diff['orphelins']),
        ]);
    }

    // ---------------------------------------------------------------
    // GET /projets/projects/{id}/export.ics
    // ---------------------------------------------------------------
    public function exportIcs(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $id);
        if (!$project) { return; }

        $taskModel = new Task();
        $ics = (new VEventSerializer())->buildCalendar($taskModel->findByProject($id));
        Response::sendIcs($ics, 'projet-' . $id . '.ics');
    }
}
