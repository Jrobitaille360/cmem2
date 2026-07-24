<?php

namespace Contacts\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\RateLimitService;
use AuthGroups\Utils\Response;
use Contacts\Models\Contact;
use Contacts\Models\Interaction;
use Contacts\Services\ContactMessageService;
use Contacts\Services\CsvParser;
use Contacts\Services\VCardParser;
use Contacts\Services\VCardSerializer;
use Stripe\Services\EntitlementService;

/**
 * Contrôleur du pilier Contacts — directive cmem_web 20260723_084409.
 *
 * Portée owner-strict : toute fiche appartient à un propriétaire (user_id) ; un tiers reçoit 403.
 * Cap de plan `max_contacts` appliqué à la création et à l'import.
 */
class ContactController
{
    private const DEFAULT_APP_ID = 'puzzle';
    private const QUOTA_KEY      = 'max_contacts';
    /** Clé d'endpoint pour le rate-limit anti-abus des envois de courriel. */
    private const RL_ENDPOINT    = 'contact-message';

    private Contact $model;

    public function __construct()
    {
        $this->model = new Contact();
    }

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : self::DEFAULT_APP_ID;
    }

    /** Retourne la fiche si trouvée + propriété de l'utilisateur, sinon envoie l'erreur et retourne null. */
    private function ownedOrFail(array $user, int $id): ?array
    {
        $contact = $this->model->findContactById($id);
        if (!$contact) {
            LoggingMiddleware::logExit(404);
            Response::error('Contact non trouvé', null, 404);
            return null;
        }
        if ((int) $contact['user_id'] !== (int) $user['user_id']) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return null;
        }
        return $contact;
    }

    private function toContract(array $c): array
    {
        return [
            'id'            => (int) $c['id'],
            'app_id'        => $c['app_id'],
            'prenom'        => $c['prenom'],
            'nom'           => $c['nom'],
            'organisation'  => $c['organisation'],
            'fonction'      => $c['fonction'],
            'courriels'     => $c['courriels'],
            'telephones'    => $c['telephones'],
            'adresses'      => $c['adresses'],
            'sites'         => $c['sites'],
            'reseaux'       => $c['reseaux'],
            'notes'         => $c['notes'],
            'categories'    => $c['categories'],
            'anniversaire'  => $c['anniversaire'],
            'photo_file_id' => $c['photo_file_id'],
            'favori'        => (bool) $c['favori'],
            'partage_scope' => $c['partage_scope'],
            'cree_le'       => $c['cree_le'],
            'maj_le'        => $c['maj_le'],
        ];
    }

    /**
     * Extrait les champs écrivables d'un payload. En création, les JSON absents valent [].
     * En mise à jour, seuls les champs présents sont retournés (maj partielle).
     */
    private function extractFields(array $p, bool $isCreate): array
    {
        $fields = [];

        foreach (['prenom', 'nom'] as $f) {
            if (array_key_exists($f, $p)) {
                $fields[$f] = trim((string) $p[$f]);
            } elseif ($isCreate) {
                $fields[$f] = '';
            }
        }

        foreach (['organisation', 'fonction', 'notes'] as $f) {
            if (array_key_exists($f, $p)) {
                $v = $p[$f] === null ? null : trim((string) $p[$f]);
                $fields[$f] = ($v === '') ? null : $v;
            }
        }

        if (array_key_exists('anniversaire', $p)) {
            $v = trim((string) ($p['anniversaire'] ?? ''));
            $fields['anniversaire'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        }

        if (array_key_exists('photo_file_id', $p)) {
            $fields['photo_file_id'] = $p['photo_file_id'] !== null ? (int) $p['photo_file_id'] : null;
        }

        if (array_key_exists('favori', $p)) {
            $fields['favori'] = filter_var($p['favori'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (array_key_exists('partage_scope', $p)
            && in_array($p['partage_scope'], ['prive', 'groupe', 'utilisateurs'], true)) {
            $fields['partage_scope'] = $p['partage_scope'];
        }

        foreach (Contact::JSON_FIELDS as $f) {
            if (array_key_exists($f, $p) && is_array($p[$f])) {
                $fields[$f] = array_values($p[$f]);
            } elseif ($isCreate) {
                $fields[$f] = [];
            }
        }

        return $fields;
    }

    /** Vérifie le cap max_contacts. Retourne true si la réponse d'erreur a été envoyée. */
    private function quotaBlocked(int $userId): bool
    {
        $quotaError = EntitlementService::checkQuota(
            $userId,
            self::QUOTA_KEY,
            $this->model->countByOwner($userId)
        );
        if ($quotaError) {
            LoggingMiddleware::logExit(403);
            Response::error('Quota de contacts atteint', $quotaError, 403);
            return true;
        }
        return false;
    }

    // ---------------------------------------------------------------
    // GET /contacts
    // ---------------------------------------------------------------
    public function list(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p = Response::getRequestParams();

        $result = $this->model->findByOwner($this->appId($p), (int) $user['user_id'], [
            'q'         => $p['q']         ?? null,
            'categorie' => $p['categorie'] ?? null,
            'favori'    => $p['favori']    ?? null,
            'limit'     => $p['limit']     ?? null,
            'offset'    => $p['offset']    ?? null,
        ]);

        Response::success('Contacts récupérés', [
            'contacts' => array_map([$this, 'toContract'], $result['contacts']),
            'total'    => $result['total'],
        ]);
    }

    // ---------------------------------------------------------------
    // GET /contacts/{id}
    // ---------------------------------------------------------------
    public function show(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }
        Response::success('Contact récupéré', ['contact' => $this->toContract($contact)]);
    }

    // ---------------------------------------------------------------
    // POST /contacts
    // ---------------------------------------------------------------
    public function create(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p      = Response::getRequestParams();
        $userId = (int) $user['user_id'];
        $fields = $this->extractFields($p, true);

        if ($fields['prenom'] === '' && $fields['nom'] === '' && empty($fields['organisation'])) {
            LoggingMiddleware::logExit(422);
            Response::error('prenom, nom ou organisation requis', null, 422);
            return;
        }

        if ($this->quotaBlocked($userId)) { return; }

        $id      = $this->model->createContact($this->appId($p), $userId, $fields);
        $contact = $this->model->findContactById($id);

        Response::success('Contact créé', ['contact' => $this->toContract($contact)], 201);
    }

    // ---------------------------------------------------------------
    // PUT /contacts/{id}
    // ---------------------------------------------------------------
    public function update(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $p      = Response::getRequestParams();
        $fields = $this->extractFields($p, false);

        // Une maj ne peut pas vider à la fois prenom, nom et organisation.
        $prenom = array_key_exists('prenom', $fields) ? $fields['prenom'] : $contact['prenom'];
        $nom    = array_key_exists('nom', $fields) ? $fields['nom'] : $contact['nom'];
        $org    = array_key_exists('organisation', $fields) ? $fields['organisation'] : $contact['organisation'];
        if ($prenom === '' && $nom === '' && ($org === null || $org === '')) {
            LoggingMiddleware::logExit(422);
            Response::error('prenom, nom ou organisation requis', null, 422);
            return;
        }

        $this->model->updateContact($id, $fields);
        $updated = $this->model->findContactById($id);

        Response::success('Contact mis à jour', ['contact' => $this->toContract($updated)]);
    }

    // ---------------------------------------------------------------
    // DELETE /contacts/{id}  — soft-delete
    // ---------------------------------------------------------------
    public function delete(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $this->model->softDeleteContact($id);
        Response::success('Contact supprimé', ['id' => $id]);
    }

    // ---------------------------------------------------------------
    // POST /contacts/{id}/messages  — envoi courriel + journalisation
    // ---------------------------------------------------------------
    public function sendMessage(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();

        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $p     = Response::getRequestParams();
        $canal = strtolower(trim((string) ($p['canal'] ?? '')));
        $sujet = trim((string) ($p['sujet'] ?? ''));
        $corps = (string) ($p['corps'] ?? '');

        // Seul le canal email est supporté en v1.
        if ($canal !== 'email') {
            LoggingMiddleware::logExit(422);
            Response::error("canal doit valoir 'email'", null, 422);
            return;
        }
        if ($sujet === '' || trim($corps) === '') {
            LoggingMiddleware::logExit(422);
            Response::error('sujet et corps requis', null, 422);
            return;
        }

        // Résolution du destinataire : fourni (validé) sinon courriel principal de la fiche.
        $destinataire = trim((string) ($p['destinataire'] ?? ''));
        if ($destinataire !== '') {
            if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
                LoggingMiddleware::logExit(422);
                Response::error('destinataire invalide', null, 422);
                return;
            }
        } else {
            $destinataire = ContactMessageService::resolvePrimaryEmail($contact['courriels'] ?? []);
            if ($destinataire === null) {
                LoggingMiddleware::logExit(422);
                Response::error('Le contact n\'a aucun courriel ; fournir un destinataire', null, 422);
                return;
            }
        }

        // Rate-limit anti-abus : clé = courriel de l'usager courant.
        $userEmail = (string) ($user['email'] ?? '');
        if (!RateLimitService::check($userEmail, self::RL_ENDPOINT)) {
            LoggingMiddleware::logExit(429);
            Response::error('Trop d\'envois. Réessayez plus tard.', null, 429);
            return;
        }
        RateLimitService::record($userEmail, self::RL_ENDPOINT);

        $interaction = (new ContactMessageService())->sendEmail(
            $contact,
            $this->appId($p),
            (int) $user['user_id'],
            $userEmail,
            $destinataire,
            $sujet,
            $corps
        );

        Response::success('Message envoyé', ['message' => $this->toMessageContract($interaction)], 201);
    }

    // ---------------------------------------------------------------
    // GET /contacts/{id}/messages  — historique des messages
    // ---------------------------------------------------------------
    public function listMessages(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();

        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $p    = Response::getRequestParams();
        $rows = (new Interaction())->findByContact(
            $this->appId($p),
            (int) $user['user_id'],
            $id,
            ['type' => 'email', 'limit' => $p['limit'] ?? null, 'offset' => $p['offset'] ?? null]
        );

        Response::success('Messages récupérés', [
            'messages' => array_map([$this, 'toMessageContract'], $rows),
        ]);
    }

    // ---------------------------------------------------------------
    // GET /contacts/{id}/interactions  — historique unifié (CRM, Phase G-C)
    // ---------------------------------------------------------------
    public function listInteractions(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();

        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $p    = Response::getRequestParams();
        $rows = (new Interaction())->findByContact(
            $this->appId($p),
            (int) $user['user_id'],
            $id,
            ['type' => $p['type'] ?? null, 'limit' => $p['limit'] ?? null, 'offset' => $p['offset'] ?? null]
        );

        Response::success('Interactions récupérées', [
            'interactions' => array_map([$this, 'toInteractionContract'], $rows),
        ]);
    }

    // ---------------------------------------------------------------
    // POST /contacts/{id}/interactions  — saisie manuelle (CRM, Phase G-C)
    // ---------------------------------------------------------------
    public function createInteraction(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();

        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $p      = Response::getRequestParams();
        $type   = strtolower(trim((string) ($p['type'] ?? '')));
        $resume = trim((string) ($p['resume'] ?? ''));

        // type='email' est réservé à /messages ; les autres types hors liste sont refusés.
        if ($type === 'email') {
            LoggingMiddleware::logExit(422);
            Response::error("type='email' réservé à /messages", null, 422);
            return;
        }
        if (!in_array($type, Interaction::MANUAL_TYPES, true)) {
            LoggingMiddleware::logExit(422);
            Response::error("type doit valoir appel, note, rdv ou sms", null, 422);
            return;
        }
        if ($resume === '') {
            LoggingMiddleware::logExit(422);
            Response::error('resume requis', null, 422);
            return;
        }

        $direction = in_array($p['direction'] ?? null, ['entrant', 'sortant'], true)
            ? $p['direction'] : 'sortant';

        // date optionnelle : format Y-m-d H:i:s, sinon maintenant.
        $date = trim((string) ($p['date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $date)) {
            LoggingMiddleware::logExit(422);
            Response::error('date invalide (attendu Y-m-d H:i:s)', null, 422);
            return;
        }

        $fileId = array_key_exists('piece_jointe_file_id', $p) && $p['piece_jointe_file_id'] !== null
            ? (int) $p['piece_jointe_file_id'] : null;

        $interaction = (new Interaction())->logManual([
            'app_id'               => $this->appId($p),
            'user_id'              => (int) $user['user_id'],
            'contact_id'           => $id,
            'type'                 => $type,
            'direction'            => $direction,
            'resume'               => $resume,
            'date'                 => $date !== '' ? str_replace('T', ' ', $date) : null,
            'piece_jointe_file_id' => $fileId,
        ]);

        Response::success('Interaction créée', ['interaction' => $this->toInteractionContract($interaction)], 201);
    }

    // ---------------------------------------------------------------
    // DELETE /contacts/{id}/interactions/{interactionId}  — soft-delete
    // ---------------------------------------------------------------
    public function deleteInteraction(array $user, int $id, int $interactionId): void
    {
        LoggingMiddleware::logEntry();

        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $p  = Response::getRequestParams();
        $ok = (new Interaction())->softDeleteInteraction(
            $this->appId($p),
            (int) $user['user_id'],
            $id,
            $interactionId
        );

        if (!$ok) {
            LoggingMiddleware::logExit(404);
            Response::error('Interaction non trouvée', null, 404);
            return;
        }

        Response::success('Interaction supprimée', ['id' => $interactionId]);
    }

    /** Contrat de sortie unifié d'une interaction (CRM). */
    private function toInteractionContract(array $i): array
    {
        return [
            'id'                   => (int) $i['id'],
            'contact_id'           => (int) $i['contact_id'],
            'type'                 => $i['type'],
            'direction'            => $i['direction'],
            'date'                 => $i['date'],
            'resume'               => $i['resume'],
            'statut'               => $i['statut'],
            'piece_jointe_file_id' => $i['piece_jointe_file_id'],
        ];
    }

    /** Contrat de sortie d'une interaction (message). */
    private function toMessageContract(array $i): array
    {
        return [
            'id'           => (int) $i['id'],
            'contact_id'   => (int) $i['contact_id'],
            'canal'        => $i['canal'],
            'destinataire' => $i['destinataire'],
            'sujet'        => $i['sujet'],
            'statut'       => $i['statut'],
            'envoye_le'    => $i['envoye_le'],
        ];
    }

    // ---------------------------------------------------------------
    // GET /contacts/{id}.vcf  — export vCard 4.0
    // ---------------------------------------------------------------
    public function exportVcf(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $contact = $this->ownedOrFail($user, $id);
        if (!$contact) { return; }

        $vcf = (new VCardSerializer())->build($contact);

        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: inline; filename="contact-' . $id . '.vcf"');
        header('Content-Length: ' . strlen($vcf));
        echo $vcf;
        exit;
    }

    // ---------------------------------------------------------------
    // POST /contacts/import  — vCard ou CSV
    // ---------------------------------------------------------------
    public function import(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p      = Response::getRequestParams();
        $userId = (int) $user['user_id'];
        $appId  = $this->appId($p);

        // Contenu : body JSON (`content`) ou fichier multipart (`file`).
        $content = (string) ($p['content'] ?? '');
        $format  = strtolower(trim((string) ($p['format'] ?? '')));

        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $content = (string) file_get_contents($_FILES['file']['tmp_name']);
            if ($format === '') {
                $ext    = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
                $format = in_array($ext, ['vcf', 'vcard'], true) ? 'vcard' : 'csv';
            }
        }

        if (trim($content) === '') {
            LoggingMiddleware::logExit(422);
            Response::error('content ou fichier requis', null, 422);
            return;
        }

        if ($format === '') {
            $format = stripos($content, 'BEGIN:VCARD') !== false ? 'vcard' : 'csv';
        }
        if (!in_array($format, ['vcard', 'csv'], true)) {
            LoggingMiddleware::logExit(422);
            Response::error('format doit être vcard ou csv', null, 422);
            return;
        }

        if ($format === 'vcard') {
            $parsed  = (new VCardParser())->parse($content);
            $entrees = $parsed['cartes'];
            $erreurs = $parsed['erreurs'];
        } else {
            $parsed  = (new CsvParser())->parse($content);
            $entrees = $parsed['lignes'];
            $erreurs = $parsed['erreurs'];
        }

        $features = EntitlementService::getFeaturesForUser($userId);
        $limite   = $features[self::QUOTA_KEY] ?? null;
        $courant  = $this->model->countByOwner($userId);

        $crees = 0;
        $maj   = 0;
        $ignores = 0;

        foreach ($entrees as $entree) {
            // Upsert : d'abord par courriel, sinon par prenom+nom.
            $existant = null;
            $courriel = $entree['courriels'][0]['valeur'] ?? null;
            if ($courriel) {
                $existant = $this->model->findByEmail($appId, $userId, $courriel);
            }
            if (!$existant && ($entree['prenom'] !== '' || $entree['nom'] !== '')) {
                $existant = $this->model->findByName($appId, $userId, $entree['prenom'], $entree['nom']);
            }

            if ($existant) {
                $this->model->updateContact((int) $existant['id'], $entree);
                $maj++;
                continue;
            }

            if ($limite !== null && $courant >= $limite) {
                $ignores++;
                continue;
            }

            $this->model->createContact($appId, $userId, $entree);
            $crees++;
            $courant++;
        }

        Response::success('Import terminé', [
            'crees'   => $crees,
            'maj'     => $maj,
            'ignores' => $ignores,
            'erreurs' => $erreurs,
        ]);
    }
}
