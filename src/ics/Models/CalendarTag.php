<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Modèle des étiquettes (tags) scopées par calendrier — partagées entre membres.
 * Table : calendar_tags
 * Directive : 20260715_090000_cmem_web_vers_cmem2_API__tags-par-calendrier.md
 */
class CalendarTag extends BaseModel
{
    protected $table = 'calendar_tags';

    public $id;
    public $calendarId;
    public $name;
    public $color;

    public function __construct()
    {
        parent::__construct();
    }

    public function getByCalendarId(int $calendarId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table} WHERE calendar_id = ? ORDER BY name ASC
        ");
        $stmt->execute([$calendarId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function existsByName(int $calendarId, string $name): bool
    {
        $stmt = $this->getDb()->prepare("
            SELECT id FROM {$this->table} WHERE calendar_id = ? AND name = ?
        ");
        $stmt->execute([$calendarId, $name]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(): array
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO {$this->table} (calendar_id, name, color) VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->calendarId, $this->name, $this->color ?? null]);
        $this->id = (int) $this->getDb()->lastInsertId();
        return $this->getById($this->id);
    }

    public function update()
    {
        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table} SET name = ?, color = ? WHERE id = ?
        ");
        return $stmt->execute([$this->name, $this->color ?? null, $this->id]);
    }

    /**
     * Renomme un tag et propage la nouvelle valeur dans categories[] des
     * events/todos/journals du calendrier — transaction unique.
     */
    public function renameWithCascade(int $tagId, int $calendarId, ?string $newName, ?string $newColor): array
    {
        $tag = $this->getById($tagId);
        $oldName = $tag['name'];

        $this->getDb()->beginTransaction();
        try {
            $fields = [];
            $params = [];
            if ($newName !== null && $newName !== $oldName) {
                $fields[] = 'name = ?';
                $params[] = $newName;
            }
            if ($newColor !== null) {
                $fields[] = 'color = ?';
                $params[] = $newColor;
            }
            if (!empty($fields)) {
                $params[] = $tagId;
                $this->getDb()->prepare(
                    "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?"
                )->execute($params);
            }

            if ($newName !== null && $newName !== $oldName) {
                foreach (['calendar_events', 'calendar_todos', 'calendar_journals'] as $table) {
                    $this->replaceCategoryValue($table, $calendarId, $oldName, $newName);
                }
            }

            $this->getDb()->commit();
            return $this->getById($tagId);
        } catch (\Exception $e) {
            $this->getDb()->rollBack();
            throw $e;
        }
    }

    /**
     * Supprime un tag et retire sa valeur de categories[] des
     * events/todos/journals du calendrier — transaction unique.
     */
    public function deleteWithCascade(int $tagId, int $calendarId): void
    {
        $tag = $this->getById($tagId);
        $name = $tag['name'];

        $this->getDb()->beginTransaction();
        try {
            $this->getDb()->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$tagId]);

            foreach (['calendar_events', 'calendar_todos', 'calendar_journals'] as $table) {
                $this->removeCategoryValue($table, $calendarId, $name);
            }

            $this->getDb()->commit();
        } catch (\Exception $e) {
            $this->getDb()->rollBack();
            throw $e;
        }
    }

    private function replaceCategoryValue(string $table, int $calendarId, string $oldName, string $newName): void
    {
        $stmt = $this->getDb()->prepare(
            "SELECT id, categories FROM {$table} WHERE calendar_id = ? AND categories IS NOT NULL AND deleted_at IS NULL"
        );
        $stmt->execute([$calendarId]);
        $update = $this->getDb()->prepare("UPDATE {$table} SET categories = ? WHERE id = ?");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $categories = json_decode($row['categories'], true) ?? [];
            if (!in_array($oldName, $categories, true)) {
                continue;
            }
            $categories = array_values(array_map(
                fn($c) => $c === $oldName ? $newName : $c,
                $categories
            ));
            $update->execute([json_encode($categories), $row['id']]);
        }
    }

    private function removeCategoryValue(string $table, int $calendarId, string $name): void
    {
        $stmt = $this->getDb()->prepare(
            "SELECT id, categories FROM {$table} WHERE calendar_id = ? AND categories IS NOT NULL AND deleted_at IS NULL"
        );
        $stmt->execute([$calendarId]);
        $update = $this->getDb()->prepare("UPDATE {$table} SET categories = ? WHERE id = ?");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $categories = json_decode($row['categories'], true) ?? [];
            if (!in_array($name, $categories, true)) {
                continue;
            }
            $categories = array_values(array_filter($categories, fn($c) => $c !== $name));
            $update->execute([json_encode($categories), $row['id']]);
        }
    }
}
