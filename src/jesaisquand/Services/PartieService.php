<?php

namespace Jesaisquand\Services;

use Jesaisquand\Models\Paquet;
use PDO;

/**
 * Parties multijoueur au tour par tour (#524). Le serveur fait autorité : les dates des
 * cartes en main ne sortent jamais avant la pose.
 *
 * Décisions (requérant, 2026-11-18) :
 *  - joueurs invités : pseudo + jeton joueur opaque (seul son SHA-256 est stocké) ; user_id
 *    renseigné si un JWT accompagne la création ou l'arrivée ;
 *  - pas de délai par tour ; une partie sans activité depuis 24 h passe à « expiree » ;
 *  - fin : le premier joueur à main vide gagne, le tour de table est complété, tous ceux
 *    qui ont vidé leur main dans ce tour sont gagnants (ex æquo).
 */
class PartieService
{
    public const JOUEURS_MAX       = 6;
    public const JOUEURS_MIN       = 2;
    public const TAILLE_MAIN_DEF   = 4;
    public const TAILLE_MAIN_MAX   = 8;
    public const EXPIRATION_HEURES = 24;
    public const PURGE_JOURS       = 7;
    private const ALPHABET_CODE    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private PDO $db;
    private Paquet $paquets;

    public function __construct(?Paquet $paquets = null)
    {
        $this->paquets = $paquets ?? new Paquet();
        $this->db      = $this->paquets->db();
    }

    // ------------------------------------------------------------------
    // Salon
    // ------------------------------------------------------------------

    public function creer(string $slug, string $pseudo, int $tailleMain, ?int $userId): array
    {
        $paquet = $this->paquets->findBySlug($slug);
        if (!$paquet || (int) $paquet['public'] !== 1) {
            throw new JsqException('Paquet introuvable', 404);
        }

        $this->db->prepare(
            "DELETE FROM jsq_parties WHERE updated_at < NOW() - INTERVAL " . self::PURGE_JOURS . " DAY"
        )->execute();

        $this->db->beginTransaction();
        try {
            $partieId = null;
            for ($essai = 0; $essai < 10 && $partieId === null; $essai++) {
                $code = $this->genererCode();
                try {
                    $this->db->prepare(
                        "INSERT INTO jsq_parties (code, paquet_id, taille_main, frise, pioche) VALUES (?, ?, ?, '[]', '[]')"
                    )->execute([$code, (int) $paquet['id'], $tailleMain]);
                    $partieId = (int) $this->db->lastInsertId();
                } catch (\PDOException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
            if ($partieId === null) {
                throw new JsqException('Impossible de générer un code de salon', 500);
            }
            [$joueurId, $jeton] = $this->ajouterJoueur($partieId, $pseudo, 1, $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['code' => $code, 'jeton' => $jeton, 'joueur_id' => $joueurId, 'ordre' => 1];
    }

    public function rejoindre(string $code, string $pseudo, ?int $userId): array
    {
        $this->db->beginTransaction();
        try {
            $partie = $this->partieParCode($code, true);
            $this->verifierStatut($partie, 'attente', 'La partie a déjà commencé');

            $st = $this->db->prepare("SELECT COUNT(*), COALESCE(MAX(ordre), 0) FROM jsq_joueurs WHERE partie_id = ?");
            $st->execute([(int) $partie['id']]);
            [$nb, $maxOrdre] = $st->fetch(PDO::FETCH_NUM);
            if ((int) $nb >= self::JOUEURS_MAX) {
                throw new JsqException('Salon complet (' . self::JOUEURS_MAX . ' joueurs maximum)', 409);
            }

            $ordre = (int) $maxOrdre + 1;
            [$joueurId, $jeton] = $this->ajouterJoueur((int) $partie['id'], $pseudo, $ordre, $userId);
            $this->bumpVersion((int) $partie['id']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['code' => $partie['code'], 'jeton' => $jeton, 'joueur_id' => $joueurId, 'ordre' => $ordre];
    }

    /** Authentifie un joueur par son jeton pour la partie $code. @return array{0: array, 1: array} */
    public function authentifier(string $code, string $jeton): array
    {
        $partie = $this->partieParCode($code);
        $st = $this->db->prepare("SELECT * FROM jsq_joueurs WHERE jeton_hash = ?");
        $st->execute([hash('sha256', $jeton)]);
        $joueur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$joueur || (int) $joueur['partie_id'] !== (int) $partie['id']) {
            throw new JsqException('Jeton joueur invalide pour cette partie', 403);
        }
        return [$this->expirerSiInactive($partie), $joueur];
    }

    public function demarrer(array $partie, array $joueur): array
    {
        if ((int) $joueur['ordre'] !== 1) {
            throw new JsqException('Seul le créateur du salon peut démarrer la partie', 403);
        }

        $this->db->beginTransaction();
        try {
            $partie = $this->partieParCode($partie['code'], true);
            $this->verifierStatut($partie, 'attente', 'La partie a déjà commencé');

            $joueurs = $this->joueurs((int) $partie['id']);
            if (count($joueurs) < self::JOUEURS_MIN) {
                throw new JsqException('Il faut au moins ' . self::JOUEURS_MIN . ' joueurs pour démarrer', 422);
            }

            $ids    = array_map(fn($c) => (int) $c['id'], $this->paquets->cartesDuPaquet((int) $partie['paquet_id']));
            $taille = (int) $partie['taille_main'];
            $requis = 1 + count($joueurs) * $taille;
            if (count($ids) < $requis) {
                throw new JsqException("Paquet trop petit : {$requis} cartes requises, " . count($ids) . ' disponibles', 422);
            }

            $pioche = $this->melanger($ids);
            $frise  = [array_shift($pioche)];
            $upd    = $this->db->prepare("UPDATE jsq_joueurs SET main = ? WHERE id = ?");
            foreach ($joueurs as $j) {
                $upd->execute([json_encode(array_splice($pioche, 0, $taille)), (int) $j['id']]);
            }

            $this->db->prepare(
                "UPDATE jsq_parties SET statut = 'en_cours', frise = ?, pioche = ?, tour_joueur_id = ?, version = version + 1
                  WHERE id = ?"
            )->execute([json_encode($frise), json_encode($pioche), (int) $joueurs[0]['id'], (int) $partie['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->etat($this->partieParCode($partie['code']), $this->joueur((int) $joueur['id']));
    }

    // ------------------------------------------------------------------
    // État
    // ------------------------------------------------------------------

    public function etat(array $partie, array $moi): array
    {
        $joueurs = $this->joueurs((int) $partie['id']);
        $frise   = $this->ids($partie['frise']);
        $maMain  = $this->ids($this->joueur((int) $moi['id'])['main'] ?? null);
        $cartes  = $this->paquets->cartesParIds([...$frise, ...$maMain]);

        $st = $this->db->prepare("SELECT slug, titre FROM jsq_paquets WHERE id = ?");
        $st->execute([(int) $partie['paquet_id']]);
        $paquet = $st->fetch(PDO::FETCH_ASSOC) ?: ['slug' => null, 'titre' => null];

        $st = $this->db->prepare("SELECT * FROM jsq_coups WHERE partie_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([(int) $partie['id']]);
        $dernier = $st->fetch(PDO::FETCH_ASSOC);
        $dernierCoup = null;
        if ($dernier) {
            $carte = $this->paquets->cartesParIds([(int) $dernier['carte_id']])[(int) $dernier['carte_id']] ?? null;
            $dernierCoup = [
                'joueur_id'   => (int) $dernier['joueur_id'],
                'carte'       => $carte ? CarteFormat::revelee($carte) : null,
                'correct'     => (bool) $dernier['correct'],
                'index_final' => (int) $dernier['index_final'],
                'penalite'    => $dernier['penalite_carte_id'] !== null,
            ];
        }

        return [
            'code'            => $partie['code'],
            'statut'          => $partie['statut'],
            'version'         => (int) $partie['version'],
            'paquet'          => $paquet,
            'taille_main'     => (int) $partie['taille_main'],
            'frise'           => array_values(array_map(fn($id) => CarteFormat::revelee($cartes[$id]), array_filter($frise, fn($id) => isset($cartes[$id])))),
            'moi'             => [
                'joueur_id' => (int) $moi['id'],
                'pseudo'    => $moi['pseudo'],
                'ordre'     => (int) $moi['ordre'],
                'createur'  => (int) $moi['ordre'] === 1,
                'main'      => array_values(array_map(fn($id) => CarteFormat::cachee($cartes[$id]), array_filter($maMain, fn($id) => isset($cartes[$id])))),
            ],
            'joueurs'         => array_map(fn($j) => [
                'joueur_id'   => (int) $j['id'],
                'pseudo'      => $j['pseudo'],
                'ordre'       => (int) $j['ordre'],
                'taille_main' => count($this->ids($j['main'])),
                'gagnant'     => (bool) $j['gagnant'],
            ], $joueurs),
            'tour_joueur_id'  => $partie['tour_joueur_id'] !== null ? (int) $partie['tour_joueur_id'] : null,
            'pioche_restante' => count($this->ids($partie['pioche'])),
            'dernier_coup'    => $dernierCoup,
        ];
    }

    // ------------------------------------------------------------------
    // Coups
    // ------------------------------------------------------------------

    public function jouer(array $partie, array $joueur, string $carteUid, int $index): array
    {
        $this->db->beginTransaction();
        try {
            $partie = $this->partieParCode($partie['code'], true);
            $this->verifierStatut($partie, 'en_cours', $partie['statut'] === 'attente'
                ? "La partie n'a pas commencé" : 'La partie est terminée');

            if ((int) $partie['tour_joueur_id'] !== (int) $joueur['id']) {
                throw new JsqException("Ce n'est pas votre tour", 403);
            }

            $joueur = $this->joueur((int) $joueur['id']);
            $main   = $this->ids($joueur['main']);
            $frise  = $this->ids($partie['frise']);
            $pioche = $this->ids($partie['pioche']);
            $cartes = $this->paquets->cartesParIds([...$main, ...$frise]);

            $carteId = null;
            foreach ($main as $id) {
                if (isset($cartes[$id]) && $cartes[$id]['uid'] === $carteUid) {
                    $carteId = $id;
                    break;
                }
            }
            if ($carteId === null) {
                throw new JsqException('Carte absente de votre main', 422, ['carte_id' => 'Carte absente de votre main']);
            }

            $datesFrise = array_map(fn($id) => CarteFormat::date($cartes[$id]), $frise);
            if (!Regles::indexValide($datesFrise, $index)) {
                throw new JsqException('Index hors frise', 422, ['index' => 'Doit être entre 0 et ' . count($frise)]);
            }

            $date       = CarteFormat::date($cartes[$carteId]);
            $correct    = Regles::poseValide($datesFrise, $date, $index);
            $indexFinal = $correct ? $index : Regles::indexCorrect($datesFrise, $date);
            array_splice($frise, $indexFinal, 0, [$carteId]);
            $main = array_values(array_filter($main, fn($id) => $id !== $carteId));

            $penaliteId = null;
            if (!$correct && $pioche) {
                $penaliteId = array_shift($pioche);
                $main[]     = $penaliteId;
            }

            $gagnant = count($main) === 0;
            $this->db->prepare("UPDATE jsq_joueurs SET main = ?, gagnant = ? WHERE id = ?")
                ->execute([json_encode($main), $gagnant ? 1 : 0, (int) $joueur['id']]);

            $this->db->prepare(
                "INSERT INTO jsq_coups (partie_id, joueur_id, carte_id, index_demande, index_final, correct, penalite_carte_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([(int) $partie['id'], (int) $joueur['id'], $carteId, $index, $indexFinal, $correct ? 1 : 0, $penaliteId]);

            // Tour suivant — la partie se termine à la fin du tour de table où quelqu'un a vidé sa main
            $joueurs   = $this->joueurs((int) $partie['id']);
            $ordres    = array_map(fn($j) => (int) $j['ordre'], $joueurs);
            $finDeTour = (int) $joueur['ordre'] === max($ordres);
            $unGagnant = (bool) array_filter($joueurs, fn($j) => (int) $j['gagnant'] === 1);
            $suivant   = null;
            foreach ($joueurs as $j) {
                if ((int) $j['ordre'] > (int) $joueur['ordre']) {
                    $suivant = (int) $j['id'];
                    break;
                }
            }
            $suivant ??= (int) $joueurs[0]['id'];
            $terminee = $finDeTour && $unGagnant;

            $this->db->prepare(
                "UPDATE jsq_parties SET frise = ?, pioche = ?, tour_joueur_id = ?, statut = ?, version = version + 1 WHERE id = ?"
            )->execute([
                json_encode($frise),
                json_encode($pioche),
                $terminee ? null : $suivant,
                $terminee ? 'terminee' : 'en_cours',
                (int) $partie['id'],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $resultat = [
            'correct'     => $correct,
            'index_final' => $indexFinal,
            'date'        => $date,
            'carte'       => CarteFormat::revelee($cartes[$carteId]),
            'statut'      => $terminee ? 'terminee' : 'en_cours',
        ];
        if ($penaliteId !== null) {
            $pen = $this->paquets->cartesParIds([$penaliteId])[$penaliteId];
            $resultat['penalite_carte'] = CarteFormat::cachee($pen);
        }
        return $resultat;
    }

    // ------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------

    private function partieParCode(string $code, bool $verrou = false): array
    {
        $st = $this->db->prepare("SELECT * FROM jsq_parties WHERE code = ?" . ($verrou ? ' FOR UPDATE' : ''));
        $st->execute([strtoupper($code)]);
        $partie = $st->fetch(PDO::FETCH_ASSOC);
        if (!$partie) {
            throw new JsqException('Partie introuvable', 404);
        }
        return $verrou ? $partie : $this->expirerSiInactive($partie);
    }

    private function expirerSiInactive(array $partie): array
    {
        if (!in_array($partie['statut'], ['attente', 'en_cours'], true)) {
            return $partie;
        }
        $st = $this->db->prepare(
            "UPDATE jsq_parties SET statut = 'expiree', tour_joueur_id = NULL, version = version + 1
              WHERE id = ? AND statut IN ('attente', 'en_cours')
                AND updated_at < NOW() - INTERVAL " . self::EXPIRATION_HEURES . " HOUR"
        );
        $st->execute([(int) $partie['id']]);
        if ($st->rowCount() > 0) {
            $st = $this->db->prepare("SELECT * FROM jsq_parties WHERE id = ?");
            $st->execute([(int) $partie['id']]);
            return $st->fetch(PDO::FETCH_ASSOC);
        }
        return $partie;
    }

    private function verifierStatut(array $partie, string $attendu, string $message): void
    {
        if ($partie['statut'] === 'expiree') {
            throw new JsqException('Partie expirée (inactive depuis ' . self::EXPIRATION_HEURES . ' h)', 410);
        }
        if ($partie['statut'] !== $attendu) {
            throw new JsqException($message, 409);
        }
    }

    private function ajouterJoueur(int $partieId, string $pseudo, int $ordre, ?int $userId): array
    {
        $jeton = bin2hex(random_bytes(32));
        $this->db->prepare(
            "INSERT INTO jsq_joueurs (partie_id, user_id, pseudo, jeton_hash, ordre, main) VALUES (?, ?, ?, ?, ?, '[]')"
        )->execute([$partieId, $userId, $pseudo, hash('sha256', $jeton), $ordre]);
        return [(int) $this->db->lastInsertId(), $jeton];
    }

    private function joueurs(int $partieId): array
    {
        $st = $this->db->prepare("SELECT * FROM jsq_joueurs WHERE partie_id = ? ORDER BY ordre");
        $st->execute([$partieId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function joueur(int $id): array
    {
        $st = $this->db->prepare("SELECT * FROM jsq_joueurs WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function bumpVersion(int $partieId): void
    {
        $this->db->prepare("UPDATE jsq_parties SET version = version + 1 WHERE id = ?")->execute([$partieId]);
    }

    /** @return int[] */
    private function ids(?string $json): array
    {
        $v = $json ? json_decode($json, true) : [];
        return is_array($v) ? array_map('intval', $v) : [];
    }

    private function melanger(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    private function genererCode(): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET_CODE[random_int(0, strlen(self::ALPHABET_CODE) - 1)];
        }
        return $code;
    }
}
