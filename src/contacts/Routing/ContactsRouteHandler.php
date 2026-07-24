<?php

namespace Contacts\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Contacts\Controllers\ContactController;
use Contacts\Controllers\OpportuniteController;

/**
 * ContactsRouteHandler — routes /contacts/*
 *
 *   GET    /contacts             → liste (filtres ?q= ?categorie= ?favori= ?limit= ?offset=)
 *   POST   /contacts             → création (cap max_contacts)
 *   POST   /contacts/import      → import vCard ou CSV
 *   GET    /contacts/{id}        → fiche complète
 *   GET    /contacts/{id}.vcf    → export vCard 4.0
 *   PUT    /contacts/{id}        → mise à jour partielle
 *   DELETE /contacts/{id}        → soft-delete
 *   GET    /contacts/{id}/messages          → historique courriels
 *   POST   /contacts/{id}/messages          → envoi courriel
 *   GET    /contacts/{id}/interactions      → historique CRM unifié (filtres ?type= ?limit= ?offset=)
 *   POST   /contacts/{id}/interactions      → saisie manuelle (appel/note/rdv/sms)
 *   DELETE /contacts/{id}/interactions/{iid} → soft-delete d'une interaction
 *   GET    /contacts/{id}/opportunites      → opportunités CRM de la fiche
 *   POST   /contacts/{id}/opportunites      → création d'une opportunité
 *
 * Toutes les routes exigent un JWT valide.
 */
class ContactsRouteHandler extends BaseRouteHandler
{
    protected function getSupportedControllers(): array
    {
        return ['contacts'];
    }

    protected function handleRoute(array $request): void
    {
        $user   = $request['user'];
        $method = $request['method'] ?? 'GET';
        $segs   = $request['segments'] ?? [];

        // segs[0] = 'contacts'
        $s1 = $segs[1] ?? ''; // '' | id | '{id}.vcf' | 'import'

        // -------------------------------------------------
        // /contacts
        // -------------------------------------------------
        if ($s1 === '') {
            match ($method) {
                'GET'   => (new ContactController())->list($user),
                'POST'  => (new ContactController())->create($user),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // -------------------------------------------------
        // /contacts/import
        // -------------------------------------------------
        if ($s1 === 'import') {
            if ($method !== 'POST') {
                Response::error('Méthode non autorisée', null, 405);
                return;
            }
            (new ContactController())->import($user);
            return;
        }

        // -------------------------------------------------
        // /contacts/{id}.vcf
        // -------------------------------------------------
        if (preg_match('/^(\d+)\.vcf$/', $s1, $m)) {
            if ($method !== 'GET') {
                Response::error('Méthode non autorisée', null, 405);
                return;
            }
            (new ContactController())->exportVcf($user, (int) $m[1]);
            return;
        }

        // -------------------------------------------------
        // /contacts/{id}
        // -------------------------------------------------
        if (!is_numeric($s1)) {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }
        $contactId = (int) $s1;

        // -------------------------------------------------
        // /contacts/{id}/messages  — envoi courriel + historique
        // -------------------------------------------------
        $s2 = $segs[2] ?? '';
        if ($s2 === 'messages') {
            match ($method) {
                'POST' => (new ContactController())->sendMessage($user, $contactId),
                'GET'  => (new ContactController())->listMessages($user, $contactId),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // -------------------------------------------------
        // /contacts/{id}/interactions[/{interactionId}]  — CRM (Phase G-C)
        // -------------------------------------------------
        if ($s2 === 'interactions') {
            $s3 = $segs[3] ?? '';
            if ($s3 === '') {
                match ($method) {
                    'GET'  => (new ContactController())->listInteractions($user, $contactId),
                    'POST' => (new ContactController())->createInteraction($user, $contactId),
                    default => Response::error('Méthode non autorisée', null, 405),
                };
                return;
            }
            if (!is_numeric($s3)) {
                Response::error('Endpoint non trouvé', null, 404);
                return;
            }
            if ($method === 'DELETE') {
                (new ContactController())->deleteInteraction($user, $contactId, (int) $s3);
                return;
            }
            Response::error('Méthode non autorisée', null, 405);
            return;
        }

        // -------------------------------------------------
        // /contacts/{id}/opportunites  — CRM pipeline (Phase G-D)
        // -------------------------------------------------
        if ($s2 === 'opportunites') {
            if (($segs[3] ?? '') !== '') {
                Response::error('Endpoint non trouvé', null, 404);
                return;
            }
            match ($method) {
                'GET'  => (new OpportuniteController())->listForContact($user, $contactId),
                'POST' => (new OpportuniteController())->create($user, $contactId),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        if ($s2 !== '') {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        match ($method) {
            'GET'    => (new ContactController())->show($user, $contactId),
            'PUT'    => (new ContactController())->update($user, $contactId),
            'PATCH'  => (new ContactController())->update($user, $contactId),
            'DELETE' => (new ContactController())->delete($user, $contactId),
            default  => Response::error('Méthode non autorisée', null, 405),
        };
    }
}
