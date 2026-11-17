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
- Les appels d'outils des tours précédents ne sont pas réaffichés comme appels d'outils : ils sont
  résumés par le bloc « [Journal système …] » en tête de tes réponses précédentes, avec leur statut
  actuel. Ce journal fait foi : les actions qu'il liste ont réellement été exécutées par toi. Ne dis
  jamais que tu n'as appelé aucun outil quand le journal en liste, et ne recopie jamais ce bloc.
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
    public function run(int $userId, ?int $conversationId, string $message, callable $emit, bool $eagerStart = false): array
    {
        $model     = AI_ASSISTANT_MODEL;
        $maxTokens = AI_ASSISTANT_MAX_TOKENS;

        // Historique avant d'ajouter le nouveau message : textes + journal des actions de chaque tour (#521).
        $messages = $conversationId !== null
            ? self::historyMessages(
                $this->store->messages($conversationId, AI_ASSISTANT_HISTORY_LIMIT),
                $this->store->conversationActions($userId, $conversationId)
            )
            : [];
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
            if ($eagerStart) {
                // SSE (#521) : `conversation` part dès la première réservation acceptée, avant l'appel
                // modèle. Le 429 budget reste du JSON ; un échec modèle devient error + done.
                $start();
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

    /**
     * Historique envoyé au modèle (#521) : messages texte + journal des actions de chaque tour.
     *
     * Une action appartient au dernier message usager créé avant elle (created_at). Son résumé
     * (id, outil, statut à jour, tâche) est placé en tête de la réponse assistant de ce tour, ou
     * dans un message assistant synthétique si le tour n'a pas de réponse enregistrée. Les actions
     * antérieures à la fenêtre d'historique sont ignorées ; l'historique commence par l'usager.
     *
     * @param array<int, array{role: string, content: string, created_at?: ?string}> $history ordre chronologique
     * @param array<int, array> $actions format AssistantStore::formatAction(), ordre chronologique
     * @return array<int, array{role: string, content: string}>
     */
    public static function historyMessages(array $history, array $actions): array
    {
        while ($history && $history[0]['role'] !== 'user') {
            array_shift($history); // l'API exige que la conversation commence par l'usager
        }
        $history = array_values($history);

        $byTurn = [];
        foreach ($actions as $action) {
            $turn = null;
            foreach ($history as $i => $m) {
                if ($m['role'] === 'user' && (string) ($m['created_at'] ?? '') <= (string) ($action['created_at'] ?? '')) {
                    $turn = $i;
                }
            }
            if ($turn !== null) {
                $byTurn[$turn][] = self::describeAction($action);
            }
        }

        $out = [];
        foreach ($history as $i => $m) {
            if ($m['role'] === 'assistant' && $out && end($out)['role'] === 'assistant') {
                // réponse d'un tour dont le journal a déjà produit le message assistant
                $out[count($out) - 1]['content'] .= $m['content'];
                continue;
            }
            $out[] = ['role' => $m['role'], 'content' => $m['content']];
            if ($m['role'] === 'user' && isset($byTurn[$i])) {
                $out[] = ['role' => 'assistant', 'content' => self::JOURNAL_HEADER . "\n" . implode("\n", $byTurn[$i]) . "\n\n"];
            }
        }
        // Tour sans réponse : retirer le séparateur final du journal synthétique.
        foreach ($out as &$m) {
            $m['content'] = $m['role'] === 'assistant' ? rtrim($m['content']) : $m['content'];
        }
        unset($m);
        return $out;
    }

    private const JOURNAL_HEADER = '[Journal système — actions exécutées à ce tour, statut actuel]';

    private static function describeAction(array $a): string
    {
        $in     = is_array($a['input'] ?? null) ? $a['input'] : [];
        $res    = is_array($a['result'] ?? null) ? $a['result'] : [];
        $task   = is_array($res['task'] ?? null) ? $res['task'] : [];
        $taskId = $task['id'] ?? $in['task_id'] ?? $in['todo_id'] ?? null;
        $title  = $task['title'] ?? $in['title'] ?? null;
        $line   = sprintf('- action #%d %s %s', (int) ($a['id'] ?? 0), $a['tool'] ?? '?', $a['status'] ?? '?');
        if ($taskId !== null) {
            $line .= ' — tâche ' . (int) $taskId;
        }
        if (is_string($title) && $title !== '') {
            $line .= ' « ' . $title . ' »';
        }
        if (!empty($task['due'])) {
            $line .= ' (échéance ' . $task['due'] . ')';
        }
        if (isset($res['error']) && is_string($res['error'])) {
            $line .= ' — erreur : ' . $res['error'];
        }
        return $line;
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
