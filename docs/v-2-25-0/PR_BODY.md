# Release v2.25.0 — cmem2_API

## Résumé

Prépare la release `v2.25.0` de **cmem2_API** : nouveau plugin `jesaisquand` (« Je sais
quand »), directives #524 (paquets et multijoueur au tour par tour) et #526 (édition admin
des cartes) du projet jesaisquand.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- `src/jesaisquand/` : paquets (`GET /jesaisquand/paquets[/{slug}]`), parties multijoueur
  invitées par jeton joueur (créer, rejoindre, démarrer, état `?depuis=` → `304`, coups),
  administration (import upsert, `PATCH` illustration + crédit journalisé, cartes sans crédit).
- Serveur faisant autorité : dates des cartes en main jamais exposées ; règle de pose portée
  de `jesaisquand/src/lib/partie.ts` (`Services/Regles.php`).
- Migration `20261118_jesaisquand.sql` : 6 tables `jsq_*`, intégrée dans
  `build_DB-v-2.25.0.sql`.

Détails : `CHANGELOG.md`, section `## [2.25.0]`.

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.25.0` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-25-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-25-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md`
- [x] Tests : `test_jesaisquand_regles.php` 23/23, `test_jesaisquand.php` 95/95 ; suite
      complète 3437/3437 sur dev

## Checklist API PHP

- [ ] Migration `docs/v-2-25-0/20261118_jesaisquand.sql` appliquée en prod (dev : fait)
- [ ] `composer install --no-dev` exécuté sur le serveur (`deploy.ps1`)
- [ ] Endpoint `/health` répond `version: 2.25.0`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

## Références

- Directives #524 et #526 (jesaisquand → cmem2_API), retour #527.

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)
