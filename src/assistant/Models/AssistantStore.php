<?php

namespace Assistant\Models;

use Database;
use PDO;

/**
 * Accès base du module Assistant — tables assistant_consents, assistant_conversations,
 * assistant_messages, assistant_actions (docs/20261114_module_assistant.sql).
 *
 * Toute lecture/écriture est filtrée par user_id : un usager ne voit jamais les
 * conversations ni les actions d'un autre (404 côté contrôleur).
 */
class AssistantStore
{
    /** Version du texte de consentement affiché par le client. */
    public const CONSENT_VERSION = '1';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Consentement (Loi 25)
    // ------------------------------------------------------------------

    /** @return array{consented: bool, consented_at: ?string, revoked_at: ?string, version: ?string} */
    public function consent(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT version, consented_at, revoked_at FROM assistant_consents WHERE user_id = ?");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return [
            'consented'    => $row !== null && $row['revoked_at'] === null,
            'consented_at' => $row['consented_at'] ?? null,
            'revoked_at'   => $row['revoked_at'] ?? null,
            'version'      => $row['version'] ?? null,
        ];
    }

    public function hasConsent(int $userId): bool
    {
        return $this->consent($userId)['consented'];
    }

    public function grantConsent(int $userId): void
    {
        $this->pdo->prepare(
            "INSERT INTO assistant_consents (user_id, version, consented_at, revoked_at) VALUES (?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE version = VALUES(version), consented_at = NOW(), revoked_at = NULL"
        )->execute([$userId, self::CONSENT_VERSION]);
    }

    public function revokeConsent(int $userId): void
    {
        $this->pdo->prepare("UPDATE assistant_consents SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
            ->execute([$userId]);
    }

    // ------------------------------------------------------------------
    // Conversations et messages
    // ------------------------------------------------------------------

    public function createConversation(int $userId, string $title): int
    {
        $this->pdo->prepare("INSERT INTO assistant_conversations (user_id, title) VALUES (?, ?)")
            ->execute([$userId, mb_substr($title, 0, 255)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findConversation(int $userId, int $conversationId): ?array
    {
        $st = $this->pdo->prepare("SELECT id, title, created_at, updated_at FROM assistant_conversations WHERE id = ? AND user_id = ?");
        $st->execute([$conversationId, $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listConversations(int $userId, int $limit = 50, int $offset = 0): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, title, created_at, updated_at FROM assistant_conversations
             WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?"
        );
        $st->bindValue(1, $userId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();
        return array_map(fn($r) => ['id' => (int) $r['id']] + $r, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function deleteConversation(int $userId, int $conversationId): bool
    {
        $st = $this->pdo->prepare("DELETE FROM assistant_conversations WHERE id = ? AND user_id = ?");
        $st->execute([$conversationId, $userId]);
        return $st->rowCount() > 0;
    }

    public function deleteAllConversations(int $userId): int
    {
        $st = $this->pdo->prepare("DELETE FROM assistant_conversations WHERE user_id = ?");
        $st->execute([$userId]);
        return $st->rowCount();
    }

    public function addMessage(int $conversationId, string $role, string $content): void
    {
        $this->pdo->prepare("INSERT INTO assistant_messages (conversation_id, role, content) VALUES (?, ?, ?)")
            ->execute([$conversationId, $role, $content]);
        $this->pdo->prepare("UPDATE assistant_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);
    }

    /** Messages dans l'ordre chronologique ; $limit = les N derniers (null = tous). */
    public function messages(int $conversationId, ?int $limit = null): array
    {
        if ($limit === null) {
            $st = $this->pdo->prepare("SELECT id, role, content, created_at FROM assistant_messages WHERE conversation_id = ? ORDER BY id");
            $st->execute([$conversationId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = $this->pdo->prepare(
            "SELECT id, role, content, created_at FROM assistant_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $conversationId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------
    // Journal d'actions
    // ------------------------------------------------------------------

    public function logAction(int $userId, ?int $conversationId, string $tool, array $input, ?array $result, string $status): int
    {
        $this->pdo->prepare(
            "INSERT INTO assistant_actions (user_id, conversation_id, tool, input, result, status, resolved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $userId,
            $conversationId,
            $tool,
            json_encode($input, JSON_UNESCAPED_UNICODE),
            $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
            $status,
            $status === 'PENDING' ? null : date('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findAction(int $userId, int $actionId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM assistant_actions WHERE id = ? AND user_id = ?");
        $st->execute([$actionId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? self::formatAction($row) : null;
    }

    /** Actions de l'usager, plus récentes d'abord ; $status = filtre optionnel (ex. PENDING). */
    public function listActions(int $userId, ?string $status, int $limit = 50): array
    {
        $sql    = "SELECT * FROM assistant_actions WHERE user_id = ?";
        $params = [$userId];
        if ($status !== null) {
            $sql     .= " AND status = ?";
            $params[] = $status;
        }
        $st = $this->pdo->prepare($sql . " ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
        $st->execute($params);
        return array_map([self::class, 'formatAction'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Passe une action PENDING à son statut final ; false si elle n'était plus PENDING (course). */
    public function resolveAction(int $actionId, string $status, ?array $result): bool
    {
        $st = $this->pdo->prepare(
            "UPDATE assistant_actions SET status = ?, result = ?, resolved_at = NOW() WHERE id = ? AND status = 'PENDING'"
        );
        $st->execute([$status, $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE), $actionId]);
        return $st->rowCount() > 0;
    }

    public static function formatAction(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'conversation_id' => $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null,
            'tool'            => $row['tool'],
            'input'           => $row['input'] !== null ? json_decode($row['input'], true) : null,
            'result'          => $row['result'] !== null ? json_decode($row['result'], true) : null,
            'status'          => $row['status'],
            'created_at'      => $row['created_at'] ?? null,
            'resolved_at'     => $row['resolved_at'] ?? null,
        ];
    }
}
