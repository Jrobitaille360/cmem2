<?php

namespace Contacts\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Contacts\Controllers\ContactController;

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
