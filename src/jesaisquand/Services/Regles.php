<?php

namespace Jesaisquand\Services;

/**
 * Règles de pose — portage PHP fidèle de jesaisquand/src/lib/partie.ts et dates.ts.
 *
 * Une date est un tableau ['annee' => int, 'mois' => ?int, 'jour' => ?int] (année < 0 = av. J.-C.).
 * Deux dates sont comparées à leur précision commune : « 1969 » = « 1969-07-20 ».
 * Fonctions pures, sans dépendance : testées hors ligne par private/tests/test_jesaisquand_regles.php.
 */
final class Regles
{
    public static function compareDates(array $a, array $b): int
    {
        if ((int) $a['annee'] !== (int) $b['annee']) {
            return (int) $a['annee'] <=> (int) $b['annee'];
        }
        if (!isset($a['mois'], $b['mois'])) {
            return 0;
        }
        if ((int) $a['mois'] !== (int) $b['mois']) {
            return (int) $a['mois'] <=> (int) $b['mois'];
        }
        if (!isset($a['jour'], $b['jour'])) {
            return 0;
        }
        return (int) $a['jour'] <=> (int) $b['jour'];
    }

    /** L'index demandé est-il dans la frise (0 = avant la première, n = après la dernière) ? */
    public static function indexValide(array $frise, int $index): bool
    {
        return $index >= 0 && $index <= count($frise);
    }

    /**
     * Pose valide si la carte n'est ni avant sa voisine de gauche ni après sa voisine de droite.
     * Égalités acceptées des deux côtés.
     *
     * @param array[] $frise dates triées
     */
    public static function poseValide(array $frise, array $date, int $index): bool
    {
        $gauche = $frise[$index - 1] ?? null;
        $droite = $frise[$index] ?? null;
        if ($gauche !== null && self::compareDates($gauche, $date) > 0) {
            return false;
        }
        if ($droite !== null && self::compareDates($date, $droite) > 0) {
            return false;
        }
        return true;
    }

    /** Premier index où la carte est strictement antérieure : insertion après les égalités. */
    public static function indexCorrect(array $frise, array $date): int
    {
        foreach (array_values($frise) as $i => $f) {
            if (self::compareDates($date, $f) < 0) {
                return $i;
            }
        }
        return count($frise);
    }
}
