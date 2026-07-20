# Release v2.9.0 — cmem2 API

## Résumé

Prépare la release `v2.9.0` de **cmem2 API**. Cette PR fige l'état du dépôt pour
produire les artefacts de publication et poursuivre le développement ensuite.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.9.0] — 2026-07-19`.

- **Feat** — partage de calendrier avec un groupe (`POST/GET/DELETE /calendars/{id}/share` avec `group_id`)
- **Feat** — étiquettes (tags) scopées par calendrier, partagées, cascade
- **Feat** — corbeille récupérable événements/tâches/journaux + restore
- **Feat** — expansion d'occurrences RRULE à la demande (endpoint additif)
- **Feat** — rôle `SUPERADMINISTRATEUR`, matrice d'autorité admin/superadmin
- **Feat** — `GET /users?include_deleted=1`, `PUT /users/{id}/plan-override`
- **Feat** — `GET /entrypoints` et `GET /entrypoints/{module}` — documentation d'endpoints publique
- **Feat** — `show_question_to_player` (quiz)
- **Feat** — enforcement caps cmem, cron purge RGPD, plan effectif `/auth/me`
- **Fix** — metadata Stripe manquant au niveau session checkout (`stripe_subscriptions` jamais créée)
- **Fix** — `DELETE /users/me` bloqué pour comptes OTP
- **Fix** — UID/DTSTAMP dupliqués export ICS

---

## Checklist commune

- [x] Version mise à jour dans le fichier approprié (`.env`, `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-9-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-9-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-9-0/`
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur
- [ ] Migrations SQL appliquées (voir `docs/v-2-9-0/2.9.0_PRODUCTION.md` — statut détaillé par fichier ; **`20260716_add_superadmin_role.sql` NON appliqué, STOP confirmation requise**)
- [ ] Caches vidés / régénérés
- [ ] Endpoint `/help` répond correctement

## Checklist journauxdebord.com

- [ ] Fiche cmem2 mise à jour (`version`, `features`)
- [ ] Page publique vérifiée

---

## Tests

Suite complète : `php private/tests/run_all_tests.php` → **1556/1556 (100%)** sur dev-cmem2.

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.9.0 -m "Release v2.9.0"` puis `git push origin v2.9.0`.
