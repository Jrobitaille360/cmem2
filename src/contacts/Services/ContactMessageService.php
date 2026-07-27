<?php

namespace Contacts\Services;

use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;
use Contacts\Models\Interaction;

/**
 * Service d'envoi de courriel depuis une fiche contact.
 * Directive cmem_web 20260724_090048 (Phase G-B).
 *
 * Réutilise l'infrastructure mail serveur (EmailService, SPF/DKIM déjà gérés).
 * Le courriel part de l'adresse serveur (From) avec Reply-To = courriel de l'usager
 * courant (« au nom de » le propriétaire de la fiche). Chaque envoi est journalisé
 * dans la table `interaction`.
 */
class ContactMessageService
{
    private Interaction $interactions;

    public function __construct()
    {
        $this->interactions = new Interaction();
    }

    /**
     * Résout le courriel principal d'un contact.
     * Priorité : 1er courriel de type 'pro', sinon 1er courriel disponible.
     *
     * @param array $courriels Liste [{type, valeur}, ...]
     * @return string|null Adresse retenue, ou null si aucune.
     */
    public static function resolvePrimaryEmail(array $courriels): ?string
    {
        $premier = null;
        foreach ($courriels as $c) {
            $valeur = trim((string) ($c['valeur'] ?? ''));
            if ($valeur === '') {
                continue;
            }
            if ($premier === null) {
                $premier = $valeur;
            }
            if (($c['type'] ?? null) === 'pro') {
                return $valeur; // pro prioritaire
            }
        }
        return $premier;
    }

    /**
     * Envoie le courriel et journalise l'interaction.
     *
     * @param array  $contact      Fiche contact hydratée (owner déjà vérifié en amont).
     * @param string $appId
     * @param int    $userId       Propriétaire de la fiche.
     * @param string $userEmail    Courriel de l'usager courant → Reply-To.
     * @param string $destinataire Adresse résolue et validée.
     * @param string $sujet
     * @param string $corps
     * @return array Interaction créée (hydratée).
     */
    public function sendEmail(
        array $contact,
        string $appId,
        int $userId,
        string $userEmail,
        string $destinataire,
        string $sujet,
        string $corps
    ): array {
        $emailService = new EmailService();

        // corps en texte brut ; Reply-To = usager courant.
        $ok = $emailService->sendEmail($destinataire, $sujet, $corps, false, $userEmail);

        $statut = $ok ? 'envoye' : 'echec';

        LogService::info('ContactMessageService: courriel contact', [
            'user_id'      => $userId,
            'contact_id'   => (int) $contact['id'],
            'destinataire' => $destinataire,
            'statut'       => $statut,
        ]);

        return $this->interactions->logEmail([
            'app_id'       => $appId,
            'user_id'      => $userId,
            'contact_id'   => (int) $contact['id'],
            'canal'        => 'email',
            'destinataire' => $destinataire,
            'sujet'        => $sujet,
            'corps'        => $corps,
            'statut'       => $statut,
        ]);
    }
}
