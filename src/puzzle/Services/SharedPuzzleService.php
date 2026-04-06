<?php

namespace Puzzle\Services;

/**
 * SharedPuzzleService — logique de génération de seed et état initial des pièces.
 */
class SharedPuzzleService
{
    /**
     * Génère un seed aléatoire (entier positif 6 chiffres).
     */
    public function generateSeed(): int
    {
        return random_int(100000, 999999);
    }

    /**
     * Génère un UUID v4.
     */
    public function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Génère les positions initiales des pièces à partir d'un seed.
     * Produit un positionnement pseudo-aléatoire reproductible.
     *
     * @param int $pieceCount  Nombre de pièces
     * @param int $seed        Seed aléatoire
     * @param int $boardWidth  Largeur du plateau (largeur logique maximale)
     * @param int $boardHeight Hauteur du plateau
     */
    public function generatePiecesFromSeed(int $pieceCount, int $seed, int $boardWidth = 800, int $boardHeight = 600): array
    {
        // Initialiser l'état PRNG avec le seed
        $state  = $seed;
        $pieces = [];

        for ($i = 0; $i < $pieceCount; $i++) {
            $state    = ($state * 1103515245 + 12345) & 0x7fffffff;
            $x        = ($state % $boardWidth);
            $state    = ($state * 1103515245 + 12345) & 0x7fffffff;
            $y        = ($state % $boardHeight);
            $state    = ($state * 1103515245 + 12345) & 0x7fffffff;
            $rotation = [0, 90, 180, 270][$state % 4];

            $pieces[] = [
                'piece_id' => $i,
                'x'        => (float) $x,
                'y'        => (float) $y,
                'rotation' => $rotation,
                'locked'   => false,
            ];
        }

        return $pieces;
    }
}
