<?php

namespace Jesaisquand\Models;

use PDO;

/**
 * Paquets et cartes — tables jsq_paquets, jsq_cartes, jsq_cartes_journal.
 *
 * Les cartes sont exposées au format client de jesaisquand (src/lib/partie.ts) :
 * id texte (uid), date {annee, mois?, jour?}, dateTexte, periode, image, credit, imageSource…
 */
class Paquet
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \Database::getInstance()->getConnection();
    }

    public function db(): PDO
    {
        return $this->db;
    }

    // ------------------------------------------------------------------
    // Paquets
    // ------------------------------------------------------------------

    public function listPublics(): array
    {
        $rows = $this->db->query(
            "SELECT p.slug, p.titre, p.description, p.source, COUNT(c.id) AS nb_cartes
               FROM jsq_paquets p
               LEFT JOIN jsq_cartes c ON c.paquet_id = p.id
              WHERE p.public = 1
              GROUP BY p.id
              ORDER BY p.titre"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($r) => [
            'slug'        => $r['slug'],
            'titre'       => $r['titre'],
            'description' => $r['description'],
            'source'      => $r['source'],
            'nb_cartes'   => (int) $r['nb_cartes'],
        ], $rows);
    }

    public function findBySlug(string $slug): ?array
    {
        $st = $this->db->prepare("SELECT * FROM jsq_paquets WHERE slug = ?");
        $st->execute([$slug]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Cartes brutes (lignes jsq_cartes) d'un paquet, dans l'ordre d'import. */
    public function cartesDuPaquet(int $paquetId): array
    {
        $st = $this->db->prepare("SELECT * FROM jsq_cartes WHERE paquet_id = ? ORDER BY ordre, id");
        $st->execute([$paquetId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param int[] $ids → [id => ligne] */
    public function cartesParIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT * FROM jsq_cartes WHERE id IN ({$in})");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public function findCarte(int $paquetId, string $uid): ?array
    {
        $st = $this->db->prepare("SELECT * FROM jsq_cartes WHERE paquet_id = ? AND uid = ?");
        $st->execute([$paquetId, $uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Upsert d'un paquet et de ses cartes (import admin). Les cartes absentes de l'import
     * sont conservées (elles peuvent être en jeu dans une partie).
     *
     * @return array{paquet_cree: bool, cartes_crees: int, cartes_mises_a_jour: int}
     */
    public function importer(array $paquet, int $userId): array
    {
        $this->db->beginTransaction();
        try {
            $existant = $this->findBySlug($paquet['slug']);
            if ($existant) {
                $paquetId = (int) $existant['id'];
                $this->db->prepare(
                    "UPDATE jsq_paquets SET titre = ?, description = ?, source = ?, public = ? WHERE id = ?"
                )->execute([$paquet['titre'], $paquet['description'], $paquet['source'], $paquet['public'], $paquetId]);
            } else {
                $this->db->prepare(
                    "INSERT INTO jsq_paquets (slug, titre, description, source, owner_id, public) VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$paquet['slug'], $paquet['titre'], $paquet['description'], $paquet['source'], $userId, $paquet['public']]);
                $paquetId = (int) $this->db->lastInsertId();
            }

            $sel = $this->db->prepare("SELECT id FROM jsq_cartes WHERE paquet_id = ? AND uid = ?");
            $ins = $this->db->prepare(
                "INSERT INTO jsq_cartes (paquet_id, uid, ordre, titre, annee, mois, jour, date_texte, periode, approx,
                                         essentiel, categorie, description, image_url, image_source, credit)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $upd = $this->db->prepare(
                "UPDATE jsq_cartes SET ordre = ?, titre = ?, annee = ?, mois = ?, jour = ?, date_texte = ?, periode = ?,
                        approx = ?, essentiel = ?, categorie = ?, description = ?, image_url = ?, image_source = ?, credit = ?
                  WHERE id = ?"
            );

            $crees = 0;
            $majs  = 0;
            foreach ($paquet['cartes'] as $ordre => $c) {
                $vals = [
                    $ordre, $c['titre'], $c['annee'], $c['mois'], $c['jour'], $c['date_texte'], $c['periode'],
                    $c['approx'], $c['essentiel'], $c['categorie'], $c['description'], $c['image_url'],
                    $c['image_source'], $c['credit'],
                ];
                $sel->execute([$paquetId, $c['uid']]);
                $id = $sel->fetchColumn();
                if ($id) {
                    $upd->execute([...$vals, (int) $id]);
                    $majs++;
                } else {
                    $ins->execute([$paquetId, $c['uid'], ...$vals]);
                    $crees++;
                }
            }

            $this->db->commit();
            return ['paquet_cree' => !$existant, 'cartes_crees' => $crees, 'cartes_mises_a_jour' => $majs];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Édition admin (#526)
    // ------------------------------------------------------------------

    /** Met à jour l'illustration d'une carte et journalise l'ancienne et la nouvelle valeur. */
    public function majIllustration(array $carte, array $nouveau, int $userId): array
    {
        $champs = ['image_url', 'image_file_id', 'image_source', 'credit'];
        $ancien = [];
        foreach ($champs as $k) {
            $ancien[$k] = $carte[$k] === null ? null : ($k === 'image_file_id' ? (int) $carte[$k] : $carte[$k]);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE jsq_cartes SET image_url = ?, image_file_id = ?, image_source = ?, credit = ? WHERE id = ?"
            )->execute([$nouveau['image_url'], $nouveau['image_file_id'], $nouveau['image_source'], $nouveau['credit'], (int) $carte['id']]);

            $this->db->prepare(
                "INSERT INTO jsq_cartes_journal (carte_id, user_id, ancien, nouveau) VALUES (?, ?, ?, ?)"
            )->execute([
                (int) $carte['id'],
                $userId,
                json_encode($ancien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($nouveau, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $st = $this->db->prepare("SELECT * FROM jsq_cartes WHERE id = ?");
        $st->execute([(int) $carte['id']]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Cartes illustrées (image_url ou image_file_id) sans crédit. */
    public function cartesSansCredit(?int $paquetId): array
    {
        $sql = "SELECT c.*, p.slug AS paquet_slug
                  FROM jsq_cartes c JOIN jsq_paquets p ON p.id = c.paquet_id
                 WHERE (c.image_url IS NOT NULL AND c.image_url <> '' OR c.image_file_id IS NOT NULL)
                   AND (c.credit IS NULL OR TRIM(c.credit) = '')";
        $params = [];
        if ($paquetId !== null) {
            $sql     .= " AND c.paquet_id = ?";
            $params[] = $paquetId;
        }
        $sql .= " ORDER BY p.slug, c.ordre, c.id";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Fichier cmem2 utilisable comme illustration : image grand-public non supprimée. */
    public function fichierImagePublic(int $fileId): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM files WHERE id = ? AND deleted_at IS NULL AND accessibility = 'grand-public'
                AND (media_type = 'image' OR mime_type LIKE 'image/%')"
        );
        $st->execute([$fileId]);
        return (bool) $st->fetchColumn();
    }
}
