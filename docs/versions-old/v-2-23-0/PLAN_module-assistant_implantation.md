# Implantation — Module Assistant (socle + chat)

Plan : [PLAN_module-assistant.md](PLAN_module-assistant.md). Tâche racine cmem #515.

## Backlog

- Début : 2026-11-14 12:20
- Fin : 2026-11-14 12:35
- Résultat : racine #515 créée (plan en description, IN-PROCESS) ; tâches 251 à 267 rattachées ; compléments du plan ajoutés à 251, 257, 258, 260, 263, 264 ; 252-254, 259, 265-267 marquées hors lot ou reportées ; 255 et 256 annulées (couvertes par #487).

## Phase 1 — Tests en échec

- Début : 2026-11-14 12:35
- Fin : 2026-11-14 12:42
- Résultat : `private/tests/test_assistant.php` (critères 1 à 15) ; 49 / 65 en échec sur dev, tous pour la bonne raison : `/assistant/*` en 404, ENUM `module_key` sans `assistant` (`UNKNOWN_MODULE_KEY`, troncature), tables `assistant_*` et colonne `ai_usage.feature` absentes, `AI_ASSISTANT_MODEL` absent de `.env.example`.

## Phase 2 — Migration + squelette + accès

- Début : 2026-11-14 12:45
- Fin : 2026-11-14 12:49
- Résultat : migration `docs/20261114_module_assistant.sql` appliquée sur la base dev après confirmation (7 instructions OK, garde-fou « base dev ») ; plugin `src/assistant/` (plugin.json, autoloader, `AssistantPlugin`, `AssistantRouteHandler`), garde module coché + activé, consentement ; clé `assistant` dans `CmemModules::KEYS` ; constantes `AI_ASSISTANT_*` (`environment.php`, `.env.example`).

## Phase 3 — Chat, historique, budget

- Début : 2026-11-14 12:46
- Fin : 2026-11-14 12:52
- Résultat : `AssistantChatService` (boucle `createStream` + `MessageAccumulator`, réservation de budget par tour, SSE), `AssistantStore`, `AssistantController` ; `AiBudgetService::reserve(..., $feature)` + `spentByFeature()`.

## Phase 4 — Outils

- Début : 2026-11-14 12:47
- Fin : 2026-11-14 12:55
- Résultat : `AssistantTools` (6 outils stricts, propriété vérifiée, tâches chiffrées masquées, `propose_delete` → `PENDING`).

### Itérations de la boucle test → correction (3)

| Itération | Résultat | Cause | Correction |
| - | - | - | - |
| 1 | 66 / 83 | `400` de l'API : un `enum` ne se combine pas avec `type: [string, null]` en schéma strict | `anyOf` (`string` enum, ou `null`) |
| 2 | 81 / 83 | `create_task` créait la tâche puis échouait (`json_decode` sur un tableau : `getById()` décode déjà `notifications`) → le modèle réessayait, doublons | `todoSummary()` accepte les deux formes ; test 11.4 « une seule tâche » ajouté |
| 3 | 83 / 83 | — | — |

### Évaluation du modèle (`private/tests/eval_assistant.php`)

- Référence figée : samedi 2026-11-14 12:00, America/Montreal ; `claude-haiku-5-5`, effort `low`, réflexion adaptative.
- Résultat : **21 / 21** — dates relatives (demain, mardi, dans 15 jours, fin du mois, veille de Noël, dans 2 heures), rappels EMAIL/DISPLAY avec délai, recherche avant modification/suppression, question posée si l'heure manque, refus du 31 février. Coût ≈ 0,008 $.
- Décision : modèle conservé (`claude-haiku-5-5`, effort `low`) ; pas de passage à Sonnet 5.5.
- Remarque : `list_events` pour « demain » lit jusqu'à la fin du surlendemain (borne `end` inclusive) — sans conséquence.

## Phase 5 — Docs et clôture

- Début : 2026-11-14 12:53
- Résultat : `docs/docs-api/assistant/` (GUIDE.md + API_ASSISTANT_ENDPOINTS.json, 12 routes), `docs/entrypoints.md`, clé `assistant` dans les docs modules/access/core, `CLAUDE.md`, `CHANGELOG.md`, `test_assistant.php` ajouté à `run_all_tests.php`.
- Suite complète sur dev : 3239 / 3241 ; les 2 échecs (`test_modules`, `test_group_modules` : « 8 modules retournés (9) ») venaient du nouveau module `assistant` — tests passés à `count(MOD_KEYS)` / `count(GM_KEYS)` avec `assistant` ajouté ; relance ciblée verte (modules 55/55, group_modules 39/39, assistant 84/84, ai_budget 50/50, ai_summarize 22/22).
- Fin : 2026-11-14 13:40
- Restant (à la release, décision usager) : intégrer la migration dans `build_DB` (condition de #251), migration prod, puis directive vers cmem_web (chat SSE + historique, consentement Loi 25, confirmation des suppressions, module + budget).
- Écart assumé (plan validé) : #260 demandait « rappels suspendus si le module est retiré » ; les rappels créés par l'Assistant sont des rappels ordinaires #487 et continuent d'être envoyés après retrait du module.
