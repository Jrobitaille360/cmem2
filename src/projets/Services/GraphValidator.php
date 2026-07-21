<?php

namespace Projets\Services;

/**
 * Vérifie l'acyclicité (§1.4, Annexe D) : arbre pour parentId, DAG pour dependsOn[].
 */
final class GraphValidator
{
    /**
     * @param array<int,array<string,mixed>> $taches contrat §6, ids réels résolus
     * @throws \RuntimeException si cycle détecté
     */
    public function assertAcyclique(array $taches): void
    {
        $parent = []; $deps = [];
        foreach ($taches as $t) {
            $id = $t['id'];
            $parent[$id] = $t['parentId'] ?? null;
            $deps[$id]   = array_map(static fn($d) => $d['taskId'], $t['dependsOn'] ?? []);
        }

        // Hiérarchie : remonter les parents, un cycle réapparaît sur un id déjà vu
        foreach (array_keys($parent) as $start) {
            $vus = []; $cur = $start;
            while ($cur !== null) {
                if (isset($vus[$cur])) {
                    throw new \RuntimeException("Cycle hiérarchie sur #$cur");
                }
                $vus[$cur] = true;
                $cur = $parent[$cur] ?? null;
            }
        }

        // Dépendances : DFS avec 3 couleurs (blanc/gris/noir)
        $couleur = [];
        $visiter = function ($id) use (&$visiter, &$couleur, &$deps) {
            $couleur[$id] = 'gris';
            foreach ($deps[$id] ?? [] as $suiv) {
                $c = $couleur[$suiv] ?? 'blanc';
                if ($c === 'gris') { throw new \RuntimeException("Cycle dépendances sur #$suiv"); }
                if ($c === 'blanc') { $visiter($suiv); }
            }
            $couleur[$id] = 'noir';
        };
        foreach (array_keys($deps) as $id) {
            if (($couleur[$id] ?? 'blanc') === 'blanc') { $visiter($id); }
        }
    }
}
