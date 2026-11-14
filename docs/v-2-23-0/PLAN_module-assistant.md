# PLAN — Module Assistant (socle + chat)

Tâche racine cmem #515 (projet 7). Lot : #251, #260, #257, #258, #263, #264.
Rédigé le 2026-11-14, après v2.22.0 (rappels unifiés #487) et la fin de la commercialisation (v2.21.0).

## Périmètre

| Tâche | Sujet | Sort |
| - | - | - |
| #251 API-01 | Squelette `AssistantPlugin` | Cette session |
| #260 API-10 | Module coché `assistant` | Cette session |
| #257 API-07 | `POST /assistant/chat` (streaming, historique, consentement) | Cette session |
| #258 API-08 | Outils Claude (tâches + rappels) | Cette session |
| #263 API-13 | Usage en dollars, `GET /assistant/usage` | Cette session |
| #264 API-14 | Budget IA mensuel appliqué | Cette session |
| #252 à #254, #259 | SMTP par utilisateur, résumé quotidien | Plus tard |
| #255 API-05, #256 API-06 | VALARM EMAIL, `mail_queue` | Annulées — couvert par #487 (`send_reminders.php`, `reminder_log`) |
| #265 à #267 | Voix | Reportées (fournisseur non choisi) |

## Ce qui est déjà en place

- Plugins : `Core\PluginManager` découvre `src/<plugin>/plugin.json` + classe `AbstractPlugin` (patron : `src/booking/`).
- IA : `AiController` + `AiSummarizeService` (`anthropic-ai/sdk` ^0.41), `ANTHROPIC_API_KEY`.
- Budget : `Access\Services\AiBudgetService` — `reserve()` (coût maximal, verrou nommé, plafonds personnel + global) puis `settle()` ; table `ai_usage` ; `GET /ai/usage`.
- Modules : `CmemModules::KEYS` + ENUM SQL `tenant_modules.module_key` et `user_module_grants.module_key` ; `ModuleGrantService::isAvailable()` (avec héritage propriétaire en contexte de groupe).
- Rappels : champ `notifications` par élément (tâches ICS, tâches de projet, contacts, opportunités), cron unifié courriel + push.

## Améliorations à faire

### Squelette (#251)

- `src/assistant/` : `plugin.json`, `AssistantPlugin`, `Routing/AssistantRouteHandler`, `Controllers/`, `Services/`, `Models/`.
- Variables `.env` : `AI_ASSISTANT_MODEL` (défaut `claude-haiku-5-5`), `AI_ASSISTANT_MAX_TOKENS` (défaut 2048), `AI_ASSISTANT_HISTORY_LIMIT` (messages renvoyés au modèle, défaut 20), `AI_ASSISTANT_MAX_TOOL_ROUNDS` (défaut 5).

### Accès (#260)

- Clé `assistant` ajoutée à `CmemModules::KEYS` et aux deux ENUM SQL (migration).
- Garde unique en tête du handler : 401 sans JWT, 403 `ACCOUNT_PENDING` / `ACCOUNT_SUSPENDED` (garde centrale existante), 403 `MODULE_NOT_AVAILABLE` si non coché ou non activé.
- Les rappels déjà posés par l'Assistant restent des rappels ordinaires (#487) : retirer le module ne les supprime pas.

### Chat (#257)

- `POST /assistant/consent` / `DELETE /assistant/consent` / `GET /assistant/consent` — consentement Loi 25 (`assistant_consents`, horodaté, version du texte).
- `POST /assistant/chat` `{conversation_id?, message}` — sans consentement : 403 `ASSISTANT_CONSENT_REQUIRED`, rien n'est envoyé au modèle.
- Streaming SSE si `Accept: text/event-stream` : événements `conversation`, `delta`, `tool`, `done`, `error` ; sinon réponse JSON complète (tests, clients simples).
- Prompt système fixé serveur : date, heure et fuseau de l'usager (`users.timezone`), règles de prudence sur les dates relatives.
- Réflexion adaptative + effort `low` (recommandation #257), modèle selon `AI_ASSISTANT_MODEL`.
- Historique : `GET /assistant/conversations`, `GET /assistant/conversations/{id}`, `DELETE /assistant/conversations/{id}`, `DELETE /assistant/conversations` (tout).

### Outils (#258)

- `list_tasks` (période, statut), `create_task`, `update_task`, `set_reminder` (champ `notifications` du contrat #487), `list_events`.
- Exécutés côté serveur avec l'identité de l'usager (jamais un `user_id` fourni par le modèle), via les modèles existants.
- Schémas `strict: true` ; validation serveur (date ISO valide, fuseau IANA, élément appartenant à l'usager).
- Aucune suppression exposée au modèle : `propose_delete` crée une action `PENDING` que l'usager confirme par `POST /assistant/actions/{id}/confirm` (ou refuse par `/reject`).
- Journal `assistant_actions` (conversation, outil, entrée, résultat, statut) ; chaque action est renvoyée au client (`tool` en SSE, `actions[]` en JSON).

### Usage et budget (#263, #264)

- Chaque tour d'appel modèle passe par `AiBudgetService::reserve()` / `settle()` : budget unique partagé avec `/ai/summarize`.
- Colonne `ai_usage.feature` (`summarize` / `assistant`) pour ventiler l'usage.
- `GET /assistant/usage` → `{spent_usd, limit_usd, reset_at, assistant_spent_usd}`.
- Refus 429 `AI_BUDGET_EXCEEDED` (`scope` = `user` ou `global`) avant tout appel modèle ; en cours de boucle d'outils, arrêt propre avec événement `error`.

## Maintenances à prévoir

- Tarifs `AiPricing` à jour si le modèle change.
- Purge des conversations inactives (à décider : rétention, ex. 12 mois) dans `src/cron/cleanup.php`.
- Suppression du compte : conversations, consentements et actions en `ON DELETE CASCADE`.
- Évaluation des ≈ 20 phrases types à rejouer à chaque changement de modèle.

## Migration SQL (`docs/20261114_module_assistant.sql`)

- `ALTER` des ENUM `tenant_modules.module_key` et `user_module_grants.module_key` (+ `assistant`).
- `ALTER TABLE ai_usage ADD feature varchar(32) NOT NULL DEFAULT 'summarize'`.
- `CREATE TABLE assistant_consents`, `assistant_conversations`, `assistant_messages`, `assistant_actions`.

Application sur dev uniquement après confirmation explicite (protocole spec-first, étape 5).

## Critères d'acceptation (tests `private/tests/test_assistant.php`)

1. Le plugin est chargé : `GET /assistant/usage` répond autre chose que 404.
2. Sans JWT : 401 sur toutes les routes `/assistant/*`.
3. Module `assistant` non coché : 403 `MODULE_NOT_AVAILABLE`.
4. Module coché, sans consentement : `POST /assistant/chat` → 403 `ASSISTANT_CONSENT_REQUIRED`, aucune ligne `ai_usage` créée.
5. `POST /assistant/consent` puis `GET` → `consented: true` avec horodatage ; `DELETE` → `consented: false`.
6. Budget personnel épuisé (`ai_budget_usd_monthly = 0`) : `POST /assistant/chat` → 429 `AI_BUDGET_EXCEEDED` `scope=user`.
7. Chat JSON : réponse `{conversation_id, reply, actions[], budget}` ; ligne `ai_usage` avec `feature='assistant'` et coût > 0.
8. Chat SSE : `Content-Type: text/event-stream`, au moins un `delta` puis `done`.
9. Historique : la conversation apparaît dans la liste, son détail contient le message usager et la réponse ; `DELETE` → 404 ensuite.
10. Isolation : l'usager B ne lit ni ne supprime la conversation de A (404).
11. Outil `create_task` (« crée une tâche … demain 14 h ») : une tâche existe pour l'usager, action `DONE` journalisée et renvoyée.
12. Outil `set_reminder` : le champ `notifications` de la tâche contient le rappel demandé.
13. Demande de suppression : aucune tâche supprimée ; action `PENDING` ; `confirm` → supprimée ; `reject` → conservée.
14. `GET /assistant/usage` : `spent_usd` = même valeur que `GET /ai/usage`, plus `assistant_spent_usd`.
15. `AI_ASSISTANT_MODEL` présent dans `.env.example`.

Les critères 7, 8, 11 à 13 appellent réellement le modèle (coût de quelques cents sur dev).

## Phases d'implantation

### Phase 1 — Tests en échec

- Actions : écrire `test_assistant.php` (critères 1 à 15) ; l'exécuter.
- Enjeux : échecs pour la bonne raison (404 du plugin absent), pas une erreur de syntaxe.
- Terminé quand : tous les tests échouent sur 404 / champ manquant.

### Phase 2 — Migration + squelette + accès (#251, #260)

- Actions : fichier SQL ; **STOP — confirmation avant application sur dev** ; plugin, handler, garde module, consentement.
- Tests : critères 1 à 5, 15.
- Terminé quand : ces critères passent sur dev ; `test_modules` et `test_module_grants` toujours verts.

### Phase 3 — Chat, historique, budget (#257, #263, #264)

- Actions : `AssistantChatService` (boucle modèle, SSE), modèles conversation/messages, `ai_usage.feature`, `GET /assistant/usage`.
- Enjeux : streaming sous le serveur PHP intégré et sous Apache/LiteSpeed (désactiver le tampon de sortie).
- Tests : critères 6 à 10, 14 ; `test_ai_budget` toujours vert.

### Phase 4 — Outils (#258)

- Actions : `AssistantTools` (schémas + exécution), journal `assistant_actions`, confirmation des suppressions.
- Tests : critères 11 à 13 ; évaluation des ≈ 20 phrases types (`private/tests/eval_assistant.php`, rapport dans le plan d'implantation).
- Terminé quand : critères passent et l'évaluation est jugée suffisante pour figer le modèle.

### Phase 5 — Docs et clôture

- Actions : `docs/docs-api/assistant/GUIDE.md` + `API_ASSISTANT_ENDPOINTS.json`, `docs/entrypoints.md`, `CLAUDE.md` (module + test), `CHANGELOG.md` ; directive vers cmem_web (client du chat) ; `done` des tâches.
- Terminé quand : suite complète verte sur dev, docs synchronisées.
