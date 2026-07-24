<?php

namespace Contacts\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Model Opportunite — table `opportunite`
 *
 * CRM pipeline (directive cmem_web 20260724_154618, Phase G-D). Voir docs/contacts/GUIDE.md.
 *
 * Portée : owner-strict — user_id est le PROPRIÉTAIRE de la fiche contact rattachée.
 * Soft-delete via `supprime_le` ; la suppression d'un contact masque ses opportunités.
 * Devise par défaut CAD ; les montants sont exposés en float (ou null).
 */
class Opportunite extends BaseModel
{
    protected $table = 'opportunite';

    /** Étapes du pipeline Kanban, dans l'ordre d'avancement. */
    public const ETAPES = ['prospect', 'qualifie', 'proposition', 'gagne', 'perdu'];

    /** Champs acceptés en création / mise à jour partielle. */
    public const FIELDS = ['titre', 'etape', 'montant', 'devise', 'date_cloture_prevue', 'notes'];

    /** Requis par BaseModel — création via createOpportunite(). */
    public function create()
    {
        return false;
    }

    /** Requis par BaseModel — mise à jour via updateOpportunite(). */
    public function update()
    {
        return false;
    }

    public static function isValidEtape(string $etape): bool
    {
        return in_array($etape, self::ETAPES, true);
    }

    /** Normalise une ligne pour le contrat de sortie. */
    public function hydrate(array $row): array
    {
        $row['id']         = (int) $row['id'];
        $row['user_id']    = (int) $row['user_id'];
        $row['contact_id'] = (int) $row['contact_id'];
        $row['montant']    = $row['montant'] !== null ? (float) $row['montant'] : null;
        return $row;
    }

    /** Nommé findOpportuniteById : BaseModel::findById a une signature incompatible. */
    public function findOpportuniteById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM opportunite WHERE id = ? AND supprime_le IS NULL LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** Opportunités actives d'une fiche contact (plus récentes d'abord). */
    public function findByContact(string $appId, int $userId, int $contactId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM opportunite
              WHERE app_id = ? AND user_id = ? AND contact_id = ? AND supprime_le IS NULL
              ORDER BY id DESC"
        );
        $stmt->execute([$appId, $userId, $contactId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Board global du propriétaire (toutes fiches confondues) — alimente le Kanban.
     * Les opportunités des contacts supprimés sont exclues (cascade logique).
     *
     * @param array $filters etape, limit, offset
     * @return array{opportunites: array, total: int}
     */
    public function findByOwner(string $appId, int $userId, array $filters = []): array
    {
        $where  = "o.app_id = ? AND o.user_id = ? AND o.supprime_le IS NULL
                   AND c.id IS NOT NULL AND c.supprime_le IS NULL";
        $params = [$appId, $userId];

        if (!empty($filters['etape'])) {
            $where   .= " AND o.etape = ?";
            $params[] = $filters['etape'];
        }

        $from = "FROM opportunite o LEFT JOIN contacts c ON c.id = o.contact_id";

        $countStmt = $this->getDb()->prepare("SELECT COUNT(*) {$from} WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT o.* {$from} WHERE {$where} ORDER BY o.id DESC";

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit'])) : null;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        if ($limit !== null) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);

        return [
            'opportunites' => array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total'        => $total,
        ];
    }

    public function createOpportunite(string $appId, int $userId, int $contactId, array $fields): int
    {
        $cols   = ['app_id', 'user_id', 'contact_id'];
        $vals   = [$appId, $userId, $contactId];
        $places = ['?', '?', '?'];

        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $fields)) {
                $cols[]   = $f;
                $places[] = '?';
                $vals[]   = $fields[$f];
            }
        }

        $sql = "INSERT INTO opportunite (" . implode(', ', $cols) . ")
                VALUES (" . implode(', ', $places) . ")";
        $this->getDb()->prepare($sql)->execute($vals);

        return (int) $this->getDb()->lastInsertId();
    }

    /** Mise à jour partielle : seuls les champs présents dans $fields sont écrits. */
    public function updateOpportunite(int $id, array $fields): bool
    {
        $sets = [];
        $vals = [];

        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $fields)) {
                $sets[] = "{$f} = ?";
                $vals[] = $fields[$f];
            }
        }

        if (empty($sets)) {
            $sets[] = "maj_le = CURRENT_TIMESTAMP";
        }

        $vals[] = $id;
        $sql = "UPDATE opportunite SET " . implode(', ', $sets) . " WHERE id = ? AND supprime_le IS NULL";
        return $this->getDb()->prepare($sql)->execute($vals);
    }

    /** Soft-delete d'une opportunité. Retourne true si une ligne active a été masquée. */
    public function softDeleteOpportunite(int $id): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE opportunite SET supprime_le = NOW() WHERE id = ? AND supprime_le IS NULL"
        );
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            \AuthGroups\Models\Link::purge('opportunite', $id);
            return true;
        }
        return false;
    }

    /**
     * Cascade : masque toutes les opportunités actives d'un contact supprimé
     * et purge leurs liens croisés. Retourne le nombre de lignes masquées.
     */
    public function softDeleteByContact(int $contactId): int
    {
        $ids = $this->activeIdsForContact($contactId);
        if (empty($ids)) {
            return 0;
        }

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->getDb()->prepare(
            "UPDATE opportunite SET supprime_le = NOW() WHERE id IN ({$in}) AND supprime_le IS NULL"
        );
        $stmt->execute($ids);

        foreach ($ids as $id) {
            \AuthGroups\Models\Link::purge('opportunite', (int) $id);
        }

        return $stmt->rowCount();
    }

    /** Ids des opportunités actives d'un contact — sert à la cascade et à la purge des liens. */
    public function activeIdsForContact(int $contactId): array
    {
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT id FROM opportunite WHERE contact_id = ? AND supprime_le IS NULL"
            );
            $stmt->execute([$contactId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            // Table absente (migration non appliquée) : la suppression du contact prime.
            return [];
        }
    }
}
