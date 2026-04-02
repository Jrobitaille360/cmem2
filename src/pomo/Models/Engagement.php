<?php

namespace Pomo\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Engagement — accès à la table pomo_engagements
 * Phase 1A uniquement.
 */
class Engagement extends BaseModel
{
    protected $table = 'pomo_engagements';

    /**
     * Implémentation de BaseModel::create() — non utilisée directement.
     * Utiliser createWaitlist() ou createSurvey() selon le type.
     */
    public function create()
    {
        throw new \LogicException('Utiliser createWaitlist() ou createSurvey()');
    }

    /**
     * Implémentation de BaseModel::update() — non applicable pour les engagements.
     */
    public function update()
    {
        throw new \LogicException('La mise à jour des engagements n\'est pas supportée');
    }

    /**
     * Vérifie si un courriel est déjà enregistré dans la waitlist.
     * Comparaison insensible à la casse via LOWER().
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->getDb()->prepare(
            "SELECT id FROM pomo_engagements WHERE type = 'waitlist' AND LOWER(email) = LOWER(?) LIMIT 1"
        );
        $stmt->execute([$email]);
        return (bool) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Crée un enregistrement waitlist.
     * Retourne l'ID inséré.
     */
    public function createWaitlist(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO pomo_engagements
                (type, device_id, email, platform, language, app_version,
                 build_number, session_duration, network_status, timestamp_utc)
            VALUES ('waitlist', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['device_id'],
            $data['email'],
            $data['platform']         ?? null,
            $data['language']         ?? null,
            $data['app_version']      ?? null,
            $data['build_number']     ?? null,
            isset($data['session_duration']) ? (int) $data['session_duration'] : null,
            $data['network_status']   ?? null,
            $data['timestamp_utc'],
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    /**
     * Crée un enregistrement survey.
     * Retourne l'ID inséré.
     */
    public function createSurvey(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO pomo_engagements
                (type, device_id, responses, suggestion, platform, language,
                 app_version, build_number, session_duration, network_status, timestamp_utc)
            VALUES ('survey', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['device_id'],
            json_encode($data['responses'], JSON_UNESCAPED_UNICODE),
            $data['suggestion']       ?? null,
            $data['platform']         ?? null,
            $data['language']         ?? null,
            $data['app_version']      ?? null,
            $data['build_number']     ?? null,
            isset($data['session_duration']) ? (int) $data['session_duration'] : null,
            $data['network_status']   ?? null,
            $data['timestamp_utc'],
        ]);
        return (int) $this->getDb()->lastInsertId();
    }
}
