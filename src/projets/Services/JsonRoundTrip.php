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

    public const DEP_TYPES = ['FS', 'SS', 'FF', 'SF'];

    /**
     * Clé normalisée d'un id de tâche du fichier (ou d'une référence parentId /
     * dependsOn[].taskId) : un même id écrit 9101 ou "9101" donne la même clé.
     * Numérique ou texte, existant ou temporaire — c'est la clé de remappage à l'import.
     * @return string|null null si absent/vide, clé sinon
     * @throws \RuntimeException si l'id n'est ni entier ni chaîne
     */
    public static function cle(mixed $id): ?string
    {
        if ($id === null) { return null; }
        if (is_int($id)) { return (string) $id; }
        if (is_string($id)) {
            $id = trim($id);
            if ($id === '') { return null; }
            return ctype_digit($id) ? (string) (int) $id : $id;
        }
        throw new \RuntimeException('id de tâche invalide (entier ou chaîne attendu) : ' . json_encode($id));
    }

    /**
     * @param array $payload     JSON décodé (export d'un client)
     * @param array $tachesCmem2 état actuel du projet (contrat §6, pour les orphelins)
     * @return array{aCreer: array, aMettreAJour: array, orphelins: array, liens: array, doublonsTitre: array}
     * @throws \RuntimeException payload invalide (→ 422)
     */
    public function planifier(array $payload, array $tachesCmem2): array
    {
        if (($payload['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('schemaVersion incompatible');
        }
        $rows = $payload['tasks'] ?? [];
        if (!is_array($rows)) { throw new \RuntimeException('tasks absent ou invalide'); }

        $idsExistants = []; $titresExistants = [];
        foreach ($tachesCmem2 as $t) {
            $idsExistants[(string) $t['id']] = true;
            $titresExistants[$this->normTitre((string) $t['title'])] = true;
        }

        // Passe A — validation des champs + inventaire des id du fichier
        $idsFichier = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) { throw new \RuntimeException("tasks[$i] doit être un objet"); }
            $this->valider($r);
            $cle = self::cle($r['id'] ?? null);
            if ($cle !== null) {
                if (isset($idsFichier[$cle])) { throw new \RuntimeException("id en double dans le fichier : $cle"); }
                $idsFichier[$cle] = true;
            }
        }

        // Passe B — références : fichier ou projet cible uniquement ; liens résolus
        $liens = [];
        $refConnue = static function ($ref) use ($idsFichier, $idsExistants): bool {
            $k = self::cle($ref);
            return $k !== null && (isset($idsFichier[$k]) || isset($idsExistants[$k]));
        };
        foreach ($rows as $r) {
            $de = $r['id'] ?? null;
            $parent = $r['parentId'] ?? null;
            if ($parent !== null) {
                if (!$refConnue($parent)) {
                    throw new \RuntimeException('parentId ' . json_encode($parent)
                        . ' ne référence ni une tâche du fichier ni une tâche du projet cible');
                }
                $liens[] = ['de' => $de, 'vers' => $parent, 'type' => 'parent'];
            }
            $deps = $r['dependsOn'] ?? [];
            if (!is_array($deps) || ($deps !== [] && array_keys($deps) !== range(0, count($deps) - 1))) {
                throw new \RuntimeException('dependsOn doit être un tableau de {taskId, type, lagDays}');
            }
            foreach ($deps as $d) {
                if (!is_array($d) || !array_key_exists('taskId', $d)) {
                    throw new \RuntimeException('dependsOn[] doit être {taskId, type, lagDays}, reçu : ' . json_encode($d));
                }
                $type = strtoupper((string) ($d['type'] ?? 'FS'));
                if (!in_array($type, self::DEP_TYPES, true)) {
                    throw new \RuntimeException('dependsOn[].type invalide : ' . json_encode($d['type']) . ' (FS|SS|FF|SF)');
                }
                if (isset($d['lagDays']) && !is_int($d['lagDays'])) {
                    throw new \RuntimeException('dependsOn[].lagDays doit être un entier');
                }
                if (!$refConnue($d['taskId'])) {
                    throw new \RuntimeException('dependsOn[].taskId ' . json_encode($d['taskId'])
                        . ' ne référence ni une tâche du fichier ni une tâche du projet cible');
                }
                $liens[] = ['de' => $de, 'vers' => $d['taskId'], 'type' => $type];
            }
        }

        // Passe C — classement : id existant dans le projet = mise à jour, sinon création
        $aCreer = []; $aMettreAJour = []; $vus = []; $doublonsTitre = [];
        foreach ($rows as $r) {
            $cle = self::cle($r['id'] ?? null);
            if ($cle !== null && isset($idsExistants[$cle])) {
                $vus[$cle] = true;
                $r['id'] = (int) $cle;
                $aMettreAJour[] = $r;
                continue;
            }
            $aCreer[] = $r; // id temporaire (numérique ou texte) remappé à l'insertion (§9.4)
            if (isset($titresExistants[$this->normTitre((string) $r['title'])])) {
                $doublonsTitre[] = $r['title'];
            }
        }

        $orphelins = array_values(array_filter(
            $tachesCmem2,
            static fn($t) => !isset($vus[(string) $t['id']])
        ));

        return [
            'aCreer'        => $aCreer,
            'aMettreAJour'  => $aMettreAJour,
            'orphelins'     => $orphelins,
            'liens'         => $liens,
            'doublonsTitre' => array_values(array_unique($doublonsTitre)),
        ];
    }

    private function normTitre(string $titre): string
    {
        return mb_strtolower(trim($titre));
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
