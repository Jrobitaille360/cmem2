<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\UserE2eKey;
use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;
use Throwable;

/**
 * Contrôleur des métadonnées de clé du chiffrement de bout en bout.
 *
 * Directive : 20260803_205805_cmem_web_vers_cmem2_API__e2e-metadonnees-de-cle.md
 *
 *   GET    /users/me/e2e-key?app_id=cmemweb  → get()
 *   PUT    /users/me/e2e-key                 → put()
 *   DELETE /users/me/e2e-key?app_id=cmemweb  → delete()
 *
 * Portée owner-strict : l'id du propriétaire vient toujours du JWT, jamais de
 * l'URL ni du corps. Aucune route par id n'existe, même pour un administrateur.
 *
 * Le serveur ne peut rien déchiffrer : il n'a ni la passphrase, ni le code de
 * secours. Les blobs sont stockés et restitués octet pour octet.
 *
 * Le corps des requêtes PUT n'est jamais journalisé (cf. LoggingMiddleware).
 */
class UserE2eKeyController
{
    /** Longueur maximale des blobs base64 (wrapped_* et verifier). */
    const MAX_BLOB_LENGTH = 4096;

    /** Longueur maximale du sel base64. */
    const MAX_SALT_LENGTH = 64;

    /** Longueur maximale du nom de fonction de dérivation. */
    const MAX_KDF_LENGTH = 32;

    /** Longueur maximale de l'identifiant d'application. */
    const MAX_APP_ID_LENGTH = 50;

    private UserE2eKey $model;

    public function __construct()
    {
        $this->model = new UserE2eKey();
    }

    /**
     * GET /users/me/e2e-key?app_id=cmemweb
     *
     * 404 quand aucune ligne n'existe : c'est l'état normal « chiffrement jamais
     * activé », pas une anomalie. Il n'est donc pas journalisé en erreur.
     */
    public function get(int $ownerId): void
    {
        $appId = $this->resolveAppId($_GET['app_id'] ?? null);
        if ($appId === null) {
            return;
        }

        try {
            $row = $this->model->findByOwnerAndApp($ownerId, $appId);
        } catch (Throwable $e) {
            LogService::error('Lecture des métadonnées e2e impossible', ['error' => $e->getMessage()]);
            Response::error('Erreur lors de la récupération de la clé', null, 500);
            return;
        }

        if ($row === null) {
            Response::error('Chiffrement de bout en bout non activé', null, 404);
            return;
        }

        Response::success('Métadonnées de clé', $this->format($row));
    }

    /**
     * PUT /users/me/e2e-key
     *
     * Crée (201) ou remplace (200) la ligne. Les blobs sont écrits tels quels :
     * aucune transformation, aucune troncature. Un dépassement de borne renvoie
     * 400 et n'écrit rien.
     */
    public function put(int $ownerId): void
    {
        // Lecture directe du corps : Response::getRequestParams() fusionne $_GET,
        // ce qui est sans effet ici, mais on veut garantir l'absence de toute
        // transformation sur les blobs.
        $raw   = file_get_contents('php://input');
        $input = json_decode((string) $raw, true);

        if (!is_array($input)) {
            Response::error('Corps de requête JSON invalide', null, 400);
            return;
        }

        $appId = $this->resolveAppId($input['app_id'] ?? null);
        if ($appId === null) {
            return;
        }

        $errors = [];

        $kdf = $input['kdf'] ?? null;
        if (!is_string($kdf) || $kdf === '') {
            $errors['kdf'] = 'Champ requis (chaîne non vide)';
        } elseif (strlen($kdf) > self::MAX_KDF_LENGTH) {
            $errors['kdf'] = 'Dépasse ' . self::MAX_KDF_LENGTH . ' caractères';
        }

        $salt = $input['kdf_salt'] ?? null;
        if (!is_string($salt) || $salt === '') {
            $errors['kdf_salt'] = 'Champ requis (chaîne non vide)';
        } elseif (strlen($salt) > self::MAX_SALT_LENGTH) {
            $errors['kdf_salt'] = 'Dépasse ' . self::MAX_SALT_LENGTH . ' caractères';
        }

        // is_int strict : "310000" ou 310000.5 sont refusés plutôt que coercés.
        $iterations = $input['kdf_iterations'] ?? null;
        if (!is_int($iterations) || $iterations <= 0) {
            $errors['kdf_iterations'] = 'Entier strictement positif requis';
        }

        foreach (['wrapped_key_passphrase', 'verifier'] as $field) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || $value === '') {
                $errors[$field] = 'Champ requis (chaîne non vide)';
            } elseif (strlen($value) > self::MAX_BLOB_LENGTH) {
                $errors[$field] = 'Dépasse ' . self::MAX_BLOB_LENGTH . ' caractères';
            }
        }

        // wrapped_key_recovery est optionnel : absent ou null quand l'usager a
        // refusé le code de secours.
        $recovery = $input['wrapped_key_recovery'] ?? null;
        if ($recovery !== null) {
            if (!is_string($recovery) || $recovery === '') {
                $errors['wrapped_key_recovery'] = 'Chaîne non vide ou null attendu';
            } elseif (strlen($recovery) > self::MAX_BLOB_LENGTH) {
                $errors['wrapped_key_recovery'] = 'Dépasse ' . self::MAX_BLOB_LENGTH . ' caractères';
            }
        }

        if (!empty($errors)) {
            Response::error('Données de validation invalides', $errors, 400);
            return;
        }

        try {
            $isCreation = $this->model->upsert($ownerId, $appId, [
                'kdf'                    => $kdf,
                'kdf_salt'               => $salt,
                'kdf_iterations'         => $iterations,
                'wrapped_key_passphrase' => $input['wrapped_key_passphrase'],
                'wrapped_key_recovery'   => $recovery,
                'verifier'               => $input['verifier'],
            ]);
            $row = $this->model->findByOwnerAndApp($ownerId, $appId);
        } catch (Throwable $e) {
            // Le message d'exception peut contenir la requête : on ne journalise
            // que la classe et le code, jamais les blobs.
            LogService::error('Écriture des métadonnées e2e impossible', ['exception' => get_class($e)]);
            Response::error('Erreur lors de l\'enregistrement de la clé', null, 500);
            return;
        }

        if ($row === null) {
            Response::error('Erreur lors de l\'enregistrement de la clé', null, 500);
            return;
        }

        Response::success(
            $isCreation ? 'Métadonnées de clé créées' : 'Métadonnées de clé remplacées',
            $this->format($row),
            $isCreation ? 201 : 200
        );
    }

    /**
     * DELETE /users/me/e2e-key?app_id=cmemweb
     *
     * Opération volontairement explicite : elle ne supprime aucun journal, mais
     * rend illisibles tous ceux qui sont chiffrés — la clé maîtresse n'existe
     * plus nulle part. Aucune autre opération serveur ne doit l'appeler par
     * effet de bord (désabonnement, changement de plan, révocation d'appareil,
     * maintenance). Seule la purge définitive de compte l'emporte, via la FK
     * ON DELETE CASCADE.
     */
    public function delete(int $ownerId): void
    {
        $appId = $this->resolveAppId($_GET['app_id'] ?? null);
        if ($appId === null) {
            return;
        }

        try {
            $deleted = $this->model->deleteByOwnerAndApp($ownerId, $appId);
        } catch (Throwable $e) {
            LogService::error('Suppression des métadonnées e2e impossible', ['error' => $e->getMessage()]);
            Response::error('Erreur lors de la suppression de la clé', null, 500);
            return;
        }

        if (!$deleted) {
            Response::error('Chiffrement de bout en bout non activé', null, 404);
            return;
        }

        Response::success('Métadonnées de clé supprimées', ['deleted' => true]);
    }

    /**
     * Valide l'app_id transmis. Aucun défaut : la directive exige qu'il soit
     * toujours fourni par le client (jamais le 'puzzle' implicite du serveur).
     *
     * @return string|null L'app_id validé, ou null si la réponse 400 a déjà été émise.
     */
    private function resolveAppId($appId): ?string
    {
        if (!is_string($appId) || trim($appId) === '') {
            Response::error('Données de validation invalides', ['app_id' => 'Champ requis'], 400);
            return null;
        }

        $appId = trim($appId);
        if (strlen($appId) > self::MAX_APP_ID_LENGTH) {
            Response::error(
                'Données de validation invalides',
                ['app_id' => 'Dépasse ' . self::MAX_APP_ID_LENGTH . ' caractères'],
                400
            );
            return null;
        }

        return $appId;
    }

    /**
     * Met en forme la ligne pour la réponse : types stables, blobs intacts.
     */
    private function format(array $row): array
    {
        return [
            'id'                     => (int) $row['id'],
            'app_id'                 => $row['app_id'],
            'kdf'                    => $row['kdf'],
            'kdf_salt'               => $row['kdf_salt'],
            'kdf_iterations'         => (int) $row['kdf_iterations'],
            'wrapped_key_passphrase' => $row['wrapped_key_passphrase'],
            'wrapped_key_recovery'   => $row['wrapped_key_recovery'],
            'verifier'               => $row['verifier'],
            'created_at'             => $row['created_at'],
            'updated_at'             => $row['updated_at'],
        ];
    }
}
