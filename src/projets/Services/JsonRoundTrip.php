<?php

namespace Projets\Services;

/**
 * Round-trip JSON — export (contrat §6 tel quel) et plan de fusion à l'import (§9.3).
 * Aucune écriture en base ici : planifier() produit le diff que l'appelant
 * applique dans une transaction (voir ProjectController::importJsonConfirm).
 */
final class JsonRoundTrip
{
    public const SCHEMA_VERSION = 1;

    public function export(array $projet, array $taches): array
    {
        return [
            'project'       => $projet,
            'tasks'         => array_values($taches),
            'exportedAt'    => gmdate('Y-m-d\TH:i:s\Z'),
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param array $payload     JSON décodé (export d'un client)
     * @param array $tachesCmem2 état actuel du projet (contrat §6, pour les orphelins)
     * @return array{aCreer: array, aMettreAJour: array, orphelins: array}
     */
    public function planifier(array $payload, array $tachesCmem2): array
    {
        if (($payload['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('schemaVersion incompatible');
        }
        $rows = $payload['tasks'] ?? [];
        if (!is_array($rows)) { throw new \RuntimeException('tasks absent ou invalide'); }

        $idsExistants = [];
        foreach ($tachesCmem2 as $t) { $idsExistants[$t['id']] = true; }

        $aCreer = []; $aMettreAJour = []; $vus = [];
        foreach ($rows as $r) {
            $this->valider($r);
            $id = $r['id'] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if (isset($idsExistants[$id])) {
                    $vus[$id] = true;
                    $r['id'] = $id;
                    $aMettreAJour[] = $r;
                    continue;
                }
            }
            $aCreer[] = $r; // id temporaire résolu à l'insertion (§9.4)
        }

        $orphelins = array_values(array_filter(
            $tachesCmem2,
            static fn($t) => !isset($vus[$t['id']])
        ));

        return ['aCreer' => $aCreer, 'aMettreAJour' => $aMettreAJour, 'orphelins' => $orphelins];
    }

    /**
     * Diff champ par champ entre la tâche existante (contrat §6) et la ligne importée.
     * Ignore id/createdAt/updatedAt (gérés serveur) ; ne compare que les champs
     * présents dans $importe (un payload partiel n'accuse pas les champs absents).
     * @return array<int, array{champ: string, avant: mixed, apres: mixed}>
     */
    public function diffChamps(array $existant, array $importe): array
    {
        $ignores = ['id', 'createdAt', 'updatedAt'];
        $champs = [];
        foreach ($importe as $champ => $apres) {
            if (in_array($champ, $ignores, true)) { continue; }
            $avant = $existant[$champ] ?? null;
            if ($avant !== $apres) {
                $champs[] = ['champ' => $champ, 'avant' => $avant, 'apres' => $apres];
            }
        }
        return $champs;
    }

    /** Validation applicative minimale d'une tâche (statut, priorité, %, title). */
    private function valider(array $t): void
    {
        $statuts = ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'];
        if (isset($t['status']) && !in_array($t['status'], $statuts, true)) {
            throw new \RuntimeException('Statut invalide : ' . $t['status']);
        }
        if (isset($t['priority']) && ($t['priority'] < 0 || $t['priority'] > 9)) {
            throw new \RuntimeException('Priorité hors 0..9');
        }
        if (isset($t['percentComplete']) && ($t['percentComplete'] < 0 || $t['percentComplete'] > 100)) {
            throw new \RuntimeException('percentComplete hors 0..100');
        }
        if (!isset($t['title']) || $t['title'] === '') {
            throw new \RuntimeException('title requis');
        }
    }
}
