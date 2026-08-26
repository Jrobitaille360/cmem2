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

    /** Fenêtre de restauration après soft-delete (jours) — aligné sur CalendarTodo::RESTORE_RETENTION_DAYS. */
    public const RESTORE_RETENTION_DAYS = 30;

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
     * Soft-delete — le projet, ses tâches (calendar_todos.project_id) et son calendrier
     * caché restent intacts en base ; seul le projet devient invisible (deleted_at),
     * jusqu'à restauration ou purge ultérieure. Les tâches ne sont pas touchées : elles
     * réapparaissent telles quelles si le projet est restauré.
     */
    public function deleteProject(int $id): bool
    {
        $stmt = $this->getDb()->prepare(
            'UPDATE projects SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            \AuthGroups\Models\Link::purge('project', $id);
        }
        return $ok;
    }

    /** Projet dans n'importe quel état (actif ou soft-supprimé) — sert à la restauration. */
    public function findRawByIdAnyState(int $id): ?array
    {
        $stmt = $this->getDb()->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Projets soft-supprimés du propriétaire, dans la fenêtre de restauration.
     * @return array<int,array>
     */
    public function getDeletedByUser(int $userId, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        $stmt = $this->getDb()->prepare(
            'SELECT * FROM projects
              WHERE user_id = ? AND deleted_at IS NOT NULL
                AND deleted_at >= NOW() - INTERVAL ' . self::RESTORE_RETENTION_DAYS . ' DAY
              ORDER BY deleted_at DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Annule le soft-delete d'un projet. */
    public function restoreProject(int $id): bool
    {
        $stmt = $this->getDb()->prepare(
            'UPDATE projects SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
