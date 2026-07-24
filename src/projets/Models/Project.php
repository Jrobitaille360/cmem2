<?php

namespace Projets\Models;

use AuthGroups\Models\BaseModel;
use ICS\Models\Calendar;
use PDO;

/**
 * Model Project — table `projects`
 *
 * Chaque projet provisionne un `calendar` caché 1:1 (jamais exposé dans l'UI
 * calendrier normale) pour satisfaire la contrainte calendar_todos.calendar_id
 * NOT NULL sans toucher au module ics existant.
 */
class Project extends BaseModel
{
    protected $table = 'projects';

    public $id;
    public $user_id;
    public $calendar_id;
    public $name;
    public $created_at;
    public $updated_at;
    public $deleted_at;

    public function create()
    {
        // Requis par BaseModel — non utilisé directement, voir createProject()
        return $this->createProject((int) $this->user_id, (string) $this->name);
    }

    public function update()
    {
        return $this->renameProject((int) $this->id, (string) $this->name);
    }

    /**
     * Crée un projet + son calendrier caché associé. Retourne l'id du projet.
     */
    public function createProject(int $userId, string $name): int
    {
        $cal = new Calendar();
        $cal->userId     = $userId;
        $cal->title      = $name;
        $cal->visibility = 'private';
        $created         = $cal->create();
        $calendarId      = (int) $created['id'];

        $stmt = $this->getDb()->prepare(
            'INSERT INTO projects (user_id, calendar_id, name, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$userId, $calendarId, $name]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function findProjectById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->getDb()->prepare(
            'SELECT * FROM projects WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function renameProject(int $id, string $name): bool
    {
        $stmt = $this->getDb()->prepare(
            'UPDATE projects SET name = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$name, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Suppression physique — cascade sur calendar_todos.project_id, task_dependencies
     * et le calendrier caché associé (FK ON DELETE CASCADE, §4).
     */
    public function deleteProject(int $id): bool
    {
        $project = $this->findProjectById($id);
        if (!$project) { return false; }

        // Cascade liens (directive B2) : la suppression du projet FK-cascade ses tâches
        // (calendar_todos.project_id) en DB, hors PHP — purger leurs liens ici, avant le DELETE.
        $taskStmt = $this->getDb()->prepare('SELECT id FROM calendar_todos WHERE project_id = ?');
        $taskStmt->execute([$id]);
        foreach ($taskStmt->fetchAll(PDO::FETCH_COLUMN) as $taskId) {
            \AuthGroups\Models\Link::purgeTodo((int) $taskId);
        }
        \AuthGroups\Models\Link::purge('project', $id);

        $stmt = $this->getDb()->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
