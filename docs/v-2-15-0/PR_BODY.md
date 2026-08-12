# Release v2.15.0 — cmem2_API

## Résumé

Prépare la release `v2.15.0` de **cmem2_API**. Cette PR fige l'état du dépôt pour produire les
artefacts de publication et poursuivre le développement ensuite.

Six directives inter-projets traitées : entête `Urgency` manquant sur le push web, effacement
explicite des champs texte du calendrier (deux directives `cmem_web`), code OTP fixe en
développement pour la suite Playwright de `jdb`, socle des rôles de jeu Traque, et proxy IA
(résumé d'agenda).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.15.0] — 2026-08-11`.

### Push web — entête `Urgency: high` pour livraison Android fiable

> Directive `20260810_121500_cmem_web_vers_cmem2_API__urgency-high-web-push` (complété)

- `WebPushService::sendToOwner()` passe `'urgency' => 'high'` à `minishlink/web-push` — l'absence
  omettait l'entête HTTP, traité `normal` par FCM, différé de minutes à heures sous Doze Android

### Calendrier — `null` efface, champ absent laisse inchangé

> Directive `20260729_174600_cmem_web_vers_cmem2_API__null-effacement-explicite-put-evenement` (complété)

- `PUT /calendars/{cid}/events/{eid}` distingue « absent » de « null » sur `location`,
  `description`, `color`, `recurrence_rule` ; plus aucun `422` sur `null`

### Calendrier — vider le lieu ou la description d'une seule occurrence

> Directive `20260729_174500_cmem_web_vers_cmem2_API__effacement-lieu-description-occurrence` (complété)

- Trois états sur `modified_location` / `modified_description` : `NULL` (hérite), `''` (effacé),
  texte (remplace) ; migration de données `20260804_occurrence_modified_empty_to_null.sql`

### `AUTH_TEST_CODE` — code OTP fixe en développement

> Directive `20260728_150000_jdb_vers_cmem2_API__code-otp-fixe-dev-tests` (en_cours)

- `POST /auth/send-code` accepte un code fixe hors production, garde-fou sur `APP_ENV`

### Traque — socle des rôles de jeu

> Directive `20260605_161757_traque_vers_cmem2_API__table-traque-roles-et-endpoints-admin-gm` (complété, partiel)

- Table `traque_roles`, helper `TraqueAuth`, trois endpoints admin, promotion `gm` automatique au
  niveau 15 ; endpoints de secteur MJ et CRUD de contenu hors périmètre (tables non définies)

### Proxy IA — `POST /ai/summarize`

> Directive `20260810_140000_cmem_web_vers_cmem2_API__ai-proxy` (complété)

- Résumé d'agenda gaté par module `ia`, quota décompté avant l'appel modèle, aucune `description`
  ni `notes` transmise au modèle, clé Anthropic serveur uniquement

## Tests effectués

`php private/tests/run_all_tests.php` — à confirmer avant merge (voir §Checklist).

Suites nouvelles depuis la v2.14.0 :

| Suite | Portée |
| - | - |
| `test_ics_null_erasure.php` | Effacement explicite `null` (événement + occurrence) |
| `test_auth_test_code.php` | `AUTH_TEST_CODE`, se saute si absent |
| `test_traque_roles.php` | Gating `401` / `403` des endpoints de rôles |
| `test_ai_summarize.php` | Gating, quota, validation ; happy path se saute sans `ANTHROPIC_API_KEY` |

## Références

- `docs/v-2-15-0/2.15.0_CLIENT.md` — changements visibles côté client
- `docs/v-2-15-0/2.15.0_PRODUCTION.md` — checklist de déploiement
- Directives : `20260810_121500`, `20260729_174600`, `20260729_174500`, `20260605_161757`,
  `20260810_140000` (`complété`) ; `20260728_150000` (`en_cours`)

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`, `README.md`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-15-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-15-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Migrations pendantes déplacées dans `docs/v-2-15-0/` et intégrées à `build_DB-v-2.15.0.sql`
- [ ] CI status checks green
- [ ] Reviewer assigné

## Checklist API PHP

- [ ] Suite de tests complète exécutée localement, 0 échec
- [ ] Migrations SQL appliquées (dev, puis prod après merge)
- [ ] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur
- [ ] Endpoint racine répond correctement
- [ ] Redéploiement dev + prod après merge du tag

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.15.0 -m "Release v2.15.0"` puis `git push origin v2.15.0`.
- La directive `AUTH_TEST_CODE` (`jdb`) reste `en_cours` — code livré, statut à faire passer
  `complété` par `jdb` après validation de sa suite Playwright.
- La directive Traque reste partiellement ouverte (secteur MJ, CRUD de contenu) — cinq tables
  restent à définir dans une prochaine release.
