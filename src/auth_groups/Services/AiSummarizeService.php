<?php

namespace AuthGroups\Services;

use Anthropic\Client;

/**
 * Proxy IA — résumé d'agenda. Directive cmem_web 20260810_140000_ai-proxy.
 *
 * Consigne de résumé fixée ici (jamais transmise par le client) — évite l'injection
 * de prompt et le détournement du proxy pour un usage hors périmètre. Le corps envoyé
 * au modèle ne contient que les métadonnées assemblées côté client (period + items),
 * jamais de corps de journal ni de texte libre du client.
 */
class AiSummarizeService
{
    private const SYSTEM_PROMPT =
        "Tu résumes un agenda personnel à partir de métadonnées structurées (titres, dates, "
        . "tags, statuts) — jamais de texte libre ni de contenu de journal. Rédige un résumé "
        . "court en français, 2 à 4 phrases, orienté sur les échéances et le volume de travail "
        . "de la période. N'invente aucune information absente des données fournies.";

    /**
     * @param array $period {start, end}
     * @param array $items  Métadonnées d'événements/tâches (voir contrat de la directive)
     * @return array{summary: string, output_tokens: int|null}
     */
    public static function summarize(array $period, array $items): array
    {
        $client = new Client(apiKey: ANTHROPIC_API_KEY);

        $userContent = json_encode(
            ['period' => $period, 'items' => $items],
            JSON_UNESCAPED_UNICODE
        );

        $message = $client->messages->create(
            model: AI_SUMMARIZE_MODEL,
            maxTokens: 1024,
            system: self::SYSTEM_PROMPT,
            messages: [
                ['role' => 'user', 'content' => $userContent],
            ],
        );

        $summary = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $summary .= $block->text;
            }
        }

        return [
            'summary'       => trim($summary),
            'output_tokens' => $message->usage->outputTokens ?? null,
        ];
    }
}
