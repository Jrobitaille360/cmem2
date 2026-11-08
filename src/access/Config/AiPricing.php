<?php

namespace Access\Config;

/**
 * Tarifs Claude par modèle, en dollars US par million de tokens (API Anthropic, tarif standard).
 *
 * Plan accès sur approbation, Phase 1 (budget IA en dollars). Mise à jour MANUELLE quand
 * AI_SUMMARIZE_MODEL change ou quand Anthropic modifie ses prix — source :
 * https://platform.claude.com/docs/en/about-claude/pricing (relevé du 2026-11-08).
 *
 * Un modèle absent de la table est facturé au tarif le plus cher connu : le budget
 * ne doit jamais laisser passer un appel sous-estimé.
 */
final class AiPricing
{
    /**
     * input / output : $ par million de tokens.
     * long_threshold : au-delà de ce nombre de tokens d'entrée, toute la requête passe
     * au tarif long_input / long_output (Haiku 5.5 : prompts > 100 k tokens).
     */
    private const PRICES = [
        'claude-fable-5-1'  => ['input' => 10.00, 'output' => 50.00],
        'claude-fable-5'    => ['input' => 10.00, 'output' => 50.00],
        'claude-opus-5-5'   => ['input' => 4.00,  'output' => 20.00],
        'claude-opus-5'     => ['input' => 5.00,  'output' => 25.00],
        'claude-opus-4-8'   => ['input' => 5.00,  'output' => 25.00],
        'claude-sonnet-5-5' => ['input' => 2.00,  'output' => 10.00],
        'claude-sonnet-5'   => ['input' => 2.00,  'output' => 10.00],
        'claude-sonnet-4-6' => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-5-5'  => ['input' => 0.10,  'output' => 0.50,
                                'long_threshold' => 100_000, 'long_input' => 0.50, 'long_output' => 2.50],
        'claude-haiku-4-5'  => ['input' => 1.00,  'output' => 5.00],
    ];

    public static function isKnown(string $model): bool
    {
        return isset(self::PRICES[$model]);
    }

    /** Coût en dollars d'un appel (réel après l'appel, ou estimation max avant : input estimé + max_tokens). */
    public static function cost(string $model, int $inputTokens, int $outputTokens): float
    {
        $p = self::PRICES[$model] ?? self::mostExpensive();

        $inRate  = $p['input'];
        $outRate = $p['output'];
        if (isset($p['long_threshold']) && $inputTokens > $p['long_threshold']) {
            $inRate  = $p['long_input'];
            $outRate = $p['long_output'];
        }

        return ($inputTokens * $inRate + $outputTokens * $outRate) / 1_000_000;
    }

    /** @return array{input: float, output: float} */
    private static function mostExpensive(): array
    {
        $max = ['input' => 0.0, 'output' => 0.0];
        foreach (self::PRICES as $p) {
            if ($p['input'] + $p['output'] > $max['input'] + $max['output']) {
                $max = ['input' => $p['input'], 'output' => $p['output']];
            }
        }
        return $max;
    }
}
