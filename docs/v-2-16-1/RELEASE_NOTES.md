# RELEASE NOTES — cmem2_API v2.16.1

## Description courte

Couverture complète de la suite de tests (11 fichiers qui n'avaient jamais tourné dans
le runner) et documentation d'une limite connue sur le versioning optimiste des
calendriers/projets. Regroupe aussi le contenu non tagué de `v2.16.0` (module
`booking`, plan équipe, versioning optimiste), déjà en production.

## Formats publiés

- [x] API

## Changements principaux

> Détails complets : voir `CHANGELOG.md`.

### Ajouté

- Nouveau module `booking` — réservation publique par lien (v2.16.0)
- Plan équipe — facturation Stripe portée par un groupe + modules de groupe (v2.16.0)
- Versioning optimiste (`updatedAt` + `If-Unmodified-Since`) sur events/todos/journals/tasks (v2.16.0)
- `docs/PLAN_concurrence-updated-at-microsecondes.md` — analyse et décision documentée
  sur la limite de résolution seconde de la garde de versioning optimiste (v2.16.1)

### Modifié

- `private/tests/run_all_tests.php` exécute désormais les 63 suites de tests existantes
  (11 n'avaient jamais été exécutées par le runner) (v2.16.1)
- `docs/ics/API_ICS_ENDPOINTS.json` et `docs/projets/API_PROJETS_ENDPOINTS.json` —
  documentent la limite connue sur `If-Unmodified-Since` (v2.16.1)

### Corrigé

- `STRIPE_PRICE_CMEMWEB_TEAM` jamais défini côté PHP (v2.16.0)
- `test_stripe_webhooks.php` — URL legacy, vérification SSL, table SQL obsolète (v2.16.1)
- Fixture de test partagée `support@journauxdebord.com` non vérifiée sur dev (v2.16.1)

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun artefact binaire — API PHP déployée directement sur le serveur.

## Instructions de déploiement rapides

```bash
# API — déjà déployée (2.16.0 en production ; 2.16.1 est documentation/tests, rien à déployer)

# Tag Git
git tag -a v2.16.1 -m "Release v2.16.1"
git push origin v2.16.1

# GitHub Release (sans artefacts joints)
gh release create v2.16.1 \
  --title "v2.16.1" \
  --notes-file docs/v-2-16-1/RELEASE_NOTES.md \
  --draft
```
