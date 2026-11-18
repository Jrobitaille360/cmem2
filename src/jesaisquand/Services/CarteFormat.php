<?php

namespace Jesaisquand\Services;

/**
 * Mise en forme des cartes au format client jesaisquand.
 *
 * - revelee() : carte complète, date comprise (paquet solo, frise, carte posée).
 * - cachee()  : carte en main — JAMAIS de date, dateTexte, periode ni description
 *               (la description peut citer l'année). Règle d'autorité du serveur (#524).
 */
final class CarteFormat
{
    public static function date(array $r): array
    {
        $d = ['annee' => (int) $r['annee']];
        if ($r['mois'] !== null) {
            $d['mois'] = (int) $r['mois'];
            if ($r['jour'] !== null) {
                $d['jour'] = (int) $r['jour'];
            }
        }
        return $d;
    }

    public static function image(array $r): ?string
    {
        if (!empty($r['image_file_id'])) {
            return rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/files/' . (int) $r['image_file_id'];
        }
        return $r['image_url'] !== null && $r['image_url'] !== '' ? $r['image_url'] : null;
    }

    public static function revelee(array $r): array
    {
        $c = [
            'id'    => $r['uid'],
            'titre' => $r['titre'],
            'date'  => self::date($r),
        ];
        if ($r['date_texte'] !== null) $c['dateTexte'] = $r['date_texte'];
        if ((int) $r['approx'] === 1) $c['approx'] = true;
        if ($r['periode'] !== null) $c['periode'] = $r['periode'];
        if ((int) $r['essentiel'] === 1) $c['essentiel'] = true;
        if ($r['categorie'] !== null) $c['categorie'] = $r['categorie'];
        if ($r['description'] !== null) $c['description'] = $r['description'];
        return $c + self::illustration($r);
    }

    public static function cachee(array $r): array
    {
        $c = ['id' => $r['uid'], 'titre' => $r['titre']];
        if ($r['categorie'] !== null) $c['categorie'] = $r['categorie'];
        return $c + self::illustration($r);
    }

    private static function illustration(array $r): array
    {
        $c   = [];
        $img = self::image($r);
        if ($img !== null) $c['image'] = $img;
        if ($r['credit'] !== null && $r['credit'] !== '') $c['credit'] = $r['credit'];
        if ($r['image_source'] !== null && $r['image_source'] !== '') $c['imageSource'] = $r['image_source'];
        if (!empty($r['image_file_id'])) $c['imageFileId'] = (int) $r['image_file_id'];
        return $c;
    }
}
