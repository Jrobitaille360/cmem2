# Guide — Proxy IA

Directive `cmem_web` `20260810_140000` — résumé d'agenda sur une période, déclenché
manuellement par l'usager. Endpoint consommateur du socle `modules-gating`.

## Prérequis

Le module `ia` doit être **disponible** (plan `monthly`/`yearly`/`ami`) et **activé**
(`PATCH /modules/ia { "enabled": true }`) avant tout appel à `/ai/summarize`. Voir
`docs/docs-api/modules/GUIDE.md`.

## Le contrat de confidentialité

Le corps d'un journal — chiffré ou non — n'est **jamais** transmis à l'API. Le client
assemble uniquement des métadonnées bornées avant l'appel :

```json
{
  "period": { "start": "2026-08-10", "end": "2026-08-16" },
  "items": [
    { "type": "event", "title": "Rendez-vous client X", "date": "2026-08-11", "tags": ["pro"] },
    { "type": "task", "title": "Envoyer devis", "due": "2026-08-12", "done": false }
  ]
}
```

Aucun champ `description`, `notes`, ni corps de journal n'est accepté. `title` peut être
vide/générique si l'entité provient d'un calendrier chiffré de bout en bout — le front omet
le titre plutôt que d'envoyer un texte illisible.

## Budget mensuel en dollars

Depuis le 2026-11-08 (plan accès sur approbation, Phase 1), l'IA est plafonnée **en dollars**,
plus en nombre d'appels. **Changement cassant** : `data.quota` et `AI_QUOTA_EXCEEDED`
disparaissent, remplacés par `data.budget` et `AI_BUDGET_EXCEEDED`.

- **Budget personnel** : `users.ai_budget_usd_monthly`, `NULL` = `AI_DEFAULT_BUDGET_USD`
  (10 $ par mois). S'applique aussi aux administrateurs.
- **Plafond global** : `AI_GLOBAL_BUDGET_USD_MONTHLY` (30 $ par mois, tous usagers
  confondus). Atteint, il coupe l'IA pour tout le monde (`errors.scope = "global"`).
- **Remise à zéro** : le 1er du mois suivant, 00:00 (`reset_at`).

`AiBudgetService::reserve()` inscrit dans `ai_usage`, **avant** l'appel au modèle et sous
verrou nommé, le coût maximal estimé (entrée majorée + `max_tokens`) : deux requêtes
concurrentes ne peuvent pas passer ensemble sous le plafond. Après l'appel, `settle()`
ramène la ligne au coût réel ; à 0 si l'appel modèle échoue (`502`, non facturé). Si la
réservation dépasse un plafond, `429 AI_BUDGET_EXCEEDED` est renvoyé **sans** appeler le
modèle. Les tarifs par modèle vivent dans `src/access/Config/AiPricing.php` (mise à jour
manuelle quand le modèle ou les prix changent).
## Consigne fixe, pas de prompt libre

Le prompt système (`AiSummarizeService::SYSTEM_PROMPT`) est fixé côté serveur. Le client ne
transmet aucune instruction de résumé — seulement les métadonnées. Ça évite l'injection de
prompt et le détournement du proxy pour un usage hors périmètre.

## Clé et modèle

`ANTHROPIC_API_KEY` vit uniquement dans l'environnement serveur — jamais dans une réponse
HTTP ni un header exposé au client. `AI_SUMMARIZE_MODEL` est configurable par variable
d'environnement (défaut `claude-haiku-5-5`, réflexion désactivée — coût minimal, environ
0,0001 $ par résumé) pour permettre une migration de modèle sans redéploiement front.
`AI_SUMMARIZE_MAX_TOKENS` (défaut 512) plafonne la sortie et borne la réservation.

## Endpoint

### `POST /ai/summarize`

```json
// 200
{
  "success": true,
  "message": "Résumé généré",
  "data": {
    "summary": "Semaine chargée : 3 rendez-vous clients, 2 tâches en retard...",
    "budget": { "spent_usd": 0.0042, "limit_usd": 10, "reset_at": "2026-12-01 00:00:00" }
  }
}
```

Erreurs :

| Code HTTP | `errors.code` | Cas |
| - | - | - |
| `401` | — | JWT absent ou invalide |
| `403` | `MODULE_NOT_AVAILABLE` | module `ia` non disponible sur le plan ou non activé |
| `422` | — | `period` ou `items` absent |
| `429` | `AI_BUDGET_EXCEEDED` | budget atteint (`errors.scope` : `user` ou `global`), aucun appel modèle déclenché |
| `502` | — | échec de l'appel modèle (réservation ramenée à 0) |

### `GET /ai/usage`

```json
// 200
{
  "success": true,
  "message": "Budget IA",
  "data": {
    "budget": { "spent_usd": 1.25, "limit_usd": 10, "reset_at": "2026-12-01 00:00:00" }
  }
}
```

## Fichiers

| Rôle | Fichier |
| - | - |
| Appel Anthropic, prompt fixe | `src/auth_groups/Services/AiSummarizeService.php` |
| Endpoints, gating, budget | `src/auth_groups/Controllers/AiController.php` |
| Réservation / ajustement du budget | `src/access/Services/AiBudgetService.php` |
| Tarifs par modèle | `src/access/Config/AiPricing.php` |
| Routage | `src/auth_groups/Routing/RouteHandlers/AiRouteHandler.php` |
| Tests | `private/tests/test_ai_summarize.php`, `private/tests/test_ai_budget.php` |
