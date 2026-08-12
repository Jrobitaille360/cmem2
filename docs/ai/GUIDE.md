# Guide — Proxy IA

Directive `cmem_web` `20260810_140000` — résumé d'agenda sur une période, déclenché
manuellement par l'usager. Endpoint consommateur du socle `modules-gating`.

## Prérequis

Le module `ia` doit être **disponible** (plan `monthly`/`yearly`/`ami`) et **activé**
(`PATCH /modules/ia { "enabled": true }`) avant tout appel à `/ai/summarize`. Voir
`docs/modules/GUIDE.md`.

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

## Quota décompté avant l'appel

`AiController::summarize()` décompte le quota (`TenantModule::incrementQuota`) **avant**
d'appeler le modèle — jamais après. Ça évite un appel gratuit en cas de course entre deux
requêtes concurrentes de la même seconde. Conséquence acceptée : si l'appel modèle échoue
ensuite (`502`), le quota reste décompté — pas de remboursement automatique.

Si le quota est déjà atteint (30/30), `429 AI_QUOTA_EXCEEDED` est renvoyé **sans**
incrémenter et **sans** appeler le modèle.

## Consigne fixe, pas de prompt libre

Le prompt système (`AiSummarizeService::SYSTEM_PROMPT`) est fixé côté serveur. Le client ne
transmet aucune instruction de résumé — seulement les métadonnées. Ça évite l'injection de
prompt et le détournement du proxy pour un usage hors périmètre.

## Clé et modèle

`ANTHROPIC_API_KEY` vit uniquement dans l'environnement serveur — jamais dans une réponse
HTTP ni un header exposé au client. `AI_SUMMARIZE_MODEL` est configurable par variable
d'environnement (défaut `claude-haiku-4-5`) pour permettre une migration de modèle sans
redéploiement front.

## Endpoint

### `POST /ai/summarize`

```json
// 200
{
  "success": true,
  "message": "Résumé généré",
  "data": {
    "summary": "Semaine chargée : 3 rendez-vous clients, 2 tâches en retard...",
    "quota": { "used": 6, "limit": 30, "reset_at": "2026-09-01 00:00:00" }
  }
}
```

Erreurs :

| Code HTTP | `errors.code` | Cas |
| - | - | - |
| `401` | — | JWT absent ou invalide |
| `403` | `MODULE_NOT_AVAILABLE` | module `ia` non disponible sur le plan ou non activé |
| `422` | — | `period` ou `items` absent |
| `429` | `AI_QUOTA_EXCEEDED` | quota mensuel atteint, aucun appel modèle déclenché |
| `502` | — | échec de l'appel modèle (quota déjà décompté) |

## Fichiers

| Rôle | Fichier |
| - | - |
| Appel Anthropic, prompt fixe | `src/auth_groups/Services/AiSummarizeService.php` |
| Endpoint, gating, quota | `src/auth_groups/Controllers/AiController.php` |
| Routage | `src/auth_groups/Routing/RouteHandlers/AiRouteHandler.php` |
| Tests | `private/tests/test_ai_summarize.php` |
