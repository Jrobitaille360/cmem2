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

    /** Requis par BaseModel — mise à jour via updateManual(). */
    public function update()
    {
        return false;
    }

    /** Types acceptés en saisie manuelle (email exclu — passe par /messages). */
    public const MANUAL_TYPES = ['appel', 'note', 'rdv', 'sms'];

    /** Normalise une ligne pour le contrat de sortie. */
    public function hydrate(array $row): array
    {
        $row['id']         = (int) $row['id'];
        $row['user_id']    = (int) $row['user_id'];
        $row['contact_id'] = (int) $row['contact_id'];
        $row['meta']       = $row['meta'] !== null ? json_decode($row['meta'], true) : null;
        $row['piece_jointe_file_id'] = isset($row['piece_jointe_file_id']) && $row['piece_jointe_file_id'] !== null
            ? (int) $row['piece_jointe_file_id'] : null;
        // Contrat unifié : `date` et `resume` valent la saisie CRM, sinon les champs email (Phase G-B).
        $row['date']   = $row['date_interaction'] ?? $row['envoye_le'] ?? $row['cree_le'] ?? null;
        $row['resume'] = ($row['resume'] ?? null) !== null && $row['resume'] !== ''
            ? $row['resume'] : ($row['sujet'] ?? null);
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

    /**
     * Journalise une interaction saisie manuellement (appel/note/rdv/sms) et retourne la ligne créée.
     *
     * @param array $data app_id, user_id, contact_id, type, direction, date, resume, piece_jointe_file_id
     * @return array Ligne hydratée
     */
    public function logManual(array $data): array
    {
        $sql = "INSERT INTO interaction
                    (app_id, user_id, contact_id, type, direction,
                     resume, date_interaction, piece_jointe_file_id, statut)
                VALUES
                    (:app_id, :user_id, :contact_id, :type, :direction,
                     :resume, :date_interaction, :piece_jointe_file_id, NULL)";
        $this->getDb()->prepare($sql)->execute([
            'app_id'               => $data['app_id'],
            'user_id'              => $data['user_id'],
            'contact_id'           => $data['contact_id'],
            'type'                 => $data['type'],
            'direction'            => $data['direction'] ?? 'sortant',
            'resume'               => $data['resume'],
            'date_interaction'     => $data['date'] ?? date('Y-m-d H:i:s'),
            'piece_jointe_file_id' => $data['piece_jointe_file_id'] ?? null,
        ]);

        return $this->findInteractionById((int) $this->getDb()->lastInsertId());
    }

    /**
     * Soft-delete d'une interaction du propriétaire. Retourne true si une ligne active a été masquée.
     * Nommé softDeleteInteraction : BaseModel::softDelete a une signature incompatible.
     */
    public function softDeleteInteraction(string $appId, int $userId, int $contactId, int $interactionId): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE interaction SET supprime_le = NOW()
              WHERE id = ? AND app_id = ? AND user_id = ? AND contact_id = ? AND supprime_le IS NULL"
        );
        $stmt->execute([$interactionId, $appId, $userId, $contactId]);
        return $stmt->rowCount() > 0;
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
        $where  = "app_id = ? AND user_id = ? AND contact_id = ? AND supprime_le IS NULL";
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
