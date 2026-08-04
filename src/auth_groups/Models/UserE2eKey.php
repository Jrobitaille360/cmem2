<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Modèle UserE2eKey — métadonnées de clé du chiffrement de bout en bout.
 *
 * Directive : 20260803_205805_cmem_web_vers_cmem2_API__e2e-metadonnees-de-cle.md
 *
 * RÈGLE ABSOLUE : les blobs (wrapped_key_passphrase, wrapped_key_recovery,
 * verifier, kdf_salt) sont du base64 opaque. Aucun strip_tags, aucun
 * htmlspecialchars, aucune normalisation, aucune troncature — un octet modifié
 * rend la clé maîtresse irrécupérable, donc tous les journaux de l'usager
 * illisibles, définitivement. C'est pourquoi ce modèle n'hérite pas des
 * traitements appliqués ailleurs (cf. UserAppSetup::create()).
 *
 * N'étend pas BaseModel volontairement : ce dernier impose create()/update()
 * et un SoftDeleteTrait basé sur une colonne deleted_at. Ici la suppression est
 * réelle — une ligne fantôme casserait l'unicité (owner_id, app_id) et ferait
 * échouer le 404 « chiffrement jamais activé » attendu par le client.
 */
class UserE2eKey
{
    protected string $table = 'user_e2e_keys';

    private PDO $db;

    public function __construct()
    {
        require_once __DIR__ . '/../database.php';
        $this->db = \Database::getInstance()->getConnection();
    }

    private function getDb(): PDO
    {
        return $this->db;
    }

    /**
     * Récupérer la ligne d'un usager pour une application donnée.
     *
     * @return array|null Ligne telle qu'en base, ou null si le chiffrement n'a jamais été activé.
     */
    public function findByOwnerAndApp(int $ownerId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = :owner_id AND app_id = :app_id"
        );
        $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->bindValue(':app_id', $appId, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Créer ou remplacer la ligne (activation, changement de passphrase,
     * régénération du code de secours).
     *
     * L'unicité (owner_id, app_id) garantit qu'il n'existe jamais deux lignes ;
     * ON DUPLICATE KEY UPDATE conserve donc l'id d'origine.
     *
     * @return bool True si une ligne a été créée, false si une ligne existante a été remplacée.
     */
    public function upsert(int $ownerId, string $appId, array $fields): bool
    {
        $existing = $this->findByOwnerAndApp($ownerId, $appId);

        $sql = "INSERT INTO {$this->table}
                    (owner_id, app_id, kdf, kdf_salt, kdf_iterations,
                     wrapped_key_passphrase, wrapped_key_recovery, verifier)
                VALUES
                    (:owner_id, :app_id, :kdf, :kdf_salt, :kdf_iterations,
                     :wrapped_key_passphrase, :wrapped_key_recovery, :verifier)
                ON DUPLICATE KEY UPDATE
                    kdf                    = VALUES(kdf),
                    kdf_salt               = VALUES(kdf_salt),
                    kdf_iterations         = VALUES(kdf_iterations),
                    wrapped_key_passphrase = VALUES(wrapped_key_passphrase),
                    wrapped_key_recovery   = VALUES(wrapped_key_recovery),
                    verifier               = VALUES(verifier),
                    updated_at             = CURRENT_TIMESTAMP";

        $stmt = $this->getDb()->prepare($sql);
        $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->bindValue(':app_id', $appId, PDO::PARAM_STR);
        $stmt->bindValue(':kdf', $fields['kdf'], PDO::PARAM_STR);
        $stmt->bindValue(':kdf_salt', $fields['kdf_salt'], PDO::PARAM_STR);
        $stmt->bindValue(':kdf_iterations', $fields['kdf_iterations'], PDO::PARAM_INT);
        $stmt->bindValue(':wrapped_key_passphrase', $fields['wrapped_key_passphrase'], PDO::PARAM_STR);
        $stmt->bindValue(
            ':wrapped_key_recovery',
            $fields['wrapped_key_recovery'],
            $fields['wrapped_key_recovery'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->bindValue(':verifier', $fields['verifier'], PDO::PARAM_STR);
        $stmt->execute();

        return $existing === null;
    }

    /**
     * Supprimer la ligne. Suppression réelle, jamais un soft delete.
     *
     * @return bool True si une ligne a été supprimée.
     */
    public function deleteByOwnerAndApp(int $ownerId, string $appId): bool
    {
        $stmt = $this->getDb()->prepare(
            "DELETE FROM {$this->table} WHERE owner_id = :owner_id AND app_id = :app_id"
        );
        $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->bindValue(':app_id', $appId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
