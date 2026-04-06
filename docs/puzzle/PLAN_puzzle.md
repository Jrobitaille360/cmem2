# Plan : Plugin Puzzle dans cmem2_API

> 6 avril 2026

## TL;DR

Créer un plugin PHP `src/puzzle/` intégré au système de plugins cmem2 existant. Le backend gère
l'authentification sans compte (token d'appareil), l'abonnement Google Play, la banque d'images
multilingue avec thèmes, la sauvegarde en ligne (blob opaque), et la synchronisation en temps réel
des casse-têtes partagés via polling. Aucune donnée personnelle n'est collectée.

---

## Phase 0 — Prérequis plugin *(bloquant — déjà planifié dans PLAN_pomo.md)*

1. `src/Core/AbstractPlugin.php` doit exister avec `safeLog()`, `deactivate()`, `getDependencies()`,
   hook `runMigrations()`
2. `src/Core/PluginManager.php` — exclusions hardcodées `'auth_groups'`, `'Core'` supprimées
3. `src/ics/CalendarPlugin.php` — hérite `AbstractPlugin` ; `initialize()` appelle
   `registerPluginRoutes('ics', $this->getRouteHandlers())` sans redéclarer les factories

> Si la Phase 0 du plan Pomo est déjà implémentée → aller directement en Phase 1.

---

## Phase 1 — Authentification appareil & abonnement *(1–2 semaines)*

### Routes publiques *(préfixe `/puzzle`, pas d'Authorization)*

| Méthode | Route | Description |
| ------- | ----- | ----------- |
| POST | `/puzzle/auth/register-device` | Enregistrement d'un appareil, retourne `device_token` |

### Routes authentifiées par `device_token` (Bearer)

| Méthode | Route | Premium | Description |
| ------- | ----- | ------- | ----------- |
| POST | `/puzzle/auth/verify-subscription` | Non | Valider reçu Google Play, marquer `is_premium` |
| POST | `/puzzle/auth/pseudonym` | Non | Choisir / modifier le pseudonyme |

### Fichiers à créer

1. **`src/puzzle/plugin.json`** — name `Puzzle`, namespace `Puzzle`, main_class
   `Puzzle\PuzzlePlugin`, dépendance `cmem2_core >=1.3.0`
2. **`src/puzzle/PuzzlePlugin.php`** — hérite `AbstractPlugin`
   - `initialize()` → `PluginManager::getInstance()->registerPluginRoutes('puzzle', $this->getRouteHandlers())`
   - `getRouteHandlers()` → retourne les lazy factories (clé `puzzle`)
3. **`src/puzzle/Routing/PuzzleRouteHandler.php`** — UN seul handler pour tout `/puzzle` ;
   auth conditionnelle par sous-route (`device_token` via `PuzzleAuthMiddleware` ou public)
4. **`src/puzzle/Middleware/PuzzleAuthMiddleware.php`** — valide le Bearer `device_token`
   dans `puzzle_devices` ; retourne 401 si absent ou expiré
5. **`src/puzzle/Middleware/PremiumMiddleware.php`** — vérifie `is_premium = 1` et
   `premium_expires_at > NOW()` ; retourne `SUBSCRIPTION_REQUIRED` sinon
6. **`src/puzzle/Controllers/AuthController.php`** — `registerDevice()`, `verifySubscription()`,
   `setPseudonym()`
7. **`src/puzzle/Services/DeviceTokenService.php`** — génération token opaque 64 chars
   (`bin2hex(random_bytes(32))`), durée `PUZZLE_DEVICE_TOKEN_DAYS`
8. **`src/puzzle/Services/GooglePlayService.php`** — appel Google Play Developer API
   (REST), valide `purchase_token` + `product_id`, retourne `expires_at`
9. **`src/puzzle/Models/PuzzleDevice.php`**
10. **`src/puzzle/migrations/001_puzzle_base.sql`**

### Logique métier clé

- **`device_uuid`** : UUID généré côté app, enregistré UNE seule fois — toute tentative
  de re-enregistrement d'un UUID existant régénère uniquement le `device_token` (renouvellement)
- **`device_token`** : 64 chars hex, durée `PUZZLE_DEVICE_TOKEN_DAYS` (365j), renouvelé
  automatiquement si expiré lors du prochain appel
- **Google Play** : validation via `https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{package}/purchases/subscriptions/{productId}/tokens/{purchaseToken}`
  avec service account ; `is_premium` = false si `expiryTimeMillis` < now
- **Pseudonyme** : unique en DB, 3–50 chars, uniquement pour les appareils impliqués dans
  le partage ; une seule tentative de changement tolérée (sinon `PSEUDONYM_TAKEN`)

---

## Phase 2 — Banque d'images & thèmes *(1–2 semaines)*

### Routes `device_token` requis

| Méthode | Route | Premium | Description |
| ------- | ----- | ------- | ----------- |
| GET | `/puzzle/carousel` | Non | 30 images actives du carrousel |
| POST | `/puzzle/carousel/replace-one` | Non | Remplacer une image (1/jour max) |
| POST | `/puzzle/carousel/replace-all` | Oui | Remplacer toutes les images à 100% |
| GET | `/puzzle/themes` | Oui | Liste des thèmes actifs |
| GET | `/puzzle/themes/{slug}/images` | Oui | Images d'un thème |
| GET | `/puzzle/thumb/{uid}` | Non | Servir thumbnail appareil (via PHP) |
| GET | `/puzzle/image/{uid}` | Non | Servir image complète (via PHP) |
| GET | `/puzzle/thumb/theme/{slug}` | Non | Servir thumbnail de thème (via PHP) |

### Fichiers à créer

1. **`src/puzzle/Controllers/CarouselController.php`** — `getCarousel()`, `replaceOne()`,
   `replaceAll()`
2. **`src/puzzle/Controllers/ThemeController.php`** — `getThemes()`, `getThemeImages()`
3. **`src/puzzle/Controllers/ImageDeliveryController.php`** — lecture fichier via `readfile()`,
   envoi `Content-Type: image/jpeg`, `Cache-Control: private, max-age=86400`
4. **`src/puzzle/Models/PuzzleImage.php`**
5. **`src/puzzle/Models/PuzzleTheme.php`**
6. **`uploads/puzzle/.htaccess`** — `Deny from all` pour interdire accès direct Apache

### Internationalisation

- En-tête `Accept-Language` lu dans chaque requête : `fr` (défaut), `en`, `es`
- Repli sur `fr` si traduction absente ou langue non reconnue
- Labels lus : `puzzle_image_translations` + `puzzle_theme_translations`

### Logique métier clé

- **`GET /puzzle/carousel`** : `ORDER BY sort_order ASC` sur les 30 premières images
  `status = 'active'` et `is_carousel = 1`
- **`POST /puzzle/carousel/replace-one`** : sélectionne l'image complétée la plus ancienne
  (`completed[].completed_at` minimal), vérifie que `last_replaced_at != CURDATE()` ;
  erreur `ALREADY_REPLACED_TODAY` sinon ; retourne une image `status = 'active'` dont
  l'UID n'est pas dans `known_uids` ; met à jour `last_replaced_at = CURDATE()`
- **`POST /puzzle/carousel/replace-all`** : pour chaque UID de `replace_uids`, retourne
  une image `status = 'active'` hors `known_uids` ; si pool insuffisant, renseigne
  `unavailable_count` ; abonné uniquement
- **Fichiers image** : chemin physique = `PUZZLE_UPLOAD_DIR . 'thumbs/' . $uid . '.jpg'`
  (thumbs) et `PUZZLE_UPLOAD_DIR . 'images/' . $uid . '.jpg'` (full) ; 401 si token
  invalide, 404 si uid inconnu ou `status != 'active'`

---

## Phase 3 — Sauvegarde en ligne *(0,5 semaine)*

### Routes `device_token` + abonné

| Méthode | Route | Description |
| ------- | ----- | ----------- |
| POST | `/puzzle/backup` | Enregistrer blob JSON |
| GET | `/puzzle/backup` | Restaurer blob JSON |

### Fichiers à créer

1. **`src/puzzle/Controllers/SyncController.php`** — `saveBackup()`, `getBackup()`

### Logique métier clé

- Blob opaque : le serveur stocke `backup_json` (LONGTEXT) dans `puzzle_devices` sans
  interpréter le contenu
- `POST /puzzle/backup` : remplace `backup_json` et retourne `saved_at = NOW()`
- `GET /puzzle/backup` : retourne le JSON tel quel + `saved_at` (`updated_at` du device)
- Taille max recommandée : valider `Content-Length` < 512 Ko côté serveur

---

## Phase 4 — Casse-têtes partagés *(2–3 semaines)*

### Routes `device_token` + abonné

| Méthode | Route | Description |
| ------- | ----- | ----------- |
| POST | `/puzzle/shared` | Créer casse-tête partagé |
| GET | `/puzzle/shared` | Liste des partagés actifs |
| GET | `/puzzle/shared/{shared_uid}/state` | État complet (à l'ouverture) |
| POST | `/puzzle/shared/{shared_uid}/move` | Envoyer un mouvement de pièce |
| GET | `/puzzle/shared/{shared_uid}/events` | Polling : événements du partenaire |
| POST | `/puzzle/shared/{shared_uid}/leave` | Quitter (archive pour les deux) |
| DELETE | `/puzzle/shared/{shared_uid}` | Supprimer (créateur uniquement) |

### Fichiers à créer

1. **`src/puzzle/Controllers/SharedController.php`** — toutes les actions partagées
2. **`src/puzzle/Services/SharedPuzzleService.php`** — logique seed, completion,
   purge événements
3. **`src/puzzle/Models/SharedPuzzle.php`**

### Logique métier clé

- **Seed** : si `initial_pieces` absent → `seed = rand(100000, 999999)` ; insérer autant
  de lignes dans `puzzle_shared_pieces` (positions générées à partir du seed) ; sinon
  `seed = null` et utiliser `initial_pieces` directement
- **`completion`** : `ROUND(100 * COUNT(locked=1) / piece_count)` recalculé à chaque move
- **`POST /move`** : `INSERT INTO puzzle_shared_events` + `UPDATE puzzle_shared_pieces`
  en transaction ; retourne `event_id` et `completion`
- **`GET /events?after={last_event_id}`** : `WHERE shared_id = ? AND id > ? AND device_id != ?`
  (exclut les événements de l'appelant) ; retourne `partner_active = 1` si dernière
  requête du partenaire < `PUZZLE_POLL_ACTIVE_WINDOW_SECONDS` secondes
- **Purge** : cron ou hook — `DELETE FROM puzzle_shared_events WHERE created_at < NOW() - INTERVAL PUZZLE_EVENT_RETENTION_HOURS HOUR`
- **`POST /leave`** : `UPDATE puzzle_shared SET status = 'archived'` ; les deux
  participants perdent accès à ce partagé
- **`DELETE`** : réservé au `creator_id` ; `DELETE FROM puzzle_shared` (cascade)
- **Validation `POST /shared`** : vérifie `image_uid` dans `puzzle_images` WHERE
  `status = 'active'` ; vérifie `partner_pseudonym` dans `puzzle_devices` WHERE
  `pseudonym = ?` AND `is_premium = 1`

---

## Schéma `plugin.json`

```json
{
  "name": "Puzzle",
  "version": "1.0.0",
  "description": "Plugin puzzle : carrousel, thèmes, sauvegarde en ligne, casse-têtes partagés",
  "author": "CMEM Team",
  "namespace": "Puzzle",
  "main_class": "Puzzle\\PuzzlePlugin",
  "min_cmem_version": "1.3.0",
  "status": "active",
  "dependencies": {
    "cmem2_core": ">=1.3.0"
  },
  "routes": {
    "prefix": "/puzzle"
  },
  "route_handlers": {
    "puzzle": "Puzzle\\Routing\\PuzzleRouteHandler"
  },
  "database": {
    "tables": [
      "puzzle_devices",
      "puzzle_images",
      "puzzle_image_translations",
      "puzzle_themes",
      "puzzle_theme_translations",
      "puzzle_image_themes",
      "puzzle_shared",
      "puzzle_shared_pieces",
      "puzzle_shared_events"
    ],
    "migrations_path": "puzzle/migrations/"
  }
}
```

---

## Codes d'erreur métier

| Code | Phase | Signification |
| ---- | ----- | ------------- |
| `DEVICE_NOT_FOUND` | 1 | Token d'appareil inconnu ou expiré |
| `SUBSCRIPTION_REQUIRED` | 1 | Endpoint accessible uniquement aux abonnés |
| `SUBSCRIPTION_INVALID` | 1 | Reçu Google Play invalide ou abonnement expiré |
| `PSEUDONYM_TAKEN` | 1 | Pseudonyme déjà utilisé par un autre appareil |
| `NO_REPLACEMENT_AVAILABLE` | 2 | Aucune image de remplacement disponible |
| `ALREADY_REPLACED_TODAY` | 2 | Un remplacement a déjà eu lieu aujourd'hui (gratuit) |
| `THEME_NOT_FOUND` | 2 | Slug de thème inexistant |
| `PARTNER_NOT_FOUND` | 4 | Pseudonyme partenaire introuvable ou non abonné |
| `SHARED_NOT_FOUND` | 4 | Casse-tête partagé introuvable ou archivé |
| `NOT_PARTICIPANT` | 4 | L'appareil n'est pas créateur ni partenaire |
| `NOT_CREATOR` | 4 | Action réservée au créateur du casse-tête partagé |

---

## Variables `.env.puzzle`

```env
PUZZLE_UPLOAD_DIR=uploads/puzzle/
PUZZLE_THUMB_WIDTH=200
PUZZLE_THUMB_HEIGHT=200
PUZZLE_IMAGE_MAX_PX=1920
PUZZLE_DEVICE_TOKEN_DAYS=365
PUZZLE_GOOGLE_PLAY_PACKAGE=com.journauxdebord.puzzle
PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON=/path/to/service-account.json
PUZZLE_POLL_ACTIVE_WINDOW_SECONDS=10
PUZZLE_EVENT_RETENTION_HOURS=24
```

---

## Conventions

- Toutes les réponses utilisent `Response::success()` / `Response::error()` de `cmem2_API`
- Toutes les requêtes authentifiées valident le `device_token` via `PuzzleAuthMiddleware`
- Les endpoints abonné vérifient `is_premium = 1` et `premium_expires_at > NOW()` via `PremiumMiddleware`
- L'identifiant exposé à l'app est toujours `uid` / `shared_uid` (UUID) — jamais l'auto-increment interne
- Aucune donnée personnelle collectée — pas de nom, email, ni lien à un compte réel
