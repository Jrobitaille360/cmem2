# Guide — Plugin Puzzle (client mobile)

Version 1.1.0 · Base URL : `/puzzle`

> Référence complète : [API_PUZZLE_ENDPOINTS.json](API_PUZZLE_ENDPOINTS.json)
> Guide admin images : [guide_image_manager.md](guide_image_manager.md)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Démarrage rapide](#démarrage-rapide)
- [Abonnement premium](#abonnement-premium)
- [Pseudonyme](#pseudonyme)
- [Carrousel d'images](#carrousel-dimages)
- [Thèmes](#thèmes)
- [Livraison des images](#livraison-des-images)
- [Sauvegarde en ligne](#sauvegarde-en-ligne)
- [Casse-têtes partagés](#casse-têtes-partagés)
- [Codes d'erreur](#codes-derreur)
- [Intégration client Flutter](#intégration-client-flutter)

---

## Vue d'ensemble

Le plugin Puzzle fournit une API REST pour une application mobile de puzzle (Flutter).
**Aucun compte utilisateur n'est requis** : l'authentification repose sur un token d'appareil opaque,
généré une fois à l'installation.

Fonctionnalités disponibles :

- Carrousel de 30 images (gratuit)
- Banque d'images par thèmes (premium)
- Sauvegarde de la progression en ligne (premium)
- Casse-têtes partagés en temps réel entre deux abonnés (premium)

---

## Authentification

### Token d'appareil

Toutes les routes `/puzzle/*` (sauf l'enregistrement initial) exigent un `device_token` :

```txt
Authorization: Bearer {device_token}
```

| Propriété | Valeur |
| --- | --- |
| Type | Bearer opaque (64 chars hex) |
| Durée de vie | 365 jours |
| Renouvellement | Automatique via `POST /puzzle/auth/register-device` |
| Stockage client | `SharedPreferences` — clé `puzzle_device_token` |

### Modes d'accès

| Mode | Condition | Endpoints accessibles |
| --- | --- | --- |
| Public | Aucun | `POST /puzzle/auth/register-device` uniquement |
| Gratuit | `device_token` valide | Auth, carrousel, livraison images, pseudonyme |
| Premium | `device_token` + `is_premium = 1` non expiré | Tout, y compris thèmes, backup, partagé |

---

## Démarrage rapide

### 1 — Enregistrer l'appareil

```txt
POST /puzzle/auth/register-device
Content-Type: application/json

{
  "device_uuid": "550e8400-e29b-41d4-a716-446655440000"
}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ success: true, data: { device_token: "abc...64chars", expires_at: "2027-04-07T..." } }` |
| 422 | `device_uuid` absent ou vide |

> Le `device_uuid` est un UUID v4 généré une fois à l'installation et stocké dans
> `SharedPreferences` (clé `puzzle_device_uuid`). Il ne change jamais. Si le token est expiré
> (401 sur un autre endpoint), rappeler cet endpoint avec le même `device_uuid` pour le renouveler.

### 2 — Charger le carrousel

```txt
GET /puzzle/carousel
Authorization: Bearer {device_token}
Accept-Language: fr
```

Réponse :

```json
{
  "success": true,
  "message": "Carrousel chargé",
  "data": {
    "images": [
      {
        "uid": "img-uid-abc",
        "label": "Coucher de soleil",
        "thumb_url": "/puzzle/thumb/img-uid-abc",
        "full_url": "/puzzle/image/img-uid-abc",
        "themes": ["nature"],
        "created_at": "2026-01-15T10:00:00Z"
      }
    ],
    "total": 30
  }
}
```

---

## Abonnement premium

L'abonnement Google Play doit être validé côté serveur à chaque démarrage de l'app.
Le serveur enregistre l'abonnement dans la table `subscriptions` (source unique de vérité).
L'accès premium est ensuite retrouvé par `purchase_token` — il **survit à une réinstallation**.

### Valider un achat

```txt
POST /puzzle/auth/verify-subscription
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "purchase_token": "token-recu-de-google-play",
  "product_id": "premium_monthly"
}
```

Valeurs acceptées pour `product_id` :

- `premium_monthly`
- `premium_yearly`

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { is_premium: true, product_id: "...", expires_at: "..." } }` — abonné actif |
| 200 | `{ data: { is_premium: false, ... } }` — abonnement expiré |
| 401 | Token d'appareil absent ou expiré |
| 422 | `purchase_token` ou `product_id` manquant, ou `product_id` invalide |

> Si `is_premium = false`, repasser l'app en mode gratuit sans afficher d'erreur.
> Si `is_premium = true`, déverrouiller les fonctionnalités premium.

**Upgrade / downgrade** : Google Play génère un nouveau `purchaseToken` lors d'un changement
de plan et inclut `linkedPurchaseToken` pointant vers l'ancien. Le serveur expire automatiquement
l'ancien abonnement — aucun traitement particulier côté client.

**Réinstallation** : Google Play restaure l'achat au premier lancement. Re-soumettre le même
`purchase_token` restaure l'accès premium sur le nouvel appareil.

---

## Pseudonyme

Le pseudonyme est **requis** avant de créer ou rejoindre un casse-tête partagé.
Il est unique sur tout le serveur.

### Définir ou modifier le pseudonyme

```txt
POST /puzzle/auth/pseudonym
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "pseudonym": "JoueurDuDimanche"
}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { pseudonym: "JoueurDuDimanche" } }` |
| 401 | Token d'appareil absent ou expiré |
| 409 | Pseudonyme déjà utilisé — code `PSEUDONYM_TAKEN` |
| 422 | Pseudonyme vide, trop court (< 3) ou trop long (> 50) |

---

## Carrousel d'images

Le carrousel contient **30 images actives** triées par `sort_order`.
Les labels sont traduits selon le header `Accept-Language` (`fr` | `en` | `es`, repli sur `fr`).

### Charger le carrousel

```txt
GET /puzzle/carousel
Authorization: Bearer {device_token}
Accept-Language: fr
```

### Remplacer une image complétée (gratuit)

Maximum **un remplacement par jour** par appareil.

```txt
POST /puzzle/carousel/replace-one
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "known_uids": ["uid-1", "uid-2", "uid-3"],
  "completed": [
    { "uid": "uid-1", "completed_at": "2026-04-06" }
  ]
}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { replaces_uid: "uid-1", image: { uid, label, thumb_url, full_url, themes, created_at } } }` |
| 401 | Token d'appareil absent ou expiré |
| 404 | Aucune image de remplacement disponible — code `NO_REPLACEMENT_AVAILABLE` |
| 422 | `known_uids` ou `completed` absent ou invalide |
| 429 | Un remplacement a déjà eu lieu aujourd'hui — code `ALREADY_REPLACED_TODAY` |

### Remplacer toutes les images complétées (premium)

```txt
POST /puzzle/carousel/replace-all
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "known_uids": ["uid-1", "uid-2", "uid-3"],
  "replace_uids": ["uid-1", "uid-3"]
}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { replacements: [ { replaces_uid, image: {...} } ], unavailable_count: 0 } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis — code `SUBSCRIPTION_REQUIRED` |
| 422 | `known_uids` ou `replace_uids` absent ou invalide |

---

## Thèmes

> Réservé aux abonnés premium.

### Lister les thèmes

```txt
GET /puzzle/themes
Authorization: Bearer {device_token}
Accept-Language: fr
```

Réponse :

```json
{
  "success": true,
  "data": {
    "themes": [
      {
        "slug": "nature",
        "label": "Nature",
        "thumb_url": "/puzzle/thumb/theme/nature",
        "image_count": 12
      }
    ]
  }
}
```

### Charger les images d'un thème

```txt
GET /puzzle/themes/{slug}/images
Authorization: Bearer {device_token}
Accept-Language: fr
```

Paramètre URL : `slug` — identifiant du thème (ex. `nature`, `hiver`).

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { theme: { slug, label }, images: [...], total: 12 } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis — code `SUBSCRIPTION_REQUIRED` |
| 404 | Thème inexistant ou inactif — code `THEME_NOT_FOUND` |

---

## Livraison des images

Les fichiers images sont **protégés** — ils ne sont pas servis directement par Apache.
Toutes les URLs `thumb_url` et `full_url` retournées par l'API exigent le header `Authorization`.

| Route | Dimensions | Format |
| --- | --- | --- |
| `GET /puzzle/thumb/{uid}` | 200 × 200 px | JPEG |
| `GET /puzzle/image/{uid}` | max 1920 px (côté long) | JPEG qualité 85 |
| `GET /puzzle/thumb/theme/{slug}` | miniature thème | JPEG |

> En Flutter : utiliser `Image.network(url, headers: {'Authorization': 'Bearer $deviceToken'})`.
> Le serveur envoie `Cache-Control: private, max-age=86400` — laisser Flutter/HTTP mettre en cache.

---

## Sauvegarde en ligne

> Réservé aux abonnés premium. Taille maximale : 512 Ko.

Le blob de sauvegarde est **opaque** : le serveur le stocke tel quel et le restitue à l'identique.
Il contient typiquement la progression, les exploits, les paramètres locaux de l'app.

### Sauvegarder

```txt
POST /puzzle/backup
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "backup": { "progress": {...}, "exploits": [...] }
}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { saved_at: "2026-04-07T..." } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis — code `SUBSCRIPTION_REQUIRED` |
| 413 | Sauvegarde trop volumineuse (max 512 Ko) |
| 422 | Champ `backup` absent |

### Restaurer

```txt
GET /puzzle/backup
Authorization: Bearer {device_token}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { backup: {...}, saved_at: "2026-04-07T..." } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis — code `SUBSCRIPTION_REQUIRED` |
| 404 | Aucune sauvegarde disponible |

---

## Casse-têtes partagés

> Réservé aux abonnés premium. Requiert un pseudonyme défini. Synchronisation par polling (2–3 s).

### Créer un casse-tête partagé

```txt
POST /puzzle/shared
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "image_uid": "img-uid-abc",
  "piece_count": 100,
  "partner_pseudonym": "AutreJoueur"
}
```

Le champ `initial_pieces` est optionnel — s'il est fourni, il sert d'état de départ
(le serveur n'en génère pas de nouveau). S'il est absent, le serveur génère un seed aléatoire.

Réponses :

| Code | Signification |
| --- | --- |
| 201 | `{ data: { shared_uid, image_uid, image_label, piece_count, seed, creator_pseudonym, partner_pseudonym, created_at } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis |
| 404 | Image inactive ou introuvable |
| 404 | Partenaire introuvable ou non abonné — code `PARTNER_NOT_FOUND` |
| 422 | Champ requis manquant ou `piece_count` \< 2 |

### Lister ses casse-têtes partagés actifs

```txt
GET /puzzle/shared
Authorization: Bearer {device_token}
```

Réponse :

```json
{
  "success": true,
  "data": {
    "shared_puzzles": [
      {
        "shared_uid": "sh-uid-xyz",
        "image_uid": "img-uid-abc",
        "image_label": "Coucher de soleil",
        "thumb_url": "/puzzle/thumb/img-uid-abc",
        "piece_count": 100,
        "completion": 42,
        "partner_pseudonym": "AutreJoueur",
        "last_activity_at": "2026-04-07T14:00:00Z"
      }
    ]
  }
}
```

### Charger l'état complet

À appeler à l'ouverture de l'écran de jeu. Retourne toutes les pièces + le `last_event_id`
à utiliser pour le polling incrémental.

```txt
GET /puzzle/shared/{shared_uid}/state
Authorization: Bearer {device_token}
```

Réponse :

```json
{
  "data": {
    "shared_uid": "sh-uid-xyz",
    "image_uid": "img-uid-abc",
    "piece_count": 100,
    "seed": 42195,
    "completion": 42,
    "last_event_id": 318,
    "pieces": [
      { "piece_id": 0, "x": 0.12, "y": 0.45, "rotation": 90, "locked": false }
    ]
  }
}
```

### Envoyer un mouvement de pièce

```txt
POST /puzzle/shared/{shared_uid}/move
Authorization: Bearer {device_token}
Content-Type: application/json

{
  "piece_id": 0,
  "x": 0.25,
  "y": 0.60,
  "rotation": 0,
  "locked": true
}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { event_id: 319, completion: 43 } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis |
| 404 | Casse-tête introuvable ou archivé — code `SHARED_NOT_FOUND` |
| 422 | `piece_id`, `x` ou `y` manquant |

> Appliquer le mouvement localement **avant** d'envoyer la requête (fire-and-forget UI).

### Polling des événements du partenaire

```txt
GET /puzzle/shared/{shared_uid}/events?after={last_event_id}
Authorization: Bearer {device_token}
```

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ data: { events: [...], last_event_id: 320, completion: 44, partner_active: true } }` |
| 200 | `{ data: { events: [], last_event_id: 318, completion: 42, partner_active: false } }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis |
| 404 | Casse-tête introuvable ou archivé |

Flux de polling recommandé :

1. Appeler toutes les **2 500 ms**
2. Appliquer chaque `event` comme mouvement de pièce sur le plateau local
3. Stocker le `last_event_id` retourné pour le prochain appel
4. Afficher un indicateur de présence si `partner_active = true`
5. Arrêter le polling quand `completion = 100`, erreur 404, ou destruction du widget

### Quitter un casse-tête partagé

```txt
POST /puzzle/shared/{shared_uid}/leave
Authorization: Bearer {device_token}
```

Archive le partagé pour **les deux participants**. Accessible au créateur comme au partenaire.

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ message: "Vous avez quitté le casse-tête partagé" }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis |
| 404 | Casse-tête introuvable ou déjà archivé |

### Supprimer définitivement un casse-tête partagé

```txt
DELETE /puzzle/shared/{shared_uid}
Authorization: Bearer {device_token}
```

Supprime le partagé et tout son historique (cascade). **Réservé au créateur.**

Réponses :

| Code | Signification |
| --- | --- |
| 200 | `{ message: "Casse-tête partagé supprimé" }` |
| 401 | Token d'appareil absent ou expiré |
| 403 | Abonnement requis ou non-créateur — codes `SUBSCRIPTION_REQUIRED` / `NOT_CREATOR` |
| 404 | Casse-tête introuvable ou archivé |

---

## Codes d'erreur

| Code | HTTP | Description |
| --- | --- | --- |
| `DEVICE_NOT_FOUND` | 401 | Token d'appareil inconnu ou expiré |
| `SUBSCRIPTION_REQUIRED` | 403 | Endpoint réservé aux abonnés actifs |
| `SUBSCRIPTION_INVALID` | 422 | Reçu Google Play invalide ou abonnement expiré |
| `PSEUDONYM_TAKEN` | 409 | Pseudonyme déjà utilisé par un autre appareil |
| `NO_REPLACEMENT_AVAILABLE` | 404 | Aucune image de remplacement disponible |
| `ALREADY_REPLACED_TODAY` | 429 | Un remplacement a déjà eu lieu aujourd'hui |
| `THEME_NOT_FOUND` | 404 | Slug de thème inexistant ou inactif |
| `PARTNER_NOT_FOUND` | 404 | Pseudonyme partenaire introuvable ou non abonné |
| `SHARED_NOT_FOUND` | 404 | Casse-tête partagé introuvable ou archivé |
| `NOT_CREATOR` | 403 | Action réservée au créateur du partagé |

---

## Intégration client Flutter

### Stockage local (SharedPreferences)

| Clé | Contenu | Remarques |
| --- | --- | --- |
| `puzzle_device_uuid` | UUID v4 (string) | Généré une fois à l'installation, ne change jamais |
| `puzzle_device_token` | Token 64 chars hex | Remplacé à chaque renouvellement |
| `puzzle_pseudonym` | Pseudonyme (string) | Optionnel — requis pour le mode partagé |

### Renouvellement du token

```txt
Si 401 reçu sur n'importe quel endpoint :
  → POST /puzzle/auth/register-device { device_uuid }
  → Stocker le nouveau device_token
  → Relancer la requête originale
```

### Langue des labels

Envoyer `Accept-Language: fr` (ou `en` / `es`) dans toutes les requêtes pour obtenir
les labels traduits des images et des thèmes.

### Chargement des images protégées

```dart
Image.network(
  '$apiBase/puzzle/thumb/$uid',
  headers: {'Authorization': 'Bearer $deviceToken'},
)
```

Le serveur retourne `Cache-Control: private, max-age=86400`.
Laisser le cache HTTP de Flutter gérer la mise en cache.

### Démarrage de l'app — flux complet

```txt
1. Lire puzzle_device_uuid depuis SharedPreferences
   Si absent : génerer un UUID v4, le stocker
2. POST /puzzle/auth/register-device { device_uuid }
   → Stocker device_token et expires_at
3. Si abonnement Google Play détecté :
   POST /puzzle/auth/verify-subscription { purchase_token, product_id }
   → Activer ou désactiver les fonctionnalités premium selon is_premium
4. GET /puzzle/carousel → afficher les images
```
