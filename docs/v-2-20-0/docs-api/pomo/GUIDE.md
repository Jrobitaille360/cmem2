# Guide — Plugin Pomo (Pomodoro)

Version 1.0.0 · Base URL : `/pomo`

> Référence complète : [API_POMO_ENDPOINTS_v1_0_0.json](API_POMO_ENDPOINTS_v1_0_0.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Phases d'implémentation](#phases-dimplémentation)
- [Authentification](#authentification)
- [Endpoints — Engagement (Ph1A)](#endpoints--engagement-ph1a)
- [Endpoints — Support (Ph1B)](#endpoints--support-ph1b)
- [Endpoints — Sync cloud (Ph2)](#endpoints--sync-cloud-ph2)
- [Endpoints — Stripe (Ph3)](#endpoints--stripe-ph3)
- [Notes importantes](#notes-importantes)
- [Erreurs](#erreurs)
- [Migrations](#migrations)

---

## Vue d'ensemble

Le plugin Pomo est intégré dans cmem2 via le système de plugins. Il couvre :

- **Ph1A** : Engagement MVP public — waitlist (email) et sondage (5 questions), sans authentification
- **Ph1B** : Formulaire de support avec confirmation email, nécessite JWT
- **Ph2** : Synchronisation cloud des données Pomodoro (sessions, tâches, paramètres) — JWT requis
- **Ph3** : Abonnements via Stripe, webhook de paiement

> Inscription et connexion utilisent les endpoints cmem2 core : `POST /users/register` et `POST /auth/login`.

---

## Phases d'implémentation

| Phase | Contenu | Statut |
| --- | --- | --- |
| Ph1A | Engagement public (waitlist, sondage) | **Actif** |
| Ph1B | Support (formulaire, email confirmation) | À venir — non implémenté |
| Ph2 | Sync cloud sessions/tâches/paramètres | À venir — non implémenté |
| Ph3 | Abonnements Stripe | À venir — non implémenté |

> Seul `POST /pomo/engagement` est routé par le serveur. Toute autre route `/pomo/*`
> retourne actuellement `404`. Les sections Ph1B, Ph2 et Ph3 ci-dessous décrivent le
> contrat **prévu** et peuvent changer avant implémentation.

---

## Authentification

| Phase | Auth |
| --- | --- |
| Ph1A — `POST /pomo/engagement` | Aucune |
| Ph1B — `POST /pomo/support` | `Authorization: Bearer <jwt_token>` |
| Ph2 — sync cloud | `Authorization: Bearer <jwt_token>` |
| Ph3 — webhook Stripe | Signature Stripe (`Stripe-Signature` header) |

---

## Endpoints — Engagement (Ph1A)

### POST /pomo/engagement

Soumettre une inscription à la waitlist ou un sondage. Identifié par `device_id` stable côté client.

**Auth** : Aucune

```json
{
  "type": "waitlist",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "email": "alice@example.com",
  "platform": "ios",
  "language": "fr",
  "app_version": "1.0.0",
  "build_number": "42",
  "timestamp_utc": "2026-04-05T12:00:00Z"
}
```

Réponse `201` :

```json
{
  "success": true,
  "reference_id": 101
}
```

**Sondage** (`type=survey`) :

```json
{
  "type": "survey",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "responses": {
    "q1": "yes",
    "q2": "no",
    "q3": "maybe",
    "q4": "yes",
    "q5": "yes"
  },
  "suggestion": "Ajouter des statistiques hebdomadaires",
  "platform": "android",
  "session_duration": 180,
  "network_status": "online",
  "timestamp_utc": "2026-04-05T12:01:00Z"
}
```

| Champ | Type | Requis | Notes |
| --- | --- | --- | --- |
| `type` | string | Oui | `waitlist` \| `survey` |
| `device_id` | string | Oui | UUID stable, max 36 chars |
| `email` | string | Si `type=waitlist` | max 254 chars |
| `responses` | object | Si `type=survey` | clés `q1`–`q5`, valeurs `yes` \| `no` \| `maybe` |
| `suggestion` | string | Non | Texte libre (survey) |
| `platform` | string | Non | `android` \| `ios` \| `web` \| `windows` \| `macos` \| `linux` |
| `timestamp_utc` | datetime | Oui | ISO 8601 UTC |

**Règles** :

- `type=waitlist` : un seul enregistrement par email (409 si doublon)
- `type=survey` : plusieurs soumissions par `device_id` acceptées

---

## Endpoints — Support (Ph1B)

> **Non implémenté** — cette route retourne actuellement `404`. Contrat prévu ci-dessous.

### POST /pomo/support

Soumettre un formulaire de support. Envoie un email à l'équipe et une confirmation à l'utilisateur. Enregistre la demande avec un `reference_id` unique.

**Auth** : JWT requis — sera activé uniquement si `SUPPORT_FORM_ENABLED=true` en configuration

```json
{
  "email": "alice@example.com",
  "message": "L'application se ferme quand je lance une session de 25 minutes.",
  "infos": {
    "device_id": "550e8400-e29b-41d4-a716-446655440000",
    "platform": "ios",
    "app_version": "1.0.0",
    "build_number": "42",
    "locale": "fr_CA",
    "timezone": "America/Montreal",
    "screen_resolution": "390x844",
    "network_status": "online"
  }
}
```

Réponse `201` :

```json
{
  "success": true,
  "message": "Demande de support reçue",
  "reference_id": "SUP-2026-042"
}
```

| Champ | Requis | Notes |
| --- | --- | --- |
| `email` | Oui | Courriel de contact |
| `message` | Oui | Description du problème |
| `infos` | Non | Contexte technique de l'appareil |

---

## Endpoints — Sync cloud (Ph2)

> **Non implémenté** — ces routes retournent actuellement `404`. Contrat prévu :
> disponible quand `POMO_SYNC_ENABLED=true`, JWT requis.

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/pomo/sessions` | Lister les sessions Pomodoro |
| POST | `/pomo/sessions` | Créer une session |
| PUT | `/pomo/sessions/{id}` | Mettre à jour |
| DELETE | `/pomo/sessions/{id}` | Supprimer |
| GET | `/pomo/tasks` | Lister les tâches |
| POST | `/pomo/tasks` | Créer une tâche |
| PUT | `/pomo/tasks/{id}` | Mettre à jour |
| DELETE | `/pomo/tasks/{id}` | Supprimer |
| GET | `/pomo/settings` | Paramètres utilisateur |
| PUT | `/pomo/settings` | Modifier paramètres |
| GET | `/pomo/stats` | Statistiques personnelles |

---

## Endpoints — Stripe (Ph3)

> **Non implémenté** — cette route retourne actuellement `404`. Contrat prévu ci-dessous.

| Méthode | Endpoint | Description |
| --- | --- | --- |
| POST | `/pomo/stripe/webhook` | Webhook Stripe (paiement, abonnement) |

La requête doit inclure le header `Stripe-Signature` pour validation HMAC. Voir la [documentation Stripe](https://stripe.com/docs/webhooks/signatures).

---

## Notes importantes

- Toutes les routes sont préfixées `/pomo/` pour éviter les collisions avec le core
- `GET /health` est fourni par le core (`PublicRouteHandler`) — non redéfini dans ce plugin
- Le champ `timestamp_utc` doit être en UTC (ISO 8601), pas en heure locale

---

## Erreurs

| Code | Signification |
| --- | --- |
| 404 | Route non implémentée (Ph1B, Ph2, Ph3) |
| 409 | Email déjà dans la waitlist (`type=waitlist`) |
| 422 | Validation échouée — détail dans `errors[{ field, code, message }]` |

---

## Migrations

| Fichier | Description |
| --- | --- |
| [migrations/](migrations/) | Migrations SQL du plugin Pomo |
