<?php

namespace Contacts\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Model Contact — table `contacts`
 *
 * Pilier Contacts (directive cmem_web 20260723_084409). Voir docs/contacts/GUIDE.md.
 *
 * Portée : owner-strict — user_id est le PROPRIÉTAIRE de la fiche, pas un compte lié.
 * Soft-delete via `supprime_le` ; les lectures excluent toujours les fiches supprimées.
 * Colonnes en français : elles forment le contrat JSON attendu par le client cmem_web.
 */
class Contact extends BaseModel
{
    protected $table = 'contacts';

    /** Champs JSON répétables — toujours stockés comme tableaux (jamais NULL). */
    public const JSON_FIELDS = ['courriels', 'telephones', 'adresses', 'sites', 'reseaux', 'categories'];

    /**
     * Champs du chiffrement de bout en bout (directive 20260804_090000).
     * enc_payload est opaque : ni normalisé, ni tronqué, ni lu par le serveur.
     */
    public const ENC_FIELDS = ['enc_alg', 'enc_iv', 'enc_payload'];

    /** Borne de enc_payload — au-delà, 400 explicite plutôt qu'une troncature MEDIUMTEXT. */
    public const ENC_PAYLOAD_MAX = 16000000;

    /** Champs scalaires acceptés en création/mise à jour. */
    public const SCALAR_FIELDS = ['prenom', 'nom', 'organisation', 'fonction', 'notes',
                                  'anniversaire', 'photo_file_id', 'favori', 'partage_scope',
                                  'date_relance', 'motif_relance', 'relance_faite_le',
                                  'enc_alg', 'enc_iv', 'enc_payload'];

    /** Requis par BaseModel — création via createContact(). */
    public function create()
    {
        return false;
    }

    /** Requis par BaseModel — mise à jour via updateContact(). */
    public function update()
    {
        return false;
    }

    /** Décode les colonnes JSON et normalise les types pour le contrat de sortie. */
    public function hydrate(array $row): array
    {
        foreach (self::JSON_FIELDS as $f) {
            $decoded = json_decode($row[$f] ?? '[]', true);
            $row[$f] = is_array($decoded) ? $decoded : [];
        }
        $row['id']            = (int) $row['id'];
        $row['user_id']       = (int) $row['user_id'];
        $row['favori']        = (bool) $row['favori'];
        $row['photo_file_id'] = $row['photo_file_id'] !== null ? (int) $row['photo_file_id'] : null;
        return $row;
    }

    /** Nommé findContactById : BaseModel::findById a une signature incompatible (deleted_at). */
    public function findContactById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM contacts WHERE id = ? AND supprime_le IS NULL LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Liste filtrée du propriétaire.
     *
     * @param array $filters q, categorie, favori, limit, offset
     * @return array{contacts: array, total: int}
     */
    public function findByOwner(string $appId, int $userId, array $filters = []): array
    {
        $where  = "app_id = ? AND user_id = ? AND supprime_le IS NULL";
        $params = [$appId, $userId];

        if (!empty($filters['q'])) {
            // Le courriel vit dans une colonne JSON → recherche sur la représentation texte.
            // Fiche chiffrée : organisation et courriels sont vides côté serveur, la recherche
            // se réduit donc d'elle-même à prenom / nom / categories (directive 20260804 §2.3).
            // enc_payload n'est jamais interrogé : le corps chiffré n'est pas indexable.
            $where .= " AND (prenom LIKE ? OR nom LIKE ? OR organisation LIKE ? OR courriels LIKE ? OR categories LIKE ?)";
            $like   = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        if (!empty($filters['categorie'])) {
            $where   .= " AND JSON_CONTAINS(categories, ?)";
            $params[] = json_encode((string) $filters['categorie'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($filters['favori']) && $filters['favori'] !== '') {
            $where   .= " AND favori = ?";
            $params[] = ((int) $filters['favori']) ? 1 : 0;
        }

        $countStmt = $this->getDb()->prepare("SELECT COUNT(*) FROM contacts WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM contacts WHERE {$where} ORDER BY nom ASC, prenom ASC, id ASC";

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit'])) : null;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        if ($limit !== null) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'contacts' => array_map([$this, 'hydrate'], $rows),
            'total'    => $total,
        ];
    }

    /** Nombre de fiches actives — base du contrôle de quota max_contacts. */
    public function countByOwner(int $userId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COUNT(*) FROM contacts WHERE user_id = ? AND supprime_le IS NULL"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Cherche une fiche active du propriétaire par courriel (upsert d'import).
     * Comparaison insensible à la casse sur la représentation JSON.
     */
    public function findByEmail(string $appId, int $userId, string $email): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM contacts
             WHERE app_id = ? AND user_id = ? AND supprime_le IS NULL
               AND LOWER(courriels) LIKE ?
             LIMIT 1"
        );
        $stmt->execute([$appId, $userId, '%' . strtolower($email) . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** Cherche une fiche active du propriétaire par prénom + nom (upsert d'import sans courriel). */
    public function findByName(string $appId, int $userId, string $prenom, string $nom): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM contacts
             WHERE app_id = ? AND user_id = ? AND supprime_le IS NULL AND prenom = ? AND nom = ?
             LIMIT 1"
        );
        $stmt->execute([$appId, $userId, $prenom, $nom]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function createContact(string $appId, int $userId, array $fields): int
    {
        $cols   = ['app_id', 'user_id'];
        $vals   = [$appId, $userId];
        $places = ['?', '?'];

        foreach (self::SCALAR_FIELDS as $f) {
            if (array_key_exists($f, $fields)) {
                $cols[]   = $f;
                $places[] = '?';
                $vals[]   = $fields[$f];
            }
        }
        foreach (self::JSON_FIELDS as $f) {
            $cols[]   = $f;
            $places[] = '?';
            $vals[]   = json_encode($fields[$f] ?? [], JSON_UNESCAPED_UNICODE);
        }

        $sql = "INSERT INTO contacts (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $places) . ")";
        $this->getDb()->prepare($sql)->execute($vals);

        return (int) $this->getDb()->lastInsertId();
    }

    /** Mise à jour partielle : seuls les champs présents dans $fields sont écrits. */
    public function updateContact(int $id, array $fields): bool
    {
        $sets = [];
        $vals = [];

        foreach (self::SCALAR_FIELDS as $f) {
            if (array_key_exists($f, $fields)) {
                $sets[] = "{$f} = ?";
                $vals[] = $fields[$f];
            }
        }
        foreach (self::JSON_FIELDS as $f) {
            if (array_key_exists($f, $fields)) {
                $sets[] = "{$f} = ?";
                $vals[] = json_encode($fields[$f], JSON_UNESCAPED_UNICODE);
            }
        }

        if (empty($sets)) {
            // Rien à écrire : on touche quand même maj_le pour refléter la requête.
            $sets[] = "maj_le = CURRENT_TIMESTAMP";
        }

        $vals[] = $id;
        $sql = "UPDATE contacts SET " . implode(', ', $sets) . " WHERE id = ? AND supprime_le IS NULL";
        return $this->getDb()->prepare($sql)->execute($vals);
    }

    /**
     * Soft-delete — la ligne reste en base, purgée plus tard par le cron RGPD.
     * Nommé softDeleteContact : BaseModel::softDelete a une signature incompatible (deleted_at).
     *
     * Cascade (Phases G-D / G-E) : masque les opportunités de la fiche et purge les liens
     * croisés du contact ainsi que ceux de ses interactions et opportunités.
     */
    public function softDeleteContact(int $id): bool
    {
        // Ids collectés AVANT le masquage : après, les requêtes « actives » ne les voient plus.
        $interactionIds = $this->activeInteractionIds($id);
        $opportuniteIds = (new Opportunite())->activeIdsForContact($id);

        $stmt = $this->getDb()->prepare(
            "UPDATE contacts SET supprime_le = NOW() WHERE id = ? AND supprime_le IS NULL"
        );
        $ok = $stmt->execute([$id]);

        (new Opportunite())->softDeleteByContact($id);

        \AuthGroups\Models\Link::purge('contact', $id);
        foreach ($interactionIds as $iid) {
            \AuthGroups\Models\Link::purge('interaction', $iid);
        }
        foreach ($opportuniteIds as $oid) {
            \AuthGroups\Models\Link::purge('opportunite', $oid);
        }

        return $ok;
    }

    /** Ids des interactions actives d'une fiche — sert à la purge des liens croisés. */
    private function activeInteractionIds(int $contactId): array
    {
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT id FROM interaction WHERE contact_id = ? AND supprime_le IS NULL"
            );
            $stmt->execute([$contactId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            // Table absente (migration non appliquée) : la suppression du contact prime.
            return [];
        }
    }
}
