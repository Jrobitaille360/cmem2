# cmem2 — Plan de travail complet

> Phase 0 : Couche auth_groups · Phases 1–5 : Plugin ICS / iCal complet
> Document de travail Claude Code · Mars 2026 · cmem2 v2.0

---

## Vue d'ensemble des phases

| Phase   | Secteur          | Objectif principal                                            | Items | Priorité  | Effort  |
|---------|------------------|---------------------------------------------------------------|-------|-----------|---------|
| Phase 0 | auth_groups      | Sécurité (JWT, rate limit), qualité code, validation, router  | 18    | CRITIQUE  | ~11h    |
| Phase 1 | ICS — Base       | Intégrer sabre/vobject, UID RFC, TZID, line folding           | 4     | HAUTE     | ~4h     |
| Phase 2 | ICS — VEVENT     | Propriétés manquantes : CATEGORIES, PRIORITY, CLASS, TRANSP…  | 6     | HAUTE     | ~5h15   |
| Phase 3 | ICS — Attendee   | ATTENDEE complet, ORGANIZER, iTIP, email .ics                 | 4     | MOYENNE   | ~6h     |
| Phase 4 | ICS — Récurrence | EXDATE, RDATE, RELATED-TO, VALARM, DURATION                   | 5     | MOYENNE   | ~6h45   |
| Phase 5 | ICS — Composants | VTODO, VJOURNAL, VFREEBUSY                                    | 3     | BASSE     | ~9h     |

> **Ordre impératif :** Ph0 → Ph1 → Ph2–5 (Ph2–5 peuvent s'exécuter indépendamment après Ph1)
>
> **Effort total estimé :** 40–50 heures

---

## Phase 0 — Fondations auth_groups `[CRITIQUE]`

> **Objectif :** Corriger les failles de sécurité et la qualité du code avant d'attaquer le plugin ICS.
> Cette phase ne dépend d'aucune autre — elle peut démarrer immédiatement.

### Ordre d'exécution recommandé dans Phase 0

```text
A1 → A2 → A3 → A4  (sécurité en premier, impact direct sur les failles actives)
C1 → C2             (validation — bugs silencieux qui contaminent tout)
C3                  (réponses — fix rapide)
B1                  (BaseModel — impactant, à isoler dans son propre commit)
B2 → B3 → B4        (modèles — refactorisations sûres après B1)
C4                  (pagination — nouveau feature, après les fixes)
E1 → E2             (UX — nouveaux endpoints)
D1 → D2 → D3        (router — améliorations architecturales)
D4                  (pipeline middleware — grosse refactorisation, EN DERNIER)
```

---

### A. Sécurité

- ✅ **A1** — Blacklist JWT avec claim `jti` (UUID v4)
  - Ajouter table `jwt_blacklist` ; invalider le token au logout
  - Actuellement : logout inefficace, token valide 15 jours après déconnexion
  - Retourner `401` sur token blacklisté dans le middleware JWT
  - > ⚠️ **Faille active.** Priorité absolue — à faire en tout premier.

- ✅ **A2** — Rate limiting sur `/auth/login` et `/auth/send-code`
  - 5 échecs / 10 min par couple email+IP → retour `429 Too Many Requests`
  - > 💡 Vérifier d'abord si Apache/Nginx peut gérer le rate limiting côté serveur (ex. `mod_ratelimit`, `limit_req` Nginx) avant d'implémenter en PHP pur. Un middleware centralisé PHP est acceptable si le serveur ne supporte pas cette config.

- ✅ **A3** — Rotation du device token à chaque refresh réussi
  - Ancien token invalidé dès qu'un nouveau est émis
  - > Dépend de A1 (mécanisme de blacklist). À faire après A1.

- ✅ **A4** — Corriger CORS
  - Ajouter `PATCH`, `HEAD`, `X-API-Key` dans les allowed headers
  - > Fix rapide, faible risque. Nécessaire pour les clients qui utilisent PATCH (ex. mises à jour partielles d'événements).

---

### C. Validation & Réponses *(avant les modèles — bugs silencieux)*

- ✅ **C1** — Vérifier reset de `self::$errors` en début de `validate()` dans `Validator`
  - Contamination entre appels successifs si le Validator est réutilisé dans la même requête
  - > 🐛 Bug insidieux difficile à reproduire en isolation mais catastrophique en production multi-validations.

- ✅ **C2** — Corriger règle `required` dans `Validator`
  - Remplacer `empty()` par `isset($value) && $value !== ''`
  - `empty()` rejette `0` et `false`, ce qui est incorrect pour des champs numériques
  - > 🐛 Bug silencieux. Toute validation de champ numérique `0` échoue actuellement.

- ✅ **C3** — Corriger appel `Response::error(array, 429)` dans `ApiKeyAuthMiddleware`
  - Le premier paramètre doit être une `string`, pas un `array`
  - > Fix rapide, une ligne.

- ✅ **C4** — Ajouter `countFiltered()` et structure de pagination enrichie
  - Réponse paginée : `{ total, page, per_page, total_pages, data: [...] }`
  - > Nouveau feature — à faire après les fixes critiques. Nécessite modification des contrôleurs concernés.

---

### B. Modèles

- ✅ **B1** — Remplacer `static $db` par propriété d'instance dans `BaseModel`
  - Évite les conflits en contexte multi-modèles (ex. deux modèles différents dans la même requête)
  - > ⚠️ Changement impactant — **tous les modèles héritant de `BaseModel` doivent être testés après ce changement.** Isoler dans son propre commit avec test de régression.

- ✅ **B2** — Refactoriser `User::findById()` et `findByEmail()`
  - Utiliser `mapFromArray()` pour éliminer la duplication de mapping
  - > Safe à faire après B1. Réduction de dette technique.

- ✅ **B3** — Fusionner `Group::create()` et `create2()`
  - Transaction unique + paramètre `$addOwnerAsMember` (bool)
  - > Simplifie la logique métier et élimine le risque d'incohérence entre les deux méthodes.

- ✅ **B4** — Retirer `htmlspecialchars` des modèles
  - Les prepared statements PDO suffisent pour l'injection SQL
  - `htmlspecialchars` en modèle corrompt les données stockées (ex. `&amp;` au lieu de `&`)
  - > 🐛 Potentiellement source de données corrompues en DB. Safe à retirer.

---

### E. UX / Opérations

- ✅ **E1** — Créer endpoint `GET /auth/me`
  - JWT requis ; retourne profil à jour : `{ id, name, email, role, last_login }`
  - > Feature simple et à haute valeur — utile immédiatement pour tous les clients front-end.

- ✅ **E2** — Nettoyage OTP automatique
  - Option 1 : cleanup opportuniste 1% des requêtes (dans le middleware)
  - Option 2 : script cron `src/cron/cleanup.php`
  - > La structure cron existe déjà dans le projet. Privilégier le script cron pour un comportement prévisible.

---

### D. Router & Architecture

- ✅ **D1** — Passer les handlers à des factory closures (lazy-load)
  - Actuellement tous les handlers sont instanciés au boot, même ceux non utilisés
  - > Amélioration de performance au démarrage, surtout quand les routes augmentent.

- ✅ **D2** — Externaliser `BASE_PATH` dans `environment.php`
  - Remplacer le hardcodé `'/cmem2_API'` par une variable d'environnement
  - > Quick win — facilite le déploiement sur différents chemins de base.

- ✅ **D3** — Supprimer le fallback `$GLOBALS['pending_route_handlers']`
  - Mécanisme fragile et non maintenable
  - > À faire après D1 pour s'assurer que le lazy-load remplace correctement ce fallback.

- ✅ **D4** — Ajouter pipeline middleware dans `BaseRouteHandler::runMiddleware()`
  - Auth, logging, CORS appelés manuellement actuellement → centraliser dans un pipeline
  - > ⚠️ **Grosse refactorisation architecturale — garder pour DERNIER dans Phase 0.**
  > Tester exhaustivement toutes les routes après ce changement. Un pipeline mal câblé peut silencieusement bypasser l'auth sur certaines routes.

---

## Phase 1 — Fondations iCal `[HAUTE]` *(prérequis Ph2–5)*

> **Objectif :** Remplacer les parseurs iCal manuels par `sabre/vobject`.
>
> **Prérequis :** `composer require sabre/vobject` (la lib est déjà référencée dans `TimezoneHelper.php`).

### Ordre d'exécution recommandé dans Phase 1

```text
1.1 → 1.3 → 1.4 → 1.2
```

> 1.1 en premier car les autres dépendent des wrappers sabre.

- ✅ **1.1** — Intégrer `sabre/vobject`
  - Créer wrappers `IcsParser` et `IcsGenerator` remplaçant les 3 parseurs manuels
    - `Calendar.php`, `CalendarEvent.php`, `CalDAVServer.php`
  - > 🔑 **Item fondateur de toutes les phases ICS suivantes.** Les parseurs manuels sont la principale source d'erreurs RFC. sabre/vobject est la référence de l'écosystème PHP CalDAV.

- ✅ **1.3** — UID stable RFC-conforme
  - Générer UUID v4 à la création d'un événement (stocker dans colonne `uid` existante)
  - Actuellement : `'event-123@cmem'` — prédictible, non-conforme RFC 5545 §3.8.4.7
  - > À faire juste après 1.1. UIDs stables = synchronisation CalDAV fiable.

- ✅ **1.4** — `DTSTART` avec paramètre `TZID`
  - Émettre `DTSTART;TZID=America/Montreal:20260401T140000` quand la timezone est connue
  - Actuellement : toujours exporté en UTC `Z`, ce qui cause des décalages pour les clients
  - > Impact direct sur l'expérience utilisateur pour tous les événements localisés.

- ✅ **1.2** — Line folding RFC 5545 §3.1
  - `sabre/vobject` gère le folding automatiquement ; vérifier que l'export respecte la limite 75 octets/ligne
  - > Simple vérification après 1.1 — sabre s'en charge, mais valider avec un vrai fichier .ics importé dans Google Calendar / Apple Calendar.

---

## Phase 2 — Propriétés VEVENT manquantes `[HAUTE]`

> **Prérequis :** Phase 1 complétée.
>
> **Migrations SQL requises** pour chaque item ajoutant des colonnes → `src/ics/docs_ICS/migrations/`

- ✅ **2.1** — `CATEGORIES`
  - Mapper les tags système auth_groups → export `CATEGORIES:Travail,Personnel`
  - Import : parser CSV de catégories
  - > Bonne cohérence avec le système de groupes existant.

- ✅ **2.2** — `PRIORITY`
  - Colonne `priority TINYINT(1) DEFAULT 0`
  - Valeurs RFC 5545 : 0=non défini, 1=haute, 5=normale, 9=basse
  - > Simple à ajouter, très utile pour les clients de type gestionnaire de tâches.

- ✅ **2.3** — `CLASS`
  - `ENUM('PUBLIC','PRIVATE','CONFIDENTIAL')`
  - Contrôle de confidentialité en contexte entreprise / CalDAV partagé
  - > Nécessaire pour les calendriers d'équipe.

- ✅ **2.4** — `TRANSP`
  - `ENUM('OPAQUE','TRANSPARENT')` — contrôle si l'événement bloque le temps libre
  - Impact direct sur la disponibilité affichée dans les clients CalDAV (Outlook, Thunderbird)

- ✅ **2.5** — `URL` et `GEO`
  - Exporter `meeting_link` existant comme `URL:https://...`
  - Ajouter colonnes `geo_lat DECIMAL(10,7)`, `geo_lng DECIMAL(10,7)`
  - > `meeting_link` est déjà en DB — export URL = migration minime.

- ✅ **2.6** — `ATTACH`
  - Colonne `attachments JSON`
  - Export `ATTACH:https://...` ou `ATTACH;ENCODING=BASE64:...`
  - > À faire en dernier dans Ph2 — la gestion BASE64 peut alourdir les .ics.

---

## Phase 3 — ATTENDEE & ORGANIZER complets `[MOYENNE]`

> **Prérequis :** Phase 1 complétée.

- [ ] **3.1** — `ATTENDEE` complet
  - Étendre structure JSON existante : `[{ email, name, role, partstat, rsvp, cutype }]`
  - Export : `ATTENDEE;CN=Jean Tremblay;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:jean@ex.com`
  - Import avec sabre/vobject

- [ ] **3.2** — `ORGANIZER`
  - Déduire du `user_id` de l'événement
  - Export : `ORGANIZER;CN=Nom:mailto:email@ex.com`
  - Optionnel : colonne `organizer_email` si override nécessaire

- [ ] **3.3** — iTIP de base
  - `METHOD:REQUEST` à l'export d'invitation
  - Traiter `METHOD:REPLY` à l'import (mettre à jour `PARTSTAT`)
  - `METHOD:CANCEL` pour annulations
  - > Standard pour l'interopérabilité avec Outlook, Google Calendar, Apple Calendar.

- [ ] **3.4** — Notification email d'invitation avec pièce jointe `.ics`
  - Inclure `METHOD:REQUEST` dans le `.ics` joint
  - Compatible Outlook, Gmail, Apple Mail
  - > ⚠️ Dépend de l'endpoint `/notifications/send-email` déjà implémenté.
  > La gestion `multipart/mixed` avec pièce jointe `.ics` requiert un test sérieux avec Outlook (client le plus strict). Tester aussi le parsing de la réponse REPLY retournée.

---

## Phase 4 — Récurrence avancée & VALARM `[MOYENNE]`

> **Note :** RRULE (simshaun/recurr) est déjà implémenté. Cette phase ajoute les fonctionnalités complémentaires.
>
> **Nouvelles colonnes DB :** `exdate TEXT`, `rdate TEXT`, `related_to VARCHAR(255)`, `duration VARCHAR(20)`

- [ ] **4.1** — `EXDATE` — exceptions de récurrence
  - Source : `event_occurrences` avec `is_cancelled = 1`
  - Export : `EXDATE;TZID=America/Montreal:20260401T140000,20260408T140000`
  - Import : créer occurrences annulées correspondantes

- [ ] **4.2** — `RDATE` — dates additionnelles
  - Colonne `rdate TEXT` (dates ISO séparées par virgule)
  - Export/import ; générer `event_occurrences` correspondantes

- [ ] **4.3** — `RELATED-TO`
  - Colonne `related_to VARCHAR(255)` (UID parent)
  - Export : `RELATED-TO;RELTYPE=PARENT:<uid>`
  - Import : stocker UID brut si résolution locale échoue

- [ ] **4.4** — `VALARM`
  - Convertir `notifications JSON` existant `[{ type, minutes_before }]` en blocs `BEGIN:VALARM`
  - Export : `ACTION:DISPLAY` / `ACTION:EMAIL`, `TRIGGER:-PT30M`, `DESCRIPTION:Rappel`
  - > La structure notifications est déjà en DB — c'est principalement un travail d'export.

- [ ] **4.5** — `DURATION` vs `DTEND`
  - Colonne `duration VARCHAR(20)` format ISO 8601 (ex. `PT1H30M`)
  - Si `duration` défini → export `DURATION:PT1H30M` (sans `DTEND`)
  - Import : calculer `end_datetime` depuis `DTSTART + DURATION`

---

## Phase 5 — Composants CalDAV additionnels `[BASSE — optionnel]`

> **Objectif :** Support des composants iCal non-VEVENT pour interopérabilité CalDAV complète.
>
> À planifier selon les besoins réels des utilisateurs.

- [ ] **5.1** — `VTODO`
  - Nouvelle table `calendar_todos (calendar_id, title, status, due, priority, percent_complete, description)`
  - Modèle + contrôleur + routes CRUD
  - Ajouter `VTODO` au `supported-component-set` dans `CalDAVServer`
  - > Haute valeur pour les utilisateurs qui gèrent des tâches via leur client CalDAV (Thunderbird, Apple Reminders).

- [ ] **5.2** — `VJOURNAL`
  - Table `calendar_journals (calendar_id, summary, description, dtstart)`
  - Modèle + routes basiques
  - > Utilisé par Emacs org-mode, Evolution. Usage de niche — faire en dernier.

- [ ] **5.3** — `VFREEBUSY`
  - Endpoint `GET /calendars/{id}/freebusy?start=...&end=...`
  - Agréger les événements `TRANSP=OPAQUE` → générer `VFREEBUSY` avec plages occupées
  - Exposer via `REPORT` CalDAV
  - > Nécessite 2.4 (TRANSP) complété. Utile pour la planification de réunions.

---

## Checklist maître — 40 items

> Tableau de suivi global. Cocher ici une fois l'item terminé et testé.

| #  | Phase | ID  | Description courte                         | Effort | Fait |
|----|-------|-----|--------------------------------------------|--------|------|
| 1  | Ph0   | A1  | Blacklist JWT (jti + table)                | 1h30   | ✅   |
| 2  | Ph0   | A2  | Rate limiting login / send-code            | 1h     | ✅   |
| 3  | Ph0   | A3  | Rotation device token au refresh           | 45min  | ✅   |
| 4  | Ph0   | A4  | Fix CORS (PATCH, HEAD, X-API-Key)          | 20min  | ✅   |
| 5  | Ph0   | C1  | Reset `$errors` dans Validator             | 15min  | ✅   |
| 6  | Ph0   | C2  | Fix règle `required` (empty → isset)       | 15min  | ✅   |
| 7  | Ph0   | C3  | Fix `Response::error(array, 429)`          | 10min  | ✅   |
| 8  | Ph0   | B1  | `static $db` → instance dans BaseModel     | 1h     | ✅   |
| 9  | Ph0   | B2  | Refactor `User::findById/findByEmail`      | 30min  | ✅   |
| 10 | Ph0   | B3  | Fusionner `Group::create()` + `create2()`  | 45min  | ✅   |
| 11 | Ph0   | B4  | Retirer `htmlspecialchars` des modèles     | 20min  | ✅   |
| 12 | Ph0   | C4  | `countFiltered()` + pagination enrichie    | 1h     | ✅   |
| 13 | Ph0   | E1  | Endpoint `GET /auth/me`                    | 45min  | ✅   |
| 14 | Ph0   | E2  | Cron nettoyage OTP                         | 30min  | ✅   |
| 15 | Ph0   | D1  | Lazy-load handlers (factory closures)      | 45min  | ✅   |
| 16 | Ph0   | D2  | Externaliser `BASE_PATH`                   | 20min  | ✅   |
| 17 | Ph0   | D3  | Supprimer fallback `$GLOBALS`              | 15min  | ✅   |
| 18 | Ph0   | D4  | Pipeline middleware dans `runMiddleware()` | 1h30   | ✅   |
| 19 | Ph1   | 1.1 | Intégrer sabre/vobject (wrappers)          | 2h     | ✅   |
| 20 | Ph1   | 1.3 | UID stable UUID v4                         | 45min  | ✅   |
| 21 | Ph1   | 1.4 | DTSTART;TZID=...                           | 45min  | ✅   |
| 22 | Ph1   | 1.2 | Line folding RFC 5545                      | 30min  | ✅   |
| 23 | Ph2   | 2.1 | CATEGORIES                                 | 1h     | ✅   |
| 24 | Ph2   | 2.2 | PRIORITY                                   | 45min  | ✅   |
| 25 | Ph2   | 2.3 | CLASS                                      | 30min  | ✅   |
| 26 | Ph2   | 2.4 | TRANSP                                     | 30min  | ✅   |
| 27 | Ph2   | 2.5 | URL + GEO                                  | 45min  | ✅   |
| 28 | Ph2   | 2.6 | ATTACH                                     | 1h     | ✅   |
| 29 | Ph3   | 3.1 | ATTENDEE complet                           | 1h30   |      |
| 30 | Ph3   | 3.2 | ORGANIZER                                  | 45min  |      |
| 31 | Ph3   | 3.3 | iTIP de base (REQUEST/REPLY/CANCEL)        | 1h30   |      |
| 32 | Ph3   | 3.4 | Email invitation + pièce jointe .ics       | 1h30   |      |
| 33 | Ph4   | 4.1 | EXDATE                                     | 1h     |      |
| 34 | Ph4   | 4.2 | RDATE                                      | 1h     |      |
| 35 | Ph4   | 4.3 | RELATED-TO                                 | 45min  |      |
| 36 | Ph4   | 4.4 | VALARM export                              | 1h30   |      |
| 37 | Ph4   | 4.5 | DURATION vs DTEND                          | 1h     |      |
| 38 | Ph5   | 5.1 | VTODO                                      | 3h     |      |
| 39 | Ph5   | 5.2 | VJOURNAL                                   | 2h     |      |
| 40 | Ph5   | 5.3 | VFREEBUSY                                  | 3h     |      |

---

## Notes de travail

### Convention pour soumettre un item à Claude Code

```text
Item : <ID> — <titre>
Fichiers concernés : <liste>
Problème : <description>
Solution attendue : <description>
```

### Migrations SQL

Toute colonne ajoutée → créer le fichier migration dans `src/ics/docs_ICS/migrations/`

Nommage : `YYYYMMDD_<id_item>_<description>.sql`

### Dépendances composer à ajouter

- `sabre/vobject` — Phase 1 (1.1)

### Branches git recommandées

- `ph0-security` — A1, A2, A3, A4
- `ph0-models` — B1, B2, B3, B4
- `ph0-validation` — C1, C2, C3, C4
- `ph0-router` — D1, D2, D3, D4
- `ph0-ux` — E1, E2
- `ph1-sabre` — 1.1 → 1.2 → 1.3 → 1.4
- `ph2-vevent-props` — 2.x
- `ph3-attendee` — 3.x
- `ph4-recurrence` — 4.x
- `ph5-components` — 5.x
