<?php

namespace Contacts\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Contacts\Models\Contact;
use Contacts\Models\Opportunite;

/**
 * Contrôleur du pipeline CRM — directive cmem_web 20260724_154618 (Phase G-D).
 *
 *   GET    /contacts/{id}/opportunites   → opportunités d'une fiche
 *   POST   /contacts/{id}/opportunites   → création
 *   GET    /opportunites?etape=          → board Kanban global du propriétaire
 *   PUT    /opportunites/{opId}          → mise à jour partielle (dont changement d'étape)
 *   DELETE /opportunites/{opId}          → soft-delete
 *
 * Portée owner-strict : la fiche et l'opportunité doivent appartenir à l'usager (sinon 403/404).
 */
class OpportuniteController
{
    private const DEFAULT_APP_ID = 'puzzle';

    private Opportunite $model;

    public function __construct()
    {
        $this->model = new Opportunite();
    }

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : self::DEFAULT_APP_ID;
    }

    /** Retourne la fiche si trouvée + propriété de l'usager, sinon envoie l'erreur et retourne null. */
    private function ownedContactOrFail(array $user, int $contactId): ?array
    {
        $contact = (new Contact())->findContactById($contactId);
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

    /** Retourne l'opportunité si trouvée + propriété de l'usager, sinon envoie l'erreur. */
    private function ownedOpportuniteOrFail(array $user, int $id): ?array
    {
        $opportunite = $this->model->findOpportuniteById($id);
        if (!$opportunite) {
            LoggingMiddleware::logExit(404);
            Response::error('Opportunité non trouvée', null, 404);
            return null;
        }
        if ((int) $opportunite['user_id'] !== (int) $user['user_id']) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return null;
        }
        return $opportunite;
    }

    private function toContract(array $o): array
    {
        return [
            'id'                  => (int) $o['id'],
            'app_id'              => $o['app_id'],
            'contact_id'          => (int) $o['contact_id'],
            'titre'               => $o['titre'],
            'etape'               => $o['etape'],
            'montant'             => $o['montant'] !== null ? (float) $o['montant'] : null,
            'devise'              => $o['devise'],
            'date_cloture_prevue' => $o['date_cloture_prevue'],
            'notes'               => $o['notes'],
            'cree_le'             => $o['cree_le'],
            'maj_le'              => $o['maj_le'],
        ];
    }

    /**
     * Extrait les champs écrivables. Retourne null (après envoi de l'erreur 422) si un
     * champ est invalide. En création, les défauts etape=prospect et devise=CAD s'appliquent.
     */
    private function extractFields(array $p, bool $isCreate): ?array
    {
        $fields = [];

        if (array_key_exists('titre', $p) || $isCreate) {
            $titre = trim((string) ($p['titre'] ?? ''));
            if ($titre === '') {
                LoggingMiddleware::logExit(422);
                Response::error('titre requis', null, 422);
                return null;
            }
            $fields['titre'] = $titre;
        }

        if (array_key_exists('etape', $p)) {
            $etape = strtolower(trim((string) $p['etape']));
            if (!Opportunite::isValidEtape($etape)) {
                LoggingMiddleware::logExit(422);
                Response::error('etape doit être parmi : ' . implode(', ', Opportunite::ETAPES), null, 422);
                return null;
            }
            $fields['etape'] = $etape;
        } elseif ($isCreate) {
            $fields['etape'] = 'prospect';
        }

        if (array_key_exists('montant', $p)) {
            if ($p['montant'] === null || $p['montant'] === '') {
                $fields['montant'] = null;
            } elseif (!is_numeric($p['montant'])) {
                LoggingMiddleware::logExit(422);
                Response::error('montant doit être numérique', null, 422);
                return null;
            } else {
                $fields['montant'] = round((float) $p['montant'], 2);
            }
        }

        if (array_key_exists('devise', $p)) {
            $devise = strtoupper(trim((string) $p['devise']));
            if (!preg_match('/^[A-Z]{3}$/', $devise)) {
                LoggingMiddleware::logExit(422);
                Response::error('devise doit être un code ISO 4217 (ex. CAD)', null, 422);
                return null;
            }
            $fields['devise'] = $devise;
        } elseif ($isCreate) {
            $fields['devise'] = 'CAD';
        }

        if (array_key_exists('date_cloture_prevue', $p)) {
            $date = trim((string) ($p['date_cloture_prevue'] ?? ''));
            if ($date === '') {
                $fields['date_cloture_prevue'] = null;
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                LoggingMiddleware::logExit(422);
                Response::error('date_cloture_prevue invalide (attendu Y-m-d)', null, 422);
                return null;
            } else {
                $fields['date_cloture_prevue'] = $date;
            }
        }

        if (array_key_exists('notes', $p)) {
            $notes = $p['notes'] === null ? null : trim((string) $p['notes']);
            $fields['notes'] = ($notes === '') ? null : $notes;
        }

        return $fields;
    }

    // ---------------------------------------------------------------
    // GET /contacts/{id}/opportunites
    // ---------------------------------------------------------------
    public function listForContact(array $user, int $contactId): void
    {
        LoggingMiddleware::logEntry();
        if (!$this->ownedContactOrFail($user, $contactId)) { return; }

        $p    = Response::getRequestParams();
        $rows = $this->model->findByContact($this->appId($p), (int) $user['user_id'], $contactId);

        Response::success('Opportunités récupérées', [
            'opportunites' => array_map([$this, 'toContract'], $rows),
        ]);
    }

    // ---------------------------------------------------------------
    // POST /contacts/{id}/opportunites
    // ---------------------------------------------------------------
    public function create(array $user, int $contactId): void
    {
        LoggingMiddleware::logEntry();
        if (!$this->ownedContactOrFail($user, $contactId)) { return; }

        $p      = Response::getRequestParams();
        $fields = $this->extractFields($p, true);
        if ($fields === null) { return; }

        $id = $this->model->createOpportunite(
            $this->appId($p),
            (int) $user['user_id'],
            $contactId,
            $fields
        );

        Response::success('Opportunité créée', [
            'opportunite' => $this->toContract($this->model->findOpportuniteById($id)),
        ], 201);
    }

    // ---------------------------------------------------------------
    // GET /opportunites?etape=  — board Kanban global
    // ---------------------------------------------------------------
    public function board(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p = Response::getRequestParams();

        $etape = trim((string) ($p['etape'] ?? ''));
        if ($etape !== '' && !Opportunite::isValidEtape(strtolower($etape))) {
            LoggingMiddleware::logExit(422);
            Response::error('etape doit être parmi : ' . implode(', ', Opportunite::ETAPES), null, 422);
            return;
        }

        $result = $this->model->findByOwner($this->appId($p), (int) $user['user_id'], [
            'etape'  => $etape !== '' ? strtolower($etape) : null,
            'limit'  => $p['limit']  ?? null,
            'offset' => $p['offset'] ?? null,
        ]);

        Response::success('Opportunités récupérées', [
            'opportunites' => array_map([$this, 'toContract'], $result['opportunites']),
            'total'        => $result['total'],
        ]);
    }

    // ---------------------------------------------------------------
    // PUT /opportunites/{opId}
    // ---------------------------------------------------------------
    public function update(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        if (!$this->ownedOpportuniteOrFail($user, $id)) { return; }

        $p      = Response::getRequestParams();
        $fields = $this->extractFields($p, false);
        if ($fields === null) { return; }

        $this->model->updateOpportunite($id, $fields);

        Response::success('Opportunité mise à jour', [
            'opportunite' => $this->toContract($this->model->findOpportuniteById($id)),
        ]);
    }

    // ---------------------------------------------------------------
    // DELETE /opportunites/{opId}  — soft-delete
    // ---------------------------------------------------------------
    public function delete(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        if (!$this->ownedOpportuniteOrFail($user, $id)) { return; }

        $this->model->softDeleteOpportunite($id);
        Response::success('Opportunité supprimée', ['id' => $id]);
    }
}
