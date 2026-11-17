# Release v2.24.2 — cmem2_API

## Résumé

Prépare la release correctif `v2.24.2` de **cmem2_API** : directive #521 de cmem_web
(historique de l'assistant avec journal d'actions, événement SSE `conversation` immédiat),
et calendriers cachés de projet exclus du quota `max_calendars` et du calendrier par défaut
de l'assistant.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- `AssistantChatService::historyMessages()` : chaque tour passé porte le journal de ses
  actions (id, outil, statut actuel, tâche) ; prompt système : le journal fait foi.
- SSE : `conversation` émis dès la réservation budget acceptée, avant l'appel modèle ;
  échec modèle en SSE → `error` + `done` (`502` réservé au mode JSON).
- `Calendar::countOwnedByUserId()` : calendriers de projet exclus du quota.
- `AssistantTools::defaultCalendarId()` : calendriers de projet exclus.

Détails : `CHANGELOG.md`, section `## [2.24.2]`.

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.24.2` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-24-2/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-24-2/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md`
- [x] Tests : `test_assistant.php` 101/101, `test_resource_caps.php` section 6 ; suite
      complète 3342/3342 sur dev

## Checklist API PHP

- [x] Aucune migration
- [ ] `composer install --no-dev` exécuté sur le serveur (`deploy.ps1`)
- [ ] Endpoint `/health` répond `version: 2.24.2`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

## Références

- Directive #521 (cmem_web → cmem2_API), retour #523.

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)
