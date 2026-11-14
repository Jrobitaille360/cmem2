<?php

namespace Assistant\Services;

use Access\Services\AiBudgetService;
use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ToolUseBlock;
use Assistant\Models\AssistantStore;
use AuthGroups\Services\LogService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Boucle de conversation avec Claude (tâches #257, #258, #263, #264).
 *
 * Un message usager = un ou plusieurs tours d'appel modèle (boucle d'outils, au plus
 * AI_ASSISTANT_MAX_TOOL_ROUNDS). Chaque tour réserve son coût maximal dans le budget IA
 * commun (AiBudgetService, feature « assistant ») puis l'ajuste au coût réel.
 *
 * Les événements sont transmis à $emit(string $event, array $data) au fil du streaming :
 *   conversation {conversation_id} · delta {text} · tool {action} · done {…} · error {code, message}
 * Le contrôleur les écrit en SSE, ou les ignore et renvoie le résultat final en JSON.
 *
 * Erreurs levées AVANT le premier événement (aucune sortie encore écrite) :
 *   AssistantBudgetException (429) · AssistantUpstreamException (502).
 * Après le premier événement, une erreur devient un événement « error » suivi de « done ».
 */
class AssistantChatService
{
    private const SYSTEM_PROMPT = <<<'TXT'
Tu es l'assistant de l'application Journaux de Bord. Tu aides l'usager à gérer ses tâches,
ses rappels et à consulter son agenda, en français, avec des réponses courtes.

Règles :
- Pour toute date relative (« demain », « mardi prochain », « dans 15 jours »), calcule-la à partir
  de la date et de l'heure courantes indiquées ci-dessous, dans le fuseau de l'usager.
  En cas d'ambiguïté réelle (heure absente pour un rendez-vous, « mardi » sans précision
  alors qu'on est mardi), pose une question au lieu de deviner.
- Avant de modifier une tâche existante, retrouve son identifiant avec list_tasks.
- Tu ne peux rien supprimer directement : propose_delete crée une demande que l'usager doit
  confirmer dans l'application. Dis-le clairement.
- Après une action, confirme en une phrase ce qui a été fait (titre, date, rappel).
- Les tâches chiffrées sont illisibles pour toi : dis-le si l'usager en parle.
- Tu n'as accès qu'aux tâches et à l'agenda de l'usager ; décline poliment les demandes hors de ce périmètre
  si elles demandent d'agir, mais réponds normalement aux questions générales courtes.
TXT;

    private AssistantStore $store;
    private AiBudgetService $budget;

    public function __construct(?AssistantStore $store = null, ?AiBudgetService $budget = null)
    {
        $this->store  = $store ?? new AssistantStore();
        $this->budget = $budget ?? new AiBudgetService();
    }

    /**
     * @param callable(string, array): void $emit
     * @return array{conversation_id: int, reply: string, actions: array, budget: array, stop_reason: ?string}
     */
    public function run(int $userId, ?int $conversationId, string $message, callable $emit): array
    {
        $model     = AI_ASSISTANT_MODEL;
        $maxTokens = AI_ASSISTANT_MAX_TOKENS;

        // Historique (texte seul) avant d'ajouter le nouveau message.
        $history = $conversationId !== null ? $this->store->messages($conversationId, AI_ASSISTANT_HISTORY_LIMIT) : [];
        while ($history && $history[0]['role'] !== 'user') {
            array_shift($history); // l'API exige que la conversation commence par l'usager
        }
        $messages = array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $history);
        $messages[] = ['role' => 'user', 'content' => $message];

        $tools  = new AssistantTools($userId, $conversationId, $this->store);
        $system = self::systemPrompt($tools->timezone());
        $defs   = AssistantTools::definitions();

        $client     = new Client(apiKey: ANTHROPIC_API_KEY);
        $started    = false;   // un événement a-t-il déjà été émis ?
        $reply      = '';
        $stopReason = null;
        $error      = null;

        $start = function () use (&$started, &$conversationId, &$tools, $userId, $message, $emit) {
            if ($started) {
                return;
            }
            if ($conversationId === null) {
                $conversationId = $this->store->createConversation($userId, mb_substr(trim($message), 0, 80));
                $tools = new AssistantTools($userId, $conversationId, $this->store);
            }
            $this->store->addMessage($conversationId, 'user', $message);
            $started = true;
            $emit('conversation', ['conversation_id' => $conversationId]);
        };

        for ($round = 0; $round < max(1, AI_ASSISTANT_MAX_TOOL_ROUNDS); $round++) {
            $estimate    = (int) ceil(strlen($system . json_encode($messages, JSON_UNESCAPED_UNICODE) . json_encode($defs)) / 2) + 200;
            $reservation = $this->budget->reserve($userId, $model, $estimate, $maxTokens, 'assistant');
            if (!$reservation['ok']) {
                if (!$started) {
                    throw new AssistantBudgetException($reservation['scope'], $reservation['budget']);
                }
                $error = ['code' => 'AI_BUDGET_EXCEEDED', 'scope' => $reservation['scope'],
                          'message' => 'Budget IA atteint pendant la réponse.'];
                break;
            }

            $acc = MessageAccumulator::forMessages();
            try {
                $stream = $client->messages->createStream(
                    model: $model,
                    maxTokens: $maxTokens,
                    system: $system,
                    messages: $messages,
                    tools: $defs,
                    thinking: ['type' => 'adaptive'],
                    outputConfig: ['effort' => AI_ASSISTANT_EFFORT],
                );
                foreach ($stream as $event) {
                    $acc->accumulate($event);
                    if ($event instanceof RawContentBlockDeltaEvent && $event->delta instanceof TextDelta) {
                        $start();
                        $emit('delta', ['text' => $event->delta->text]);
                    }
                }
                $msg = $acc->message();
            } catch (Throwable $e) {
                $this->budget->settle($reservation['usage_id'], $model, 0, 0);
                LogService::error('Assistant : échec appel modèle (réservation annulée)', [
                    'user_id' => $userId, 'round' => $round, 'error' => $e->getMessage(),
                ]);
                if (!$started) {
                    throw new AssistantUpstreamException($e->getMessage(), 0, $e);
                }
                $error = ['code' => 'AI_UPSTREAM_ERROR', 'message' => "Erreur du service IA."];
                break;
            }

            $usage = $msg->usage;
            $this->budget->settle(
                $reservation['usage_id'],
                $model,
                (int) ($usage->inputTokens ?? 0) + (int) ($usage->cacheCreationInputTokens ?? 0) + (int) ($usage->cacheReadInputTokens ?? 0),
                (int) ($usage->outputTokens ?? 0)
            );

            $start();
            $stopReason = $msg->stopReason;
            foreach ($msg->content as $block) {
                if ($block->type === 'text') {
                    $reply .= $block->text;
                }
            }

            if ($stopReason === 'refusal') {
                $refusal = "Je ne peux pas traiter cette demande.";
                $reply  .= ($reply !== '' ? "\n\n" : '') . $refusal;
                $emit('delta', ['text' => $refusal]);
                break;
            }
            if ($stopReason !== 'tool_use') {
                break;
            }

            // Exécution des outils, puis tous les résultats dans un seul message usager.
            $results = [];
            foreach ($msg->content as $block) {
                if (!$block instanceof ToolUseBlock) {
                    continue;
                }
                $input  = is_array($block->input) ? $block->input : (array) $block->input;
                $before = count($tools->actions());
                $out    = $tools->execute($block->name, $input);
                foreach (array_slice($tools->actions(), $before) as $action) {
                    $emit('tool', ['action' => $action]);
                }
                $results[] = [
                    'type'      => 'tool_result',
                    'toolUseID' => $block->id,
                    'content'   => json_encode($out['result'], JSON_UNESCAPED_UNICODE),
                    'isError'   => !$out['ok'],
                ];
            }
            $messages[] = ['role' => 'assistant', 'content' => $msg->content];
            $messages[] = ['role' => 'user', 'content' => $results];

            if ($round === AI_ASSISTANT_MAX_TOOL_ROUNDS - 1) {
                $error = ['code' => 'ASSISTANT_TOOL_ROUNDS_EXCEEDED',
                          'message' => "Demande trop longue : l'assistant s'est arrêté avant la fin."];
            }
            if ($reply !== '' && !str_ends_with($reply, "\n")) {
                $reply .= "\n";
                $emit('delta', ['text' => "\n"]);
            }
        }

        $start();
        $reply = trim($reply);
        if ($reply === '' && $error !== null) {
            $reply = $error['message'];
        }
        if ($reply !== '') {
            $this->store->addMessage($conversationId, 'assistant', $reply);
        }
        if ($error !== null) {
            $emit('error', $error);
        }

        $budget = $this->budget->usage($userId) + [
            'assistant_spent_usd' => round($this->budget->spentByFeature($userId, 'assistant'), 6),
        ];
        $result = [
            'conversation_id' => $conversationId,
            'reply'           => $reply,
            'actions'         => $tools->actions(),
            'budget'          => $budget,
            'stop_reason'     => $stopReason,
            'error'           => $error,
        ];
        $emit('done', $result);
        return $result;
    }

    /** Prompt système complet ; $now injectable pour l'évaluation (private/tests/eval_assistant.php). */
    public static function systemPrompt(string $tz, ?DateTimeImmutable $now = null): string
    {
        $now  = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($tz));
        $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        return self::SYSTEM_PROMPT . "\n\n" . sprintf(
            "Date et heure courantes de l'usager : %s %s (fuseau %s, UTC%s).",
            $days[(int) $now->format('w')],
            $now->format('Y-m-d H:i'),
            $tz,
            $now->format('P')
        );
    }
}
