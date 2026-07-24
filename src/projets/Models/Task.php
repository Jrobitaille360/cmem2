<?php

namespace Projets\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Model Task — tâches de projet, stockées dans `calendar_todos` (extension §4)
 * + `task_dependencies` (plusieurs-à-plusieurs, §1.2).
 *
 * Convertit entre les colonnes DB (snake_case) et le contrat de tâche §6
 * (camelCase) : id, title, description, status, priority, percentComplete,
 * dtstart, due, allDay, completedAt, createdAt, updatedAt, sequence,
 * parentId, dependsOn[], assignee, url, categories[], rappelMinutesAvant.
 */
class Task extends BaseModel
{
    protected $table = 'calendar_todos';

    public $id;

    public function create() { throw new \RuntimeException('Utiliser createTask()'); }
    public function update() { throw new \RuntimeException('Utiliser updateTask()'); }

    // ---------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------

    public function findRawById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare('SELECT * FROM calendar_todos WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findTaskById(int $id): ?array
    {
        $row = $this->findRawById($id);
        if (!$row) { return null; }
        return $this->rowToContract($row, $this->findDependencies($id));
    }

    /** @return array<int,array> tâches du projet au format contrat §6 */
    public function findByProject(int $projectId): array
    {
        $stmt = $this->getDb()->prepare(
            'SELECT * FROM calendar_todos WHERE project_id = ? AND deleted_at IS NULL ORDER BY id ASC'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ids = array_column($rows, 'id');
        $depsByTask = $this->findDependenciesForTasks($ids);

        return array_map(
            fn($row) => $this->rowToContract($row, $depsByTask[$row['id']] ?? []),
            $rows
        );
    }

    public function taskExistsInProject(int $taskId, int $projectId): bool
    {
        $stmt = $this->getDb()->prepare(
            'SELECT 1 FROM calendar_todos WHERE id = ? AND project_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$taskId, $projectId]);
        return (bool) $stmt->fetchColumn();
    }

    private function findDependencies(int $taskId): array
    {
        $stmt = $this->getDb()->prepare(
            'SELECT depends_on_id, type, lag_days FROM task_dependencies WHERE task_id = ?'
        );
        $stmt->execute([$taskId]);
        return array_map(
            fn($r) => ['taskId' => (int) $r['depends_on_id'], 'type' => $r['type'], 'lagDays' => (int) $r['lag_days']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @return array<int,array> map taskId -> dependsOn[] */
    private function findDependenciesForTasks(array $taskIds): array
    {
        if (empty($taskIds)) { return []; }
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->getDb()->prepare(
            "SELECT task_id, depends_on_id, type, lag_days FROM task_dependencies WHERE task_id IN ({$in})"
        );
        $stmt->execute($taskIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['task_id']][] = [
                'taskId'  => (int) $r['depends_on_id'],
                'type'    => $r['type'],
                'lagDays' => (int) $r['lag_days'],
            ];
        }
        return $map;
    }

    // ---------------------------------------------------------------
    // Écriture
    // ---------------------------------------------------------------

    /**
     * @param array $data contrat §6 (title requis) + calendarId/userId internes
     * @return int id de la tâche créée
     */
    public function createTask(int $projectId, int $calendarId, int $userId, array $data): int
    {
        $uid = 'task-' . bin2hex(random_bytes(12)) . '@cmem.journauxdebord.com';
        $stmt = $this->getDb()->prepare(
            'INSERT INTO calendar_todos
                (calendar_id, user_id, project_id, parent_id, uid, title, description,
                 dtstart, due, all_day, status, priority, percent_complete, assignee,
                 url, categories, remind_minutes_before, sequence, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $calendarId,
            $userId,
            $projectId,
            $data['parentId'] ?? null,
            $uid,
            $data['title'],
            $data['description'] ?? null,
            $data['dtstart'] ?? null,
            $data['due'] ?? null,
            !empty($data['allDay']) ? 1 : 0,
            $data['status'] ?? 'NEEDS-ACTION',
            (int) ($data['priority'] ?? 0),
            (int) ($data['percentComplete'] ?? 0),
            $data['assignee'] ?? null,
            $data['url'] ?? null,
            isset($data['categories']) ? json_encode(array_values($data['categories'])) : null,
            $data['rappelMinutesAvant'] ?? null,
            (int) ($data['sequence'] ?? 0),
        ]);
        $id = (int) $this->getDb()->lastInsertId();

        if (!empty($data['dependsOn'])) {
            $this->replaceDependencies($id, $data['dependsOn']);
        }
        return $id;
    }

    /** @param array $data champs contrat §6 présents à modifier */
    public function updateTask(int $id, array $data): bool
    {
        $colMap = [
            'title'              => 'title',
            'description'        => 'description',
            'dtstart'            => 'dtstart',
            'due'                => 'due',
            'status'             => 'status',
            'priority'           => 'priority',
            'assignee'           => 'assignee',
            'url'                => 'url',
            'sequence'           => 'sequence',
        ];
        $sets = []; $params = [];
        foreach ($colMap as $field => $col) {
            if (array_key_exists($field, $data)) {
                $sets[]   = "{$col} = ?";
                $params[] = $data[$field];
            }
        }
        if (array_key_exists('percentComplete', $data)) {
            $sets[] = 'percent_complete = ?';
            $params[] = (int) $data['percentComplete'];
        }
        if (array_key_exists('allDay', $data)) {
            $sets[] = 'all_day = ?';
            $params[] = !empty($data['allDay']) ? 1 : 0;
        }
        if (array_key_exists('categories', $data)) {
            $sets[] = 'categories = ?';
            $params[] = $data['categories'] === null ? null : json_encode(array_values($data['categories']));
        }
        if (array_key_exists('rappelMinutesAvant', $data)) {
            $sets[] = 'remind_minutes_before = ?';
            $params[] = $data['rappelMinutesAvant'];
        }
        if (array_key_exists('parentId', $data)) {
            $sets[] = 'parent_id = ?';
            $params[] = $data['parentId'];
        }
        if (array_key_exists('completedAt', $data)) {
            $sets[] = 'completed = ?';
            $params[] = $data['completedAt'];
        }

        if (!empty($sets)) {
            $sets[]   = 'updated_at = NOW()';
            $params[] = $id;
            $sql = 'UPDATE calendar_todos SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
            $stmt = $this->getDb()->prepare($sql);
            $stmt->execute($params);
        }

        if (array_key_exists('dependsOn', $data)) {
            $this->replaceDependencies($id, $data['dependsOn'] ?? []);
        }
        return true;
    }

    public function setParent(int $id, ?int $parentId): void
    {
        $stmt = $this->getDb()->prepare('UPDATE calendar_todos SET parent_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$parentId, $id]);
    }

    public function replaceDependencies(int $taskId, array $deps): void
    {
        $this->getDb()->prepare('DELETE FROM task_dependencies WHERE task_id = ?')->execute([$taskId]);
        if (empty($deps)) { return; }
        $stmt = $this->getDb()->prepare(
            'INSERT INTO task_dependencies (task_id, depends_on_id, type, lag_days) VALUES (?, ?, ?, ?)'
        );
        foreach ($deps as $dep) {
            $stmt->execute([
                $taskId,
                (int) $dep['taskId'],
                strtoupper($dep['type'] ?? 'FS'),
                (int) ($dep['lagDays'] ?? 0),
            ]);
        }
    }

    public function softDeleteTask(int $id): bool
    {
        $stmt = $this->getDb()->prepare(
            'UPDATE calendar_todos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $ok = $stmt->rowCount() > 0;
        // Cascade : purge des liens croisés référençant cette tâche de projet (directive B2).
        \AuthGroups\Models\Link::purgeTodo($id);
        return $ok;
    }

    // ---------------------------------------------------------------
    // Conversion DB -> contrat §6
    // ---------------------------------------------------------------

    private function rowToContract(array $row, array $dependsOn): array
    {
        return [
            'id'                 => (int) $row['id'],
            'title'              => $row['title'],
            'description'        => $row['description'],
            'status'             => $row['status'],
            'priority'           => (int) $row['priority'],
            'percentComplete'    => (int) $row['percent_complete'],
            'dtstart'            => $row['dtstart'],
            'due'                => $row['due'],
            'allDay'             => (bool) $row['all_day'],
            'completedAt'        => $row['completed'],
            'createdAt'          => $row['created_at'],
            'updatedAt'          => $row['updated_at'],
            'sequence'           => (int) $row['sequence'],
            'parentId'           => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'dependsOn'          => $dependsOn,
            'assignee'           => $row['assignee'],
            'url'                => $row['url'],
            'categories'         => $row['categories'] !== null ? (json_decode($row['categories'], true) ?? []) : [],
            'rappelMinutesAvant' => $row['remind_minutes_before'] !== null ? (int) $row['remind_minutes_before'] : null,
        ];
    }
}
