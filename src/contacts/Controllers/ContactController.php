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
            // Relance de contact — modèle A1 (directive 20260726_161400 volet A).
            // Une fiche est « à relancer » si date_relance != null et relance_faite_le == null.
            'date_relance'     => $c['date_relance']     ?? null,
            'motif_relance'    => $c['motif_relance']    ?? null,
            'relance_faite_le' => $c['relance_faite_le'] ?? null,
            // Chiffrement de bout en bout — restitués tels quels, jamais interprétés.
            'enc_alg'       => $c['enc_alg']     ?? null,
            'enc_iv'        => $c['enc_iv']      ?? null,
            'enc_payload'   => $c['enc_payload'] ?? null,
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

        // --- Relance de contact (modèle A1) ---
        if (array_key_exists('date_relance', $p)) {
            $v = $p['date_relance'] === null ? '' : trim((string) $p['date_relance']);
            $fields['date_relance'] = $v === '' ? null : $v;   // format déjà validé
        }

        if (array_key_exists('motif_relance', $p)) {
            $v = $p['motif_relance'] === null ? '' : trim((string) $p['motif_relance']);
            $fields['motif_relance'] = $v === '' ? null : mb_substr($v, 0, 255);
        }

        if (array_key_exists('relance_faite_le', $p)) {
            $fields['relance_faite_le'] = self::normalizeFaiteLe($p['relance_faite_le']);
        }

        foreach (Contact::JSON_FIELDS as $f) {
            if (array_key_exists($f, $p) && is_array($p[$f])) {
                $fields[$f] = array_values($p[$f]);
            } elseif ($isCreate) {
                $fields[$f] = [];
            }
        }

        // Chiffrement de bout en bout : stockés tels quels — aucun trim, aucun strip_tags,
        // aucune normalisation. Un octet réécrit rendrait la fiche indéchiffrable.
        // null explicite = retour au clair ; champ omis = inchangé.
        foreach (Contact::ENC_FIELDS as $f) {
            if (array_key_exists($f, $p)) {
                $fields[$f] = $p[$f] === null ? null : (string) $p[$f];
            }
        }

        return $fields;
    }

    /**
     * Valide les champs de relance d'un payload.
     * Retourne le message d'erreur à renvoyer en 422, ou null si tout est acceptable.
     */
    private function validateRelance(array $p): ?string
    {
        if (array_key_exists('date_relance', $p) && $p['date_relance'] !== null) {
            $v = trim((string) $p['date_relance']);
            if ($v !== '' && !self::isValidDate($v)) {
                return 'date_relance invalide (attendu AAAA-MM-JJ)';
            }
        }

        if (array_key_exists('relance_faite_le', $p)
            && $p['relance_faite_le'] !== null
            && !is_bool($p['relance_faite_le'])) {
            $v = trim((string) $p['relance_faite_le']);
            if ($v !== '' && !self::isValidDateTime($v)) {
                return 'relance_faite_le invalide (attendu booléen ou AAAA-MM-JJ HH:MM:SS)';
            }
        }

        return null;
    }

    /**
     * Valide les champs de chiffrement. Retourne le message d'erreur à renvoyer en 400,
     * ou null si tout est acceptable. Un dépassement de borne est refusé explicitement :
     * jamais de troncature silencieuse, qui rendrait la fiche indéchiffrable.
     */
    private function validateEnc(array $p): ?string
    {
        foreach (['enc_alg', 'enc_iv'] as $f) {
            if (array_key_exists($f, $p) && $p[$f] !== null && strlen((string) $p[$f]) > 32) {
                return "{$f} dépasse 32 caractères";
            }
        }

        if (array_key_exists('enc_payload', $p) && $p['enc_payload'] !== null
            && strlen((string) $p['enc_payload']) > Contact::ENC_PAYLOAD_MAX) {
            return 'enc_payload dépasse ' . Contact::ENC_PAYLOAD_MAX . ' caractères';
        }

        return null;
    }

    /** Date calendaire réelle au format AAAA-MM-JJ (2026-13-40 est refusé). */
    private static function isValidDate(string $v): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isValidDateTime(string $v): bool
    {
        if (self::isValidDate($v)) {
            return true;
        }
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $v);
        return $d !== false && $d->format('Y-m-d H:i:s') === $v;
    }

    /**
     * Normalise `relance_faite_le` : `true` horodate côté serveur, `false`/`null`/vide efface,
     * une chaîne datetime est conservée telle quelle (déjà validée).
     */
    private static function normalizeFaiteLe($raw): ?string
    {
        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }
        if ($raw === true) {
            return date('Y-m-d H:i:s');
        }
        $v = trim((string) $raw);
        if ($v === '' || strtolower($v) === 'false' || $v === '0') {
            return null;
        }
        if (strtolower($v) === 'true' || $v === '1') {
            return date('Y-m-d H:i:s');
        }
        return self::isValidDate($v) ? $v . ' 00:00:00' : $v;
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

        if ($err = $this->validateRelance($p)) {
            LoggingMiddleware::logExit(422);
            Response::error($err, null, 422);
            return;
        }

        if ($err = $this->validateEnc($p)) {
            LoggingMiddleware::logExit(400);
            Response::error($err, null, 400);
            return;
        }

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

        $p = Response::getRequestParams();

        if ($err = $this->validateRelance($p)) {
            LoggingMiddleware::logExit(422);
            Response::error($err, null, 422);
            return;
        }

        if ($err = $this->validateEnc($p)) {
            LoggingMiddleware::logExit(400);
            Response::error($err, null, 400);
            return;
        }

        $fields = $this->extractFields($p, false);

        // Changer la date de relance rouvre le suivi : la marque « faite » est levée, sauf si
        // le client fournit lui-même relance_faite_le dans la même requête.
        if (array_key_exists('date_relance', $fields)
            && !array_key_exists('relance_faite_le', $fields)
            && (string) $fields['date_relance'] !== (string) ($contact['date_relance'] ?? '')) {
            $fields['relance_faite_le'] = null;
        }

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
    // GET /contacts/deleted — corbeille du propriétaire
    // ---------------------------------------------------------------
    public function listDeleted(array $user): void
    {
        LoggingMiddleware::logEntry();
        $pagination = Response::getPaginationParams();
        $contacts = $this->model->getDeletedByOwner(
            (int) $user['user_id'],
            $pagination['page'],
            $pagination['limit']
        );
        Response::success('Contacts supprimés récupérés', [
            'contacts' => array_map([$this, 'toContract'], $contacts),
            'count'    => count($contacts),
            'page'     => $pagination['page'],
            'limit'    => $pagination['limit'],
        ]);
    }

    // ---------------------------------------------------------------
    // POST /contacts/{id}/restore
    // ---------------------------------------------------------------
    public function restore(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();

        $contact = $this->model->findRawByIdAnyState($id);
        if (!$contact) {
            LoggingMiddleware::logExit(404);
            Response::error('Contact non trouvé', null, 404);
            return;
        }
        if ((int) $contact['user_id'] !== (int) $user['user_id']) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }
        if (empty($contact['supprime_le'])) {
            LoggingMiddleware::logExit(404);
            Response::error('Cette fiche n\'est pas supprimée', null, 404);
            return;
        }
        if (strtotime($contact['supprime_le']) < strtotime('-' . Contact::RESTORE_RETENTION_DAYS . ' days')) {
            LoggingMiddleware::logExit(404);
            Response::error('Fenêtre de restauration expirée', null, 404);
            return;
        }

        $this->model->restoreContact($id);
        $restored = $this->model->findContactById($id);
        LoggingMiddleware::logExit(200);
        Response::success('Contact restauré avec succès', ['contact' => $this->toContract($restored)]);
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
                // Fiche chiffrée : l'import n'a aucun moyen de fusionner avec le corps opaque.
                // L'écraser produirait une fiche mi-claire mi-chiffrée, indéchiffrable côté
                // client. On la laisse intacte (directive 20260804 §3).
                if (!empty($existant['enc_alg'])) {
                    $ignores++;
                    continue;
                }
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
