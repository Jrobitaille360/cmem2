# Guide — Module Assistant

Référence des routes : [API_ASSISTANT_ENDPOINTS.json](API_ASSISTANT_ENDPOINTS.json).
Plan : [PLAN_module-assistant.md](../../v-2-23-0/PLAN_module-assistant.md).

L'Assistant est un chat avec Claude qui peut lire et modifier les tâches de l'usager et leurs
rappels. Toutes les routes sont sous `/assistant/*`, authentifiées par JWT.

## Prérequis côté client

1. **Compte approuvé** — sinon `403 ACCOUNT_PENDING` / `ACCOUNT_SUSPENDED`.
2. **Module coché** — l'admin coche `assistant` (`PUT /users/{id}/modules`). Un membre de
   groupe hérite des modules du propriétaire du groupe.
3. **Module activé** — l'usager l'allume : `PATCH /modules/assistant {"app_id": "cmemweb", "enabled": true}`.
4. **Consentement** — `POST /assistant/consent` après affichage du texte de consentement
   (version `current_version` de `GET /assistant/consent`).

Sans 2 ou 3 : `403 MODULE_NOT_AVAILABLE` (champs `available` et `enabled` pour savoir quoi
afficher). Sans 4 : `403 ASSISTANT_CONSENT_REQUIRED` sur `POST /assistant/chat`, et aucune
donnée n'est envoyée à Claude.

## Envoyer un message

```http
POST /assistant/chat
Authorization: Bearer <jwt>
Content-Type: application/json

{"message": "Rendez-vous chez le dentiste mardi 14 h, rappel courriel 1 h avant"}
```

- Sans `conversation_id` : une conversation est créée, son id revient dans la réponse.
- Avec `conversation_id` : les 20 derniers messages (`AI_ASSISTANT_HISTORY_LIMIT`) sont
  renvoyés au modèle comme contexte.

### Réponse JSON (défaut)

```json
{
  "success": true,
  "data": {
    "conversation_id": 42,
    "reply": "C'est noté : « Rendez-vous chez le dentiste » mardi 17 novembre à 14 h, rappel par courriel 1 h avant.",
    "actions": [
      {"id": 7, "tool": "create_task", "status": "DONE", "input": {}, "result": {"task": {"id": 912}}}
    ],
    "budget": {"spent_usd": 0.0012, "limit_usd": 10, "reset_at": "2026-12-01 00:00:00", "assistant_spent_usd": 0.0009},
    "stop_reason": "end_turn",
    "error": null
  }
}
```

### Streaming SSE

Avec `Accept: text/event-stream`, la réponse est un flux d'événements :

```text
event: conversation
data: {"conversation_id":42}

event: tool
data: {"action":{"id":7,"tool":"create_task","status":"DONE", "...": "..."}}

event: delta
data: {"text":"C'est noté : "}

event: done
data: {"conversation_id":42,"reply":"…","actions":[…],"budget":{…},"stop_reason":"end_turn","error":null}
```

- `delta` arrive au fil de la génération : afficher le texte au fur et à mesure.
- `tool` arrive dès qu'une action est exécutée ou proposée : rafraîchir la liste des tâches.
- `error` (rare) précède `done` quand la réponse a été interrompue en cours de route.
- Les erreurs détectées **avant** le premier événement (budget, consentement, modèle
  indisponible) arrivent en JSON ordinaire avec leur code HTTP, pas en SSE.

`EventSource` ne permet pas `POST` : utiliser `fetch()` et lire `response.body` par morceaux.

## Ce que l'assistant peut faire

| Outil | Effet |
| - | - |
| `list_tasks` | Lire les tâches (titre masqué pour les tâches chiffrées) |
| `create_task` | Créer une tâche dans le premier calendrier de l'usager |
| `update_task` | Modifier titre, échéance, statut, priorité |
| `set_reminder` | Remplacer les rappels (`notifications`, contrat #487) |
| `list_events` | Lire l'agenda sur une période |
| `propose_delete` | Proposer une suppression — **jamais exécutée sans confirmation** |

Les dates sont calculées dans le fuseau de l'usager (`users.timezone`). Les rappels créés sont
des rappels ordinaires : ils partent par le cron unifié (courriel et push) et restent en place
si le module est retiré ensuite.

## Confirmer une suppression

Quand l'usager demande de supprimer une tâche, l'assistant crée une action `PENDING`
(renvoyée dans `actions[]` ou l'événement `tool`). Le client affiche une confirmation, puis :

- `POST /assistant/actions/{id}/confirm` → tâche supprimée (corbeille ICS, restaurable 30 jours), action `CONFIRMED` ;
- `POST /assistant/actions/{id}/reject` → rien n'est supprimé, action `REJECTED`.

Une action déjà traitée renvoie `409 ACTION_NOT_PENDING`. Les actions en attente se listent
par `GET /assistant/actions?status=PENDING`.

## Budget IA

Le budget mensuel en dollars est **commun** à tous les usages IA de la personne
(`/ai/summarize` et Assistant). Chaque tour d'appel au modèle réserve son coût maximal avant
l'appel, puis est ramené au coût réel.

| Situation | Réponse |
| - | - |
| Budget personnel atteint avant l'appel | `429 AI_BUDGET_EXCEEDED`, `scope: "user"` |
| Plafond global du serveur atteint | `429 AI_BUDGET_EXCEEDED`, `scope: "global"` |
| Budget atteint pendant une boucle d'outils | `200`, réponse partielle, `error.code = AI_BUDGET_EXCEEDED` |

`GET /assistant/usage` donne le total du mois (identique à `GET /ai/usage`) et la part de l'Assistant.

## Serveur SMTP personnel

Avec le module `assistant`, la personne peut faire partir **ses rappels par courriel** par son
propre serveur SMTP. Si ce serveur échoue au moment d'un rappel, l'envoi repasse par le SMTP de
Journaux de Bord : le rappel arrive quand même.

Parcours conseillé côté client :

1. `POST /assistant/smtp/test` avec les paramètres saisis : connexion, STARTTLS et
   authentification sont vérifiés, **aucun courriel n'est envoyé**.
2. `PUT /assistant/smtp` avec les mêmes paramètres pour les enregistrer.
3. Optionnel : `POST /assistant/smtp/test {"send_to_self": true}` (sans `host`) teste les
   paramètres enregistrés, renseigne `verified_at` et envoie un vrai courriel de test à
   l'adresse du compte.

```json
PUT /assistant/smtp
{
  "host": "smtp.exemple.com",
  "port": 587,
  "encryption": "tls",
  "username": "moi@exemple.com",
  "password": "••••••••",
  "from_email": "moi@exemple.com",
  "from_name": "Moi"
}
```

- `encryption` : `tls` = STARTTLS (port 587 en général), `ssl` = TLS implicite (port 465),
  `none` = sans chiffrement.
- Le mot de passe est chiffré côté serveur et **n'est jamais renvoyé** : `GET /assistant/smtp`
  indique seulement `has_password`. Omettre `password` dans un `PUT` le conserve.
- Les hôtes privés, locaux ou réservés (`127.0.0.1`, `10.x`, `192.168.x`…) sont refusés.
- `POST /assistant/smtp/test` est limité à 5 essais par tranche de 10 minutes.

| Code (`errors.code`) | Message à afficher |
| - | - |
| `SMTP_HOST_UNREACHABLE` | Serveur introuvable ou injoignable — vérifier l'hôte et le port |
| `SMTP_TLS_FAILED` | Chiffrement refusé — essayer `tls` sur 587 ou `ssl` sur 465 |
| `SMTP_AUTH_FAILED` | Identifiant ou mot de passe refusé |
| `SMTP_HOST_FORBIDDEN` | Adresse non autorisée |
| `SMTP_SEND_FAILED` | Connexion correcte mais envoi du courriel de test refusé |

## Codes d'erreur

| HTTP | Code | Cause |
| - | - | - |
| 401 | — | JWT absent ou invalide |
| 403 | `ACCOUNT_PENDING` / `ACCOUNT_SUSPENDED` | Compte non approuvé |
| 403 | `MODULE_NOT_AVAILABLE` | Module `assistant` non coché ou non activé |
| 403 | `ASSISTANT_CONSENT_REQUIRED` | Consentement absent ou retiré |
| 404 | — | Conversation ou action inconnue, ou d'un autre usager |
| 404 | `SMTP_NOT_CONFIGURED` | Aucun serveur SMTP personnel enregistré |
| 409 | `ACTION_NOT_PENDING` | Action déjà confirmée ou refusée |
| 422 | — | Message vide ou de plus de 4 000 caractères ; paramètres SMTP invalides |
| 422 | `SMTP_*` | Test SMTP en échec (voir « Serveur SMTP personnel ») |
| 429 | `AI_BUDGET_EXCEEDED` | Budget IA atteint |
| 429 | `SMTP_TEST_RATE_LIMITED` | Plus de 5 tests SMTP en 10 minutes |
| 502 | `AI_UPSTREAM_ERROR` | Échec du modèle (non facturé) |
| 503 | `AI_NOT_CONFIGURED` | Clé Anthropic absente côté serveur |
| 503 | `SMTP_KEY_NOT_CONFIGURED` | Chiffrement SMTP non configuré côté serveur |

## Données et confidentialité

- Envoyé à Claude : le message, l'historique récent de la conversation, et le résultat des
  outils (titres, échéances, statuts, rappels des tâches ; titres et lieux des événements).
- Jamais envoyé : le contenu des tâches chiffrées de bout en bout, les journaux, les contacts.
- L'usager peut supprimer une conversation ou tout son historique, et retirer son consentement.
- Paramètres SMTP : jamais transmis à Claude ; supprimés avec le compte.
