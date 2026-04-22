# Guide — Plugin Quiz

Version 1.0.0 · Base URL : `/quiz`

> Référence complète : [API_QUIZ_ENDPOINTS_v1_0_0.json](API_QUIZ_ENDPOINTS_v1_0_0.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Flux complet — côté hôte](#flux-complet--côté-hôte)
- [Flux complet — côté participant](#flux-complet--côté-participant)
- [Endpoints — Gestion des quiz](#endpoints--gestion-des-quiz)
- [Endpoints — Gestion des questions](#endpoints--gestion-des-questions)
- [Endpoints — Sessions](#endpoints--sessions)
- [Endpoints — Participation](#endpoints--participation)
- [Scoring dégressif](#scoring-dégressif)
- [Modèles de données](#modèles-de-données)
- [Erreurs](#erreurs)
- [Roadmap](#roadmap)
- [Migrations](#migrations)

---

## Vue d'ensemble

Le plugin Quiz permet des quiz interactifs en temps réel (style Kahoot) :

- L'**hôte** (utilisateur authentifié JWT) crée un quiz, y ajoute des questions, lance une session et la pilote
- Les **participants** rejoignent via un code 6 caractères, reçoivent un `participant_token`, soumettent leurs réponses et consultent le classement en direct
- Le **scoring dégressif** récompense la rapidité : réponse correcte immédiate = points max, réponse en fin de temps = 0 point

---

## Authentification

| Contexte | Mécanisme | Header |
| --- | --- | --- |
| Hôte (CRUD quiz, sessions) | JWT Bearer | `Authorization: Bearer <jwt_token>` |
| Participant (réponses, état) | participant_token | `Authorization: Bearer <participant_token>` |
| Join session | Aucune | — |

> Le `participant_token` est distinct du JWT. Il est obtenu via `POST /quiz/join` et est valide pour une seule session.

### Codes d'erreur auth

| Code | Cause |
| --- | --- |
| 401 | Header Authorization absent |
| 403 | Token invalide / introuvable / token ne correspond pas à la session |

> `GET /quiz/session/{id}` fait exception : il reste accessible avec un `participant_token` valide même si la session est `ended`.

---

## Flux complet — côté hôte

```txt
# 1. Créer le quiz
POST /quiz
→ { data: { id: 5 } }

# 2. Ajouter des questions
POST /quiz/5/questions    (MCQ)
→ { data: { id: 12 } }

POST /quiz/5/questions    (Vrai/Faux)
→ { data: { id: 13 } }

# 3. Lancer une session
POST /quiz/5/sessions
→ { data: { session_id: 8, session_code: "AB34KZ" } }

# 4. Afficher le code aux participants, puis démarrer
POST /quiz/sessions/8/next
→ { data: { current_question_idx: 0, total_questions: 2 } }

# 5. Attendre les réponses... puis question suivante
POST /quiz/sessions/8/next
→ { data: { current_question_idx: 1, total_questions: 2 } }

# 6. Terminer (ou la session se termine auto après la dernière question)
POST /quiz/sessions/8/end

# 7. Consulter les résultats
GET /quiz/sessions/8/results
```

---

## Flux complet — côté participant

```txt
# 1. Rejoindre via le code (sans auth)
POST /quiz/join
{ "session_code": "AB34KZ", "display_name": "Alice", "device_id": "uuid-stable" }
→ { data: { participant_token: "hmac...", session_id: 8, display_name: "Alice" } }

# 2. Consulter l'état de la session (sondage de questions)
GET /quiz/session/8
Authorization: Bearer <participant_token>
→ { data: { status: "active", current_question: { id: 12, type: "mcq", ... } } }

# 3. Soumettre une réponse
POST /quiz/session/8/answer
Authorization: Bearer <participant_token>
{ "question_id": 12, "value": "42", "response_time_ms": 3500 }
→ { data: { is_correct: true, points_earned: 83 } }

# 4. Consulter le classement
GET /quiz/session/8/leaderboard
Authorization: Bearer <participant_token>
→ { data: { leaderboard: [...], my_rank: null, total_participants: 12 } }
```

---

## Endpoints — Gestion des quiz

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| GET | `/quiz` | JWT | Lister ses quiz |
| POST | `/quiz` | JWT | Créer un quiz |
| GET | `/quiz/{id}` | JWT | Détails + questions + choix |
| PUT | `/quiz/{id}` | JWT | Modifier titre/description/status |
| DELETE | `/quiz/{id}` | JWT | Supprimer (soft delete) |
| GET | `/quiz/history` | JWT | Historique des sessions passées |

### POST /quiz

```json
{
  "title": "Culture générale",
  "description": "Questions de culture générale niveau facile",
  "status": "draft"
}
```

Statuts quiz : `draft` | `active` | `archived`.

### GET /quiz/{id} — réponse

```json
{
  "success": true,
  "data": {
    "quiz": {
      "id": 5,
      "title": "Culture générale",
      "status": "draft",
      "questions": [
        {
          "id": 12,
          "type": "mcq",
          "content": { "text": "Capitale de la France ?" },
          "points": 100,
          "time_limit_sec": 20,
          "position": 1,
          "choices": [
            { "id": 1, "content": { "text": "Paris" }, "is_correct": true },
            { "id": 2, "content": { "text": "Lyon" }, "is_correct": false }
          ]
        }
      ]
    }
  }
}
```

---

## Endpoints — Gestion des questions

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/quiz/{id}/questions` | JWT | Ajouter une question |
| PUT | `/quiz/{id}/questions/{q_id}` | JWT | Modifier une question |
| DELETE | `/quiz/{id}/questions/{q_id}` | JWT | Supprimer une question |

### Types de questions

| Type | Choix requis | Correct(s) |
| --- | --- | --- |
| `mcq` | 2–8 choix | Au moins 1 |
| `truefalse` | Exactement 2 | Exactement 1 |
| `numerical` | Aucun (Ph3) | Valeur numérique |

### POST /quiz/{id}/questions — MCQ

```json
{
  "type": "mcq",
  "content": { "text": "Capitale de la France ?" },
  "points": 100,
  "time_limit_sec": 20,
  "choices": [
    { "content": { "text": "Paris" }, "is_correct": true },
    { "content": { "text": "Lyon" }, "is_correct": false },
    { "content": { "text": "Marseille" }, "is_correct": false },
    { "content": { "text": "Bordeaux" }, "is_correct": false }
  ]
}
```

Retourne : `{ success: true, message: 'Question ajoutée', data: { id: 12 } }`

### POST /quiz/{id}/questions — Vrai/Faux

```json
{
  "type": "truefalse",
  "content": { "text": "La Terre est ronde." },
  "points": 50,
  "time_limit_sec": 10,
  "choices": [
    { "content": { "text": "Vrai" }, "is_correct": true },
    { "content": { "text": "Faux" }, "is_correct": false }
  ]
}
```

### PUT /quiz/{id}/questions/{q_id} — mise à jour partielle

Seuls les champs présents dans le body sont modifiés. La validation des choix n'est déclenchée que si `choices` est fourni.

```json
{
  "points": 200,
  "time_limit_sec": 30
}
```

Si `choices` est fourni, il **remplace intégralement** les anciens choix.

---

## Endpoints — Sessions

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/quiz/{id}/sessions` | JWT | Créer une session |
| POST | `/quiz/sessions/{sid}/next` | JWT | Avancer à la question suivante |
| POST | `/quiz/sessions/{sid}/end` | JWT | Terminer la session |
| GET | `/quiz/sessions/{sid}/results` | JWT | Résultats complets |

### POST /quiz/{id}/sessions

Crée une session avec code aléatoire 6 caractères (alphabet sans ambiguïté — sans I, O, 0, 1). Statut initial : `waiting`.

**Erreur 422** : le quiz ne contient aucune question.

Réponse `201` :

```json
{
  "success": true,
  "data": {
    "session_id": 8,
    "session_code": "AB34KZ"
  }
}
```

### POST /quiz/sessions/{sid}/next

- Passe le statut à `active` et incrémente `current_question_idx`
- Si plus aucune question : clôture automatiquement la session (`status: ended`) et calcule les rangs finaux
- Retourne `{ status: 'ended' }` en cas de fin automatique

**Erreur 409** : session déjà terminée.

### GET /quiz/sessions/{sid}/results

```json
{
  "success": true,
  "data": {
    "session": {
      "id": 8,
      "quiz_id": 5,
      "session_code": "AB34KZ",
      "status": "ended",
      "started_at": "2026-04-05T14:00:00",
      "ended_at": "2026-04-05T14:15:00"
    },
    "total_participants": 12,
    "leaderboard": [
      { "id": 3, "display_name": "Alice", "score": 283, "rank": 1 }
    ],
    "question_stats": [
      {
        "question_id": 12,
        "total_answers": 12,
        "correct_answers": 9,
        "avg_response_time_ms": 4200
      }
    ]
  }
}
```

---

## Endpoints — Participation

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/quiz/join` | Aucune | Rejoindre une session |
| GET | `/quiz/session/{id}` | participant_token | État de la session |
| POST | `/quiz/session/{id}/answer` | participant_token | Soumettre une réponse |
| GET | `/quiz/session/{id}/leaderboard` | participant_token | Classement en direct |

### POST /quiz/join

```json
{
  "session_code": "AB34KZ",
  "display_name": "Alice",
  "device_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Reconnexion** : si `device_id` a déjà rejoint cette session, retourne le token existant avec HTTP 200 (pas 201).

**Erreur 409** : session terminée (`ended`) — plus d'inscriptions acceptées.

### GET /quiz/session/{id}

Retourne la question courante **sans** `is_correct` sur les choix (évite la triche). `current_question: null` si statut `waiting` ou `ended`.

**Accessible même lorsque la session est terminée** (`status: ended`) — le guard `participant_token` ne bloque pas cet endpoint après la fin de session.

```json
{
  "data": {
    "session_id": 8,
    "status": "active",
    "current_question_idx": 0,
    "current_question": {
      "id": 12,
      "type": "mcq",
      "content": { "text": "Capitale de la France ?" },
      "points": 100,
      "time_limit_sec": 20,
      "choices": [
        { "id": 1, "content": { "text": "Paris" } },
        { "id": 2, "content": { "text": "Lyon" } }
      ],
      "total": 2,
      "index": 0
    },
    "quiz_settings": {
      "result_visibility": "immediate",
      "time_mode": "per_question",
      "total_time_sec": null,
      "show_leaderboard": true
    }
  }
}
```

Lorsque `status: ended`, la réponse est identique mais `current_question` vaut `null`.

### POST /quiz/session/{id}/answer

```json
{
  "question_id": 12,
  "value": "1",
  "response_time_ms": 3500
}
```

- `value` : ID du choix sélectionné (MCQ / Vrai-Faux) ou valeur numérique (Ph3)
- **Erreur 409** : déjà répondu à cette question, ou `question_id` n'est pas la question courante

Réponse :

```json
{
  "success": true,
  "data": {
    "is_correct": true,
    "points_earned": 83
  }
}
```

### GET /quiz/session/{id}/leaderboard

```json
{
  "data": {
    "leaderboard": [
      { "id": 3, "display_name": "Alice", "score": 83, "rank": null }
    ],
    "total_participants": 12,
    "my_rank": null
  }
}
```

> `rank` et `my_rank` sont `null` pendant la session active (calculés par `RANK() OVER` uniquement à la fin).

---

## Scoring dégressif

$$\text{points\_earned} = \lfloor points \times \max(0, 1 - \frac{response\_time\_ms}{time\_limit\_sec \times 1000}) \rfloor$$

| Exemple | Valeur |
| --- | --- |
| 100 pts · 30 s · réponse à 0 ms | 100 pts |
| 100 pts · 30 s · réponse à 10 s | 66 pts |
| 100 pts · 30 s · réponse à 30 s | 0 pts |
| Réponse incorrecte | 0 pts |

---

## Modèles de données

### quiz

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | integer | PK |
| `user_id` | integer | Propriétaire |
| `title` | string (max 255) | |
| `description` | text | nullable |
| `status` | enum | `draft` \| `active` \| `archived` |
| `created_at` | datetime | |
| `updated_at` | datetime | |

### quiz_questions

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | integer | PK |
| `quiz_id` | integer | FK |
| `type` | enum | `mcq` \| `truefalse` \| `numerical` |
| `content` | JSON | `{ text, latex?, image_url? }` |
| `points` | integer | défaut 100 |
| `time_limit_sec` | integer | 5–300, défaut 30 |
| `position` | integer | Ordre auto-incrémenté |

### quiz_choices

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | integer | PK |
| `question_id` | integer | FK |
| `content` | JSON | `{ text, latex?, image_url? }` |
| `is_correct` | boolean | |

### quiz_sessions

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | integer | PK |
| `quiz_id` | integer | FK |
| `host_user_id` | integer | FK |
| `session_code` | string(6) | UNIQUE |
| `status` | enum | `waiting` \| `active` \| `ended` |
| `current_question_idx` | integer | -1 = avant début |
| `started_at` | datetime | nullable |
| `ended_at` | datetime | nullable |

### quiz_participants

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | integer | PK |
| `session_id` | integer | FK |
| `display_name` | string(50) | |
| `device_id` | string(128) | |
| `participant_token` | string | HMAC-SHA256(session_id\|participant_id\|device_id, JWT_SECRET) |
| `score` | integer | Cumul des points |
| `final_rank` | integer | null pendant la session |

### quiz_participant_answers

| Colonne | Type | Notes |
| --- | --- | --- |
| `participant_id` | integer | FK |
| `question_id` | integer | FK |
| `value` | string | ID choix ou valeur numérique |
| `is_correct` | boolean | |
| `points_earned` | integer | |
| `response_time_ms` | integer | |
| UNIQUE | `(participant_id, question_id)` | Une réponse par question |

---

## Erreurs

| Code | Signification |
| --- | --- |
| 401 | Header Authorization absent |
| 403 | participant_token invalide/introuvable \| JWT invalide \| token ne correspond pas à la session |
| 404 | Quiz / question / session introuvable |
| 409 | Session déjà terminée \| Déjà répondu \| question_id non courant \| Session fermée aux inscriptions |
| 422 | Validation échouée (trop peu de choix, champ manquant…) |

---

## Roadmap

| Phase | Contenu |
| --- | --- |
| Ph2 | Variables dynamiques — colonnes `has_variables`, `variables_config`, `expression` ; table `quiz_session_questions` |
| Ph3 | Moteur math (mossadal/math-executor) — `VariableService`, type `numerical` |
| Ph4 | Microservice Node.js WebSocket — push `question_started`, `session_ended`, `leaderboard_updated` |
| Ph5 | Export CSV — `GET /quiz/sessions/{sid}/export?format=csv` |

---

## Migrations

| Fichier | Description |
| --- | --- |
| [migrations/](migrations/) | Migrations SQL du plugin Quiz |
