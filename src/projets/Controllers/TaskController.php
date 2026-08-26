<?php

namespace Projets\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Projets\Models\Project;
use Projets\Models\Task;
use Projets\Services\GraphValidator;

class TaskController
{
    private Task $model;
    private Project $projectModel;

    public function __construct()
    {
        $this->model        = new Task();
        $this->projectModel = new Project();
    }

    /** Charge le projet propriétaire d'une tâche et vérifie l'accès. Envoie l'erreur et retourne null si KO. */
    private function ownedProjectForTask(array $user, array $taskRow): ?array
    {
        $projectId = $taskRow['project_id'] ?? null;
        if ($projectId === null) {
            Response::error('Tâche hors projet', null, 404);
            return null;
        }
        $project = $this->projectModel->findProjectById((int) $projectId);
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

    private function ownedProjectOrFail(array $user, int $projectId): ?array
    {
        $project = $this->projectModel->findProjectById($projectId);
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

    /**
     * Valide parentId / dependsOn[].taskId : doivent exister et appartenir au même projet.
     * @return string|null message d'erreur, ou null si OK
     */
    private function validateRefs(int $projectId, array $data): ?string
    {
        if (!empty($data['parentId']) && !$this->model->taskExistsInProject((int) $data['parentId'], $projectId)) {
            return 'parentId inexistant ou hors-projet';
        }
        foreach ($data['dependsOn'] ?? [] as $dep) {
            if (!isset($dep['taskId']) || !$this->model->taskExistsInProject((int) $dep['taskId'], $projectId)) {
                return 'dependsOn[].taskId inexistant ou hors-projet';
            }
        }
        return null;
    }

    private function pdo(): \PDO
    {
        require_once dirname(__DIR__, 3) . '/src/auth_groups/database.php';
        return \Database::getInstance()->getConnection();
    }

    // ---------------------------------------------------------------
    // GET /projets/projects/{id}/tasks
    // ---------------------------------------------------------------
    public function list(array $user, int $projectId): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $projectId);
        if (!$project) { return; }
        $tasks = $this->model->findByProject($projectId);
        Response::success('Tâches récupérées', ['tasks' => $tasks, 'count' => count($tasks)]);
    }

    // ---------------------------------------------------------------
    // POST /projets/projects/{id}/tasks
    // ---------------------------------------------------------------
    public function create(array $user, int $projectId): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->ownedProjectOrFail($user, $projectId);
        if (!$project) { return; }

        $data = Response::getRequestParams();
        if (empty($data['title'])) {
            Response::error('title requis', null, 422);
            return;
        }
        if ($err = $this->validateRefs($projectId, $data)) {
            Response::error($err, null, 422);
            return;
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->model->createTask($projectId, (int) $project['calendar_id'], (int) $user['user_id'], $data);
            (new GraphValidator())->assertAcyclique($this->model->findByProject($projectId));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::error('Création rejetée : ' . $e->getMessage(), null, 422);
            return;
        }

        Response::success('Tâche créée', ['task' => $this->model->findTaskById($id)], 201);
    }

    // ---------------------------------------------------------------
    // GET /projets/tasks/{id}
    // ---------------------------------------------------------------
    public function show(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $row = $this->model->findRawById($id);
        if (!$row) {
            Response::error('Tâche non trouvée', null, 404);
            return;
        }
        if (!$this->ownedProjectForTask($user, $row)) { return; }
        Response::success('Tâche récupérée', ['task' => $this->model->findTaskById($id)]);
    }

    // ---------------------------------------------------------------
    // PATCH /projets/tasks/{id}
    // ---------------------------------------------------------------
    public function update(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $row = $this->model->findRawById($id);
        if (!$row) {
            Response::error('Tâche non trouvée', null, 404);
            return;
        }
        $project = $this->ownedProjectForTask($user, $row);
        if (!$project) { return; }

        \AuthGroups\Utils\ConditionalRequest::enforce(
            $row['updated_at'] ?? null,
            fn() => ['task' => $this->model->findTaskById($id)]
        );

        $data = Response::getRequestParams();
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            Response::error('title ne peut pas être vide', null, 422);
            return;
        }
        if ($err = $this->validateRefs((int) $project['id'], $data)) {
            Response::error($err, null, 422);
            return;
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $this->model->updateTask($id, $data);
            (new GraphValidator())->assertAcyclique($this->model->findByProject((int) $project['id']));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::error('Mise à jour rejetée : ' . $e->getMessage(), null, 422);
            return;
        }

        Response::success('Tâche mise à jour', ['task' => $this->model->findTaskById($id)]);
    }

    // ---------------------------------------------------------------
    // DELETE /projets/tasks/{id}
    // ---------------------------------------------------------------
    public function delete(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $row = $this->model->findRawById($id);
        if (!$row) {
            Response::error('Tâche non trouvée', null, 404);
            return;
        }
        $project = $this->ownedProjectForTask($user, $row);
        if (!$project) { return; }

        $this->model->softDeleteTask($id);
        Response::success('Tâche supprimée', null, 204);
    }

    // ---------------------------------------------------------------
    // GET /projets/projects/{id}/tasks/deleted — corbeille du projet
    // ---------------------------------------------------------------
    public function listDeleted(array $user, int $projectId): void
    {
        LoggingMiddleware::logEntry();
        $project = $this->projectModel->findProjectById($projectId);
        if (!$project) {
            Response::error('Projet non trouvé', null, 404);
            return;
        }
        if ((int) $project['user_id'] !== (int) $user['user_id']) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $pagination = Response::getPaginationParams();
        $tasks = $this->model->getDeletedByProject($projectId, $pagination['page'], $pagination['limit']);
        Response::success('Tâches supprimées récupérées', [
            'tasks' => $tasks,
            'count' => count($tasks),
            'page'  => $pagination['page'],
            'limit' => $pagination['limit'],
        ]);
    }

    // ---------------------------------------------------------------
    // POST /projets/tasks/{id}/restore
    // ---------------------------------------------------------------
    public function restore(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();

        $row = $this->model->findRawByIdAnyState($id);
        if (!$row) {
            Response::error('Tâche non trouvée', null, 404);
            return;
        }
        $project = $this->ownedProjectForTask($user, $row);
        if (!$project) { return; }

        if (empty($row['deleted_at'])) {
            Response::error('Cette tâche n\'est pas supprimée', null, 404);
            return;
        }
        if (strtotime($row['deleted_at']) < strtotime('-' . Task::RESTORE_RETENTION_DAYS . ' days')) {
            Response::error('Fenêtre de restauration expirée', null, 404);
            return;
        }

        $this->model->restoreTask($id);
        Response::success('Tâche restaurée avec succès', ['task' => $this->model->findTaskById($id)]);
    }
}
