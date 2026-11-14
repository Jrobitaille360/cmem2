<?php

namespace Assistant\Controllers;

use Access\Config\CmemModules;
use Access\Services\AiBudgetService;
use Access\Services\ModuleGrantService;
use Assistant\Models\AssistantStore;
use Assistant\Services\AssistantBudgetException;
use Assistant\Services\AssistantChatService;
use Assistant\Services\AssistantTools;
use Assistant\Services\AssistantUpstreamException;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\TenantModule;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;
use Throwable;

/**
 * Routes /assistant/* (tâche racine #515).
 *
 * Garde commune (requireModule) : module « assistant » coché (user_module_grants, hérité du
 * propriétaire en contexte de groupe) ET activé (tenant_modules.enabled). Les comptes
 * pending/suspended sont déjà refusés par JwtAuthMiddleware (403 ACCOUNT_PENDING / SUSPENDED).
 */
class AssistantController
{
    public const MODULE_KEY = 'assistant';
    private const MESSAGE_MAX = 4000;

    private AssistantStore $store;

    public function __construct()
    {
        $this->store = new AssistantStore();
    }

    /** true si l'usager a le module coché et activé ; sinon envoie 403 MODULE_NOT_AVAILABLE. */
    public function requireModule(int $userId): bool
    {
        $available = (new ModuleGrantService())->isAvailable($userId, self::MODULE_KEY);
        $row       = (new TenantModule())->findByOwnerAndKey($userId, self::MODULE_KEY);
        $enabled   = $row !== null ? ((int) $row['enabled'] === 1) : CmemModules::isEnabledByDefault(self::MODULE_KEY);

        if ($available && $enabled) {
            return true;
        }
        LoggingMiddleware::logExit(403);
        Response::error(
            "Le module « assistant » n'est pas disponible ou n'est pas activé.",
            ['code' => 'MODULE_NOT_AVAILABLE', 'module' => self::MODULE_KEY, 'available' => $available, 'enabled' => $enabled],
            403
        );
        return false;
    }

    // ------------------------------------------------------------------
    // Consentement
    // ------------------------------------------------------------------

    public function getConsent(int $userId): void
    {
        Response::success('Consentement', $this->store->consent($userId) + ['current_version' => AssistantStore::CONSENT_VERSION]);
    }

    public function grantConsent(int $userId): void
    {
        $this->store->grantConsent($userId);
        Response::success('Consentement enregistré', $this->store->consent($userId), 201);
    }

    public function revokeConsent(int $userId): void
    {
        $this->store->revokeConsent($userId);
        Response::success('Consentement retiré', $this->store->consent($userId));
    }

    // ------------------------------------------------------------------
    // Chat
    // ------------------------------------------------------------------

    public function chat(int $userId): void
    {
        if (!$this->store->hasConsent($userId)) {
            LoggingMiddleware::logExit(403);
            Response::error(
                "Consentement requis avant le premier envoi de données à l'assistant.",
                ['code' => 'ASSISTANT_CONSENT_REQUIRED', 'version' => AssistantStore::CONSENT_VERSION],
                403
            );
            return;
        }

        $input   = Response::getRequestParams();
        $message = is_string($input['message'] ?? null) ? trim($input['message']) : '';
        if ($message === '' || mb_strlen($message) > self::MESSAGE_MAX) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', ['message' => 'Message requis (' . self::MESSAGE_MAX . ' caractères au plus)'], 422);
            return;
        }

        $conversationId = null;
        if (isset($input['conversation_id']) && $input['conversation_id'] !== null && $input['conversation_id'] !== '') {
            if (!ctype_digit((string) $input['conversation_id'])
                || $this->store->findConversation($userId, (int) $input['conversation_id']) === null) {
                LoggingMiddleware::logExit(404);
                Response::error('Conversation introuvable', null, 404);
                return;
            }
            $conversationId = (int) $input['conversation_id'];
        }

        if (ANTHROPIC_API_KEY === '') {
            LoggingMiddleware::logExit(503);
            Response::error("Service IA non configuré", ['code' => 'AI_NOT_CONFIGURED'], 503);
            return;
        }

        $sse  = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/event-stream') !== false;
        $emit = $sse ? $this->sseEmitter() : static function (string $event, array $data): void {};

        @set_time_limit(300);
        try {
            $result = (new AssistantChatService($this->store))->run($userId, $conversationId, $message, $emit);
        } catch (AssistantBudgetException $e) {
            LoggingMiddleware::logExit(429);
            Response::error(
                $e->scope === 'global'
                    ? "Budget IA global du mois atteint : l'IA est suspendue jusqu'à la remise à zéro."
                    : 'Budget IA mensuel atteint.',
                ['code' => 'AI_BUDGET_EXCEEDED', 'scope' => $e->scope, 'budget' => $e->budget],
                429
            );
            return;
        } catch (AssistantUpstreamException $e) {
            LoggingMiddleware::logExit(502);
            Response::error('Erreur du service IA', ['code' => 'AI_UPSTREAM_ERROR'], 502);
            return;
        } catch (Throwable $e) {
            LogService::error('Assistant : erreur inattendue', ['user_id' => $userId, 'error' => $e->getMessage()]);
            if ($sse && headers_sent()) {
                $emit('error', ['code' => 'INTERNAL_ERROR', 'message' => 'Erreur interne']);
                exit;
            }
            LoggingMiddleware::logExit(500);
            Response::error('Erreur interne', null, 500);
            return;
        }

        LoggingMiddleware::logExit(200);
        if ($sse) {
            exit; // événement done déjà écrit
        }
        Response::success('Réponse de l\'assistant', $result);
    }

    /** Émetteur SSE : en-têtes envoyés au premier événement, tampons de sortie désactivés. */
    private function sseEmitter(): callable
    {
        return static function (string $event, array $data): void {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-cache, no-transform');
                header('X-Accel-Buffering: no'); // nginx / LiteSpeed : pas de mise en tampon
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                @ini_set('zlib.output_compression', '0');
                @ini_set('implicit_flush', '1');
            }
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };
    }

    // ------------------------------------------------------------------
    // Historique
    // ------------------------------------------------------------------

    public function listConversations(int $userId): void
    {
        $params = Response::getRequestParams();
        $limit  = max(1, min(100, (int) ($params['limit'] ?? 50)));
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $rows   = $this->store->listConversations($userId, $limit, $offset);
        Response::success('Conversations', ['conversations' => $rows, 'count' => count($rows)]);
    }

    public function getConversation(int $userId, int $conversationId): void
    {
        $conv = $this->store->findConversation($userId, $conversationId);
        if ($conv === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Conversation introuvable', null, 404);
            return;
        }
        $conv['id'] = (int) $conv['id'];
        Response::success('Conversation', $conv + ['messages' => $this->store->messages($conversationId)]);
    }

    public function deleteConversation(int $userId, int $conversationId): void
    {
        if (!$this->store->deleteConversation($userId, $conversationId)) {
            LoggingMiddleware::logExit(404);
            Response::error('Conversation introuvable', null, 404);
            return;
        }
        Response::success('Conversation supprimée');
    }

    public function deleteAllConversations(int $userId): void
    {
        Response::success('Historique supprimé', ['deleted' => $this->store->deleteAllConversations($userId)]);
    }

    // ------------------------------------------------------------------
    // Actions (journal + confirmation des suppressions)
    // ------------------------------------------------------------------

    public function listActions(int $userId): void
    {
        $params = Response::getRequestParams();
        $status = isset($params['status']) ? strtoupper((string) $params['status']) : null;
        if ($status !== null && !in_array($status, ['DONE', 'PENDING', 'CONFIRMED', 'REJECTED', 'FAILED'], true)) {
            LoggingMiddleware::logExit(422);
            Response::error('Statut invalide', ['status' => 'DONE, PENDING, CONFIRMED, REJECTED ou FAILED'], 422);
            return;
        }
        $rows = $this->store->listActions($userId, $status, (int) ($params['limit'] ?? 50));
        Response::success('Actions', ['actions' => $rows, 'count' => count($rows)]);
    }

    public function resolveAction(int $userId, int $actionId, bool $confirm): void
    {
        $action = $this->store->findAction($userId, $actionId);
        if ($action === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Action introuvable', null, 404);
            return;
        }
        if ($action['status'] !== 'PENDING') {
            LoggingMiddleware::logExit(409);
            Response::error('Action déjà traitée', ['code' => 'ACTION_NOT_PENDING', 'status' => $action['status']], 409);
            return;
        }

        if ($confirm) {
            $result = $action['tool'] === 'propose_delete'
                ? (new AssistantTools($userId, $action['conversation_id']))->performDelete($action)
                : ['error' => 'Action non confirmable'];
            $status = !empty($result['deleted']) ? 'CONFIRMED' : 'FAILED';
        } else {
            $result = ['rejected' => true];
            $status = 'REJECTED';
        }

        if (!$this->store->resolveAction($actionId, $status, $result)) {
            LoggingMiddleware::logExit(409);
            Response::error('Action déjà traitée', ['code' => 'ACTION_NOT_PENDING'], 409);
            return;
        }
        Response::success($confirm ? 'Action confirmée' : 'Action refusée', ['action' => $this->store->findAction($userId, $actionId)]);
    }

    // ------------------------------------------------------------------
    // Usage
    // ------------------------------------------------------------------

    public function usage(int $userId): void
    {
        $budget = new AiBudgetService();
        Response::success('Budget IA', [
            'budget' => $budget->usage($userId) + [
                'assistant_spent_usd' => round($budget->spentByFeature($userId, 'assistant'), 6),
            ],
            'model'  => AI_ASSISTANT_MODEL,
        ]);
    }
}
