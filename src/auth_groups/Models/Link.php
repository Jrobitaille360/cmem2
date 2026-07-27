<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Model Link — table `links`
 *
 * Liens croisés polymorphes entre entités
 * (event|task|journal|project|project_task|file|contact|interaction|opportunite).
 * Directive cmem_web B2 (20260722_141845), étendue à la GED par 20260724_154619 (Phase G-E).
 * Voir docs/links/GUIDE.md.
 *
 * Portée : owner-strict. Un lien n'est permis qu'entre entités dont le propriétaire == owner_id.
 * Un seul enregistrement (src → dst) par paire logique ; le sens inverse est le même lien.
 */
class Link extends BaseModel
{
    protected $table = 'links';

    /**
     * Types liables → table, expression du titre, colonne propriétaire, colonne de suppression,
     * filtre project_id éventuel.
     *
     * Les piliers récents (contacts) utilisent des colonnes françaises : `supprime_le` au lieu de
     * `deleted_at`, d'où les clés `owner` et `deleted` explicites.
     */
    private const ENTITY_MAP = [
        'event'        => ['table' => 'calendar_events',   'title' => 'title',
                           'owner' => 'user_id',     'deleted' => 'deleted_at',  'project' => null],
        'task'         => ['table' => 'calendar_todos',    'title' => 'title',
                           'owner' => 'user_id',     'deleted' => 'deleted_at',  'project' => 'null'],
        'journal'      => ['table' => 'calendar_journals', 'title' => 'summary',
                           'owner' => 'user_id',     'deleted' => 'deleted_at',  'project' => null],
        'project'      => ['table' => 'projects',          'title' => 'name',
                           'owner' => 'user_id',     'deleted' => 'deleted_at',  'project' => null],
        'project_task' => ['table' => 'calendar_todos',    'title' => 'title',
                           'owner' => 'user_id',     'deleted' => 'deleted_at',  'project' => 'notnull'],
        // --- GED (Phase G-E) ---
        'file'         => ['table' => 'files',             'title' => 'original_name',
                           'owner' => 'uploaded_by', 'deleted' => 'deleted_at',  'project' => null],
        'contact'      => ['table' => 'contacts',
                           'title' => "COALESCE(NULLIF(TRIM(CONCAT(prenom, ' ', nom)), ''), organisation)",
                           'owner' => 'user_id',     'deleted' => 'supprime_le', 'project' => null],
        'interaction'  => ['table' => 'interaction',
                           'title' => "COALESCE(NULLIF(resume, ''), sujet)",
                           'owner' => 'user_id',     'deleted' => 'supprime_le', 'project' => null],
        'opportunite'  => ['table' => 'opportunite',       'title' => 'titre',
                           'owner' => 'user_id',     'deleted' => 'supprime_le', 'project' => null],
    ];

    public static function validTypes(): array
    {
        return array_keys(self::ENTITY_MAP);
    }

    public static function isValidType(string $type): bool
    {
        return isset(self::ENTITY_MAP[$type]);
    }

    /**
     * Résout une entité owner-scoped. Retourne ['id','title'] ou null si inexistante,
     * supprimée, d'un autre usager, ou du mauvais sous-type (task vs project_task).
     */
    public function resolveEntity(int $ownerId, string $type, int $id): ?array
    {
        if (!isset(self::ENTITY_MAP[$type])) {
            return null;
        }
        $meta  = self::ENTITY_MAP[$type];
        $where = "id = ? AND {$meta['owner']} = ? AND {$meta['deleted']} IS NULL";
        if ($meta['project'] === 'null') {
            $where .= " AND project_id IS NULL";
        } elseif ($meta['project'] === 'notnull') {
            $where .= " AND project_id IS NOT NULL";
        }
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT id, {$meta['title']} AS title FROM {$meta['table']} WHERE {$where} LIMIT 1"
            );
            $stmt->execute([$id, $ownerId]);
        } catch (\PDOException $e) {
            // Table absente (migration non encore appliquée) : l'entité est simplement non résolue.
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Requis par BaseModel — les liens sont immuables, création via insert(). */
    public function create()
    {
        return false;
    }

    /** Requis par BaseModel — les liens sont immuables (pas de mise à jour). */
    public function update()
    {
        return false;
    }

    public function getLinkById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM links WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cherche un lien existant dans l'un ou l'autre sens (dédup logique bidirectionnelle).
     */
    public function findLogical(string $appId, int $ownerId, string $srcType, int $srcId, string $dstType, int $dstId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM links
             WHERE app_id = ? AND owner_id = ?
               AND (
                    (src_type = ? AND src_id = ? AND dst_type = ? AND dst_id = ?)
                 OR (src_type = ? AND src_id = ? AND dst_type = ? AND dst_id = ?)
               )
             LIMIT 1"
        );
        $stmt->execute([
            $appId, $ownerId,
            $srcType, $srcId, $dstType, $dstId,
            $dstType, $dstId, $srcType, $srcId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(string $appId, int $ownerId, string $srcType, int $srcId, string $dstType, int $dstId): array
    {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO links (app_id, owner_id, src_type, src_id, dst_type, dst_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$appId, $ownerId, $srcType, $srcId, $dstType, $dstId]);
        return $this->getLinkById((int) $this->getDb()->lastInsertId());
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->getDb()->prepare("DELETE FROM links WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Liens entrants + sortants d'une entité, enrichis du titre de la cible.
     * direction = 'outgoing' quand l'entité est src, 'incoming' quand elle est dst.
     */
    public function getForEntity(string $appId, int $ownerId, string $type, int $id): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM links
             WHERE app_id = ? AND owner_id = ?
               AND ( (src_type = ? AND src_id = ?) OR (dst_type = ? AND dst_id = ?) )
             ORDER BY created_at DESC"
        );
        $stmt->execute([$appId, $ownerId, $type, $id, $type, $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $isSrc = ($r['src_type'] === $type && (int) $r['src_id'] === $id);
            $otherType = $isSrc ? $r['dst_type'] : $r['src_type'];
            $otherId   = (int) ($isSrc ? $r['dst_id'] : $r['src_id']);
            $target    = $this->resolveEntity($ownerId, $otherType, $otherId);
            $out[] = [
                'id'          => (int) $r['id'],
                'direction'   => $isSrc ? 'outgoing' : 'incoming',
                'other_type'  => $otherType,
                'other_id'    => $otherId,
                'other_title' => $target['title'] ?? null,
                'created_at'  => $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Point d'entrée statique sûr pour la cascade de purge, appelé depuis les modèles
     * d'entités (ics/projets). N'interrompt jamais la suppression de l'entité même si la
     * table `links` est absente (migration non encore appliquée) ou en cas d'erreur SQL.
     *
     * Pour un calendar_todos, passer les deux types 'task' et 'project_task' (l'id peut
     * avoir été lié sous l'un ou l'autre selon project_id).
     */
    public static function purge(string $type, int $id): void
    {
        try {
            (new self())->purgeForEntity($type, $id);
        } catch (\Throwable $e) {
            // Silencieux : la suppression de l'entité prime sur la purge des liens.
        }
    }

    /**
     * Purge d'une tâche calendar_todos (couvre 'task' et 'project_task').
     */
    public static function purgeTodo(int $id): void
    {
        self::purge('task', $id);
        self::purge('project_task', $id);
    }

    /**
     * Cascade de purge : supprime tous les liens référençant l'entité (src OU dst),
     * tous tenants confondus. Appelé lors de la suppression (soft/hard) d'une entité.
     * Retourne le nombre de liens purgés.
     */
    public function purgeForEntity(string $type, int $id): int
    {
        $stmt = $this->getDb()->prepare(
            "DELETE FROM links
             WHERE (src_type = ? AND src_id = ?) OR (dst_type = ? AND dst_id = ?)"
        );
        $stmt->execute([$type, $id, $type, $id]);
        return $stmt->rowCount();
    }
}
