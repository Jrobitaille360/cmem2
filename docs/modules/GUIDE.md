# Guide — Registre de modules activables

Directive `cmem_web` `20260727_144926` — gating des pans fonctionnels par plan, interrupteur
usager, quota serveur.

## Le modèle en trois états

Trois notions distinctes, jamais fusionnées :

| État | Qui décide | Où c'est stocké |
| - | - | - |
| **Disponible** | le plan cmem effectif | `Stripe\Config\CmemModules` (config statique, pas de table) |
| **Activé** | l'usager | `tenant_modules.enabled` |
| **Quota** | le serveur | `tenant_modules.quota_used` / `quota_reset_at` |

Conséquence recherchée pour le module IA : un module peut être **disponible mais éteint** — aucun
appel, aucun coût, tant que l'usager ne l'allume pas.

L'autorité est serveur. Le front reflète un état et invite à l'upgrade ; il ne décide jamais d'un
droit. Un `PATCH` émis sans droit est refusé, quel que soit ce que croit le client.

## Les 8 clés

```txt
projet | contacts | crm | ged | ia | caldav | booking | push_avance
```

Énumération figée dès la v1, alignée sur l'`ENUM` SQL de `tenant_modules.module_key`. Ajouter une
clé exige une migration de l'enum **et** une mise à jour de `CmemModules::KEYS`.

## Mapping plan → modules

| `module_key` | `free` | `monthly` / `yearly` / `team` | `ami` | `enabled` par défaut | Quota |
| - | - | - | - | - | - |
| `projet` | disponible | disponible | disponible | `true` | — |
| `contacts` | disponible | disponible | disponible | `true` | — |
| `crm` | disponible | disponible | disponible | `true` | — |
| `ged` | disponible | disponible | disponible | `true` | — |
| `ia` | non | disponible | disponible | `false` | 30 appels/mois |
| `caldav` | non | non | disponible | `false` | — |
| `booking` | non | non | disponible | `false` | — |
| `push_avance` | non | non | disponible | `false` | — |

`team` (plan équipe, groupe) a le même mapping que `monthly`/`yearly`.

**Calibrage du rétro-fit** (acté avec `cmem_web` le 2026-07-27) : les quatre pans déjà en
production restent disponibles sur **tous** les plans, y compris Gratuit. Pas de clause
grand-père, pas de date de bascule — donc aucun compte existant ne perd l'accès, et aucun backfill
de données n'est nécessaire. Le plan `ami` ouvre les 8 modules.

## Absence de ligne = état par défaut

`GET /modules` **n'écrit rien**. Un usager qui n'a jamais touché à ses réglages n'a aucune ligne
dans `tenant_modules` : chaque module est servi à sa valeur par défaut
(`CmemModules::isEnabledByDefault`). La première ligne n'apparaît qu'au premier `PATCH`.

Un module non disponible est toujours renvoyé `enabled: false`, même si une ligne héritée d'un
ancien plan dit le contraire — la perte du droit éteint l'accès sans détruire le réglage.

## Endpoints

### `GET /modules`

```json
{
  "success": true,
  "message": "Modules récupérés",
  "data": {
    "plan": "monthly",
    "modules": [
      { "key": "projet",   "available": true,  "enabled": true,  "quota": null },
      { "key": "contacts", "available": true,  "enabled": true,  "quota": null },
      { "key": "crm",      "available": true,  "enabled": true,  "quota": null },
      { "key": "ged",      "available": true,  "enabled": true,  "quota": null },
      { "key": "ia",       "available": true,  "enabled": false,
        "quota": { "used": 0, "limit": 30, "reset_at": "2026-08-01 00:00:00" } },
      { "key": "caldav",   "available": false, "enabled": false, "quota": null },
      { "key": "booking",  "available": false, "enabled": false, "quota": null },
      { "key": "push_avance", "available": false, "enabled": false, "quota": null }
    ]
  }
}
```

### `PATCH /modules/{key}`

Corps : `{ "app_id": "cmemweb", "enabled": true }`. `enabled` doit être un booléen strict.
`PUT` est accepté comme alias.

UPSERT sur `(owner_id, module_key)` : deux appels successifs ne créent jamais deux lignes.

Erreurs :

| Code HTTP | `errors.code` | Cas |
| - | - | - |
| `401` | — | JWT absent ou invalide |
| `403` | `MODULE_NOT_AVAILABLE` | le plan ne donne pas droit au module |
| `422` | `UNKNOWN_MODULE_KEY` | clé hors énumération |
| `422` | `VALIDATION_ERROR` | `enabled` absent ou non booléen |

Le `403` porte `errors.module` et `errors.plan` en plus du code. Le front doit lire
`errors.code` pour distinguer ce refus d'un `403` d'authentification : le premier appelle une
invite à l'upgrade, le second une déconnexion.

## Désactiver ne détruit rien

Éteindre un module coupe l'accès, jamais le contenu. Aucune donnée n'est supprimée ; réactiver
rend les données telles quelles. Le test `test_modules.php` §6 le vérifie sur `contacts`.

## Quota

Le décompte est serveur, jamais client. `TenantModule::incrementQuota()` incrémente
`quota_used` et remet le compteur à 1 quand `quota_reset_at` est échu (période = mois calendaire,
`CmemModules::nextQuotaReset()`). Les endpoints consommateurs — module IA, voir
`docs/ai/GUIDE.md` — appellent ce mécanisme et répondent `429` au dépassement.

`GET /modules` n'expose que la lecture : `used` / `limit` / `reset_at`.

Le quota de stockage `ged` est **reporté** : le cap `max_storage_mb` de `CmemPlans` n'est pas
enforcé, `ged` renvoie `quota: null`.

## Portée groupe — plan équipe

Directive `20260813_143000` (plan-equipe). `tenant_modules.group_id` (unicité
`(group_id, module_key)` + CHECK XOR avec `owner_id`) est servi par deux mécanismes :

1. **`GET /modules` fusionne** perso ∪ tous les groupes actifs de l'usager : `available` suit le
   meilleur plan effectif (perso ou groupe, `EntitlementService::getEffectivePlanForCmem`),
   `enabled` est un **OR logique** (activé quelque part = activé). La forme de la réponse ne
   change pas — pas de champ `source` par module. `quota` reste porté uniquement par la ligne
   perso en v1 (pas de quota de groupe).
2. **`GET/PATCH /groups/{id}/modules[/{key}]`** — vue et administration dédiées d'un groupe. Le
   plan suit l'abonnement propre du groupe (`EntitlementService::getEffectivePlanForGroup`), sans
   fusion avec les membres.

### `GET /groups/{id}/modules`

Ouvert à tout **membre** du groupe. Même forme que `GET /modules`, avec `plan` = plan effectif du
groupe (`free` par défaut si aucun abonnement).

```json
{ "success": true, "data": { "plan": "team", "modules": [ /* mêmes 8 clés */ ] } }
```

Erreurs : `401` (JWT absent), `403 GROUP_MEMBERSHIP_REQUIRED` (non-membre).

### `PATCH /groups/{id}/modules/{key}`

Réservé aux **admins du groupe** (`role=admin`, ou rôle système `ADMINISTRATEUR`+). Mêmes règles
et codes d'erreur que `PATCH /modules/{key}` (`UNKNOWN_MODULE_KEY`, `VALIDATION_ERROR`,
`MODULE_NOT_AVAILABLE`), plus `403 GROUP_ADMIN_REQUIRED` pour un membre non-admin. UPSERT sur
`(group_id, module_key)`.

## Fichiers

| Rôle | Fichier |
| - | - |
| Enum, mapping, quotas | `src/stripe/Config/CmemModules.php` |
| Plans + classement | `src/stripe/Config/CmemPlans.php` |
| Accès table | `src/auth_groups/Models/TenantModule.php` |
| Endpoints (perso) | `src/auth_groups/Controllers/ModuleController.php` |
| Endpoints (groupe) | `src/auth_groups/Controllers/GroupModuleController.php` |
| Plan effectif (perso + groupe) | `src/stripe/Services/EntitlementService.php` |
| Routage | `src/auth_groups/Routing/RouteHandlers/ModuleRouteHandler.php`, `GroupRouteHandler.php` |
| Migrations | `docs/20260727_tenant_modules.sql`, `docs/20260813_group_billing.sql` |
| Tests | `private/tests/test_modules.php`, `test_group_modules.php`, `test_plan_effectif_groupe.php` |
