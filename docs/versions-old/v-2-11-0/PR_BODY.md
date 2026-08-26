# Release v2.11.0 — cmem2 API

## Résumé

Prépare la release `v2.11.0` de **cmem2 API**. Cette PR fige l'état du dépôt pour produire
les artefacts de publication et poursuivre le développement ensuite.

Version centrée sur deux axes : les **notifications push web (VAPID)** avec préférences par
compte et cron d'envoi idempotent, et la fin du **pilier Contacts / CRM** (interactions,
pipeline d'opportunités, GED par liens croisés, relance de contact). S'y ajoutent le fuseau
horaire du compte et un correctif de `LOG_DIR`.

Aucun BREAKING CHANGE.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.11.0] — 2026-07-26`.

### Ajouté

- **Push web (VAPID)** — module `src/push/`, cinq routes `/push/*`, préférences par compte
  (`event`, `task_due`, `recurring`, `contact_followup`), plage « ne pas déranger »,
  opt-in par défaut, cron d'envoi idempotent et purge des endpoints morts.
- **`users.timezone`** — fuseau IANA du compte, prioritaire sur celui du calendrier, repli
  `America/Montreal`.
- **Relance de contact** — `date_relance` / `motif_relance` / `relance_faite_le` sur la fiche ;
  2e source du rappel `contact_followup`, sans nouveau `kind`.
- **CRM pipeline** — table `opportunite`, board Kanban `GET /opportunites`, CRUD par fiche.
- **CRM interactions** — historique unifié `GET/POST/DELETE /contacts/{id}/interactions`.
- **Envoi de courriel depuis une fiche** — `POST /contacts/{id}/messages`, `Reply-To` usager,
  journalisation, rate-limit.
- **GED** — `/links` accepte `file`, `contact`, `interaction`, `opportunite` ; `other_title`
  résolu côté serveur ; cascade de purge.

### Corrigé

- `LOG_DIR` absolu était concaténé à la racine du projet : les journaux applicatifs
  partaient dans un dossier imbriqué depuis le 22 juin 2026, sans erreur remontée.
  `LogService::writeToFile()` signale désormais un `fopen` refusé via `error_log()`.

## Tests

Suite complète exécutée sur `dev-cmem` : **2068 / 2068 verts, 0 échec** (100 %).

Suites notables de cette release :

| Suite | Résultat |
| - | - |
| `test_push.php` | 98 / 98 |
| `test_contacts.php` | 137 / 137 |
| `test_contacts_opportunites.php` | 65 / 65 |
| `test_links_ged.php` | 54 / 54 |
| `test_contacts_interactions.php` | 46 / 46 |
| `test_contacts_messages.php` | 43 / 43 |
| `test_user_timezone.php` | 38 / 38 |

## Références

- Plans : `docs/v-2-11-0/PLAN_relance-contact.md`, `docs/v-2-11-0/PLAN_timezone-usager.md`
- Directives inter-projets traitées :
  - `20260724_090048_cmem_web_vers_cmem2_API__contacts-email-envoi.md`
  - `20260724_143353_cmem_web_vers_cmem2_API__crm-interactions.md`
  - `20260724_154618_cmem_web_vers_cmem2_API__crm-pipeline.md`
  - `20260724_154619_cmem_web_vers_cmem2_API__ged-liens-fichiers.md`
  - `20260726_140426_cmem_web_vers_cmem2_API__web-push.md`
  - `20260726_161400_cmem2_API_vers_cmem_web__relance-contact-et-timezone-usager.md`

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example` → `APP_VERSION=2.11.0`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-11-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-11-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-11-0/`
- [x] Migrations SQL pendantes déplacées et intégrées à `build_DB-v-2.11.0.sql`
- [x] `README.md` — badge de version et pied de page
- [ ] CI status checks green
- [ ] Reviewer assigné

## Checklist API PHP

- [ ] `vendor/` régénéré localement avec `minishlink/web-push ^9` puis déployé
      (**pas de `composer install` sur le serveur**)
- [ ] Sept migrations SQL appliquées en prod, dans l'ordre de
      `docs/v-2-11-0/2.11.0_PRODUCTION.md`
- [ ] Clés VAPID générées sur la cible (`php src/push/generate_vapid.php`) et posées en `.env`
- [ ] Cron d'envoi push ajouté (`*/5 * * * *`)
- [ ] `LOG_DIR=logs/` vérifié sur prod et dev
- [ ] Vérifications post-déploiement de `docs/v-2-11-0/2.11.0_PRODUCTION.md` cochées

## Checklist journauxdebord.com

- [ ] Fiche cmem mise à jour (`version`, `features`) — `PUT /items/{id}`
- [ ] Page publique vérifiée

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.11.0 -m "Release v2.11.0"` puis `git push origin v2.11.0`.
- Le tag `v2.10.0` manquait ; il a été posé rétroactivement sur `d9dddc7`.
- Pour un **hotfix** : utiliser `git cherry-pick` vers `main` plutôt qu'un merge direct
  (voir `C:\code\PRIVATE-DATA\ancrage_versions\version_anchor.md` §4).
