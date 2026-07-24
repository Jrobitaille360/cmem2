<?php

namespace Contacts\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Model Interaction — table `interaction`
 *
 * Directive cmem_web 20260724_090048 — envoi de courriel depuis une fiche contact.
 * Conçue générique pour anticiper crm-interactions (Phase C : historique unifié).
 * La v1 ne journalise que des courriels sortants (type='email', direction='sortant').
 *
 * Portée : owner-strict — user_id est le PROPRIÉTAIRE de la fiche contact.
 */
class Interaction extends BaseModel
{
    protected $table = 'interaction';

    /** Requis par BaseModel — création via logEmail(). */
    public function create()
    {
        return false;
    }

    /** Requis par BaseModel — pas de mise à jour en v1. */
    public function update()
    {
        return false;
    }

    /** Normalise une ligne pour le contrat de sortie. */
    public function hydrate(array $row): array
    {
        $row['id']         = (int) $row['id'];
        $row['user_id']    = (int) $row['user_id'];
        $row['contact_id'] = (int) $row['contact_id'];
        $row['meta']       = $row['meta'] !== null ? json_decode($row['meta'], true) : null;
        return $row;
    }

    /**
     * Journalise un courriel sortant et retourne la ligne créée.
     *
     * @param array $data app_id, user_id, contact_id, canal, destinataire, sujet, corps, statut
     * @return array Ligne hydratée
     */
    public function logEmail(array $data): array
    {
        $sql = "INSERT INTO interaction
                    (app_id, user_id, contact_id, type, direction, canal,
                     destinataire, sujet, corps, statut, envoye_le)
                VALUES
                    (:app_id, :user_id, :contact_id, 'email', 'sortant', :canal,
                     :destinataire, :sujet, :corps, :statut, NOW())";
        $this->getDb()->prepare($sql)->execute([
            'app_id'       => $data['app_id'],
            'user_id'      => $data['user_id'],
            'contact_id'   => $data['contact_id'],
            'canal'        => $data['canal'] ?? 'email',
            'destinataire' => $data['destinataire'],
            'sujet'        => $data['sujet'],
            'corps'        => $data['corps'],
            'statut'       => $data['statut'] ?? 'envoye',
        ]);

        return $this->findInteractionById((int) $this->getDb()->lastInsertId());
    }

    /** Nommé findInteractionById : BaseModel::findById a une signature incompatible. */
    public function findInteractionById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM interaction WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Historique des interactions d'une fiche contact (propriétaire).
     *
     * @return array Liste hydratée, plus récentes d'abord.
     */
    public function findByContact(string $appId, int $userId, int $contactId, array $filters = []): array
    {
        $where  = "app_id = ? AND user_id = ? AND contact_id = ?";
        $params = [$appId, $userId, $contactId];

        if (!empty($filters['type'])) {
            $where   .= " AND type = ?";
            $params[] = $filters['type'];
        }

        $sql = "SELECT * FROM interaction WHERE {$where} ORDER BY id DESC";

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit'])) : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        $sql   .= " LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrate'], $rows);
    }
}
