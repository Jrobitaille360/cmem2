# Release v2.13.0 — cmem2_API

## Résumé

Prépare la release `v2.13.0` de **cmem2_API**. Cette PR fige l'état du dépôt pour
produire les artefacts de publication et poursuivre le développement ensuite.

Contenu : durcissement de la validation d'upload du module `files` (deux failles corrigées),
élargissement des types de fichiers acceptés, renommage de fichier, lecture des étiquettes,
et alignement du plafond d'upload.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.13.0] — 2026-08-03`.

### Sécurité

- **SVG servi `inline`** par `GET /files/{id}` : un SVG est du XML exécutable ; servi inline, son
  script s'exécutait sur l'origine de l'API. Désormais `Content-Disposition: attachment` +
  `X-Content-Type-Options: nosniff`. Les autres `image/*` gardent l'inline et le cache long
- **`mime_type` stocké = `Content-Type` déclaré par le client**, jamais vérifié. Provient
  maintenant de la signature réelle du fichier ; `media_type` en découle
- **L'extension seule pouvait faire passer un fichier** (un texte renommé `.png` était accepté).
  La validation croise désormais la paire (extension, signature)

### Ajouté

- Types acceptés : `pptx`, `odt`, `ods`, `odp`, `csv`, `md`, `rtf`, `heic`, `heif`, `avif`,
  `tiff`, `tif`
- `PATCH /files/{id}` — renommage (`original_name`) et `description`, sans toucher au stockage
- `GET /files/{id}/tags` + champ `tags[]` dans `GET /files/user/{user_id}` (une requête par page)
- Codes de refus applicatifs : `FILE_TYPE_REFUSED`, `FILE_TOO_LARGE`, `FILE_NAME_INVALID`
- `FILES_MAX_UPLOAD_MB` (défaut 100) ; `.htaccess` aligné sur `user.ini` (100M / 105M)

## Tests effectués

`php private/tests/run_all_tests.php` → **2302 / 2302**, 0 échec (dont
`test_files_types.php`, 57 tests neufs), exécutés contre `dev-cmem2.journauxdebord.com`.

## Références

- Directive `20260803_101500_cmem_web_vers_cmem2_API__upload-types-fichiers` — complétée
- Directive `20260728_153000_jdb_vers_cmem2_API__plafond-upload-installateurs` — complétée

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example` → `APP_VERSION=2.13.0`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-13-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-13-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-13-0/` — aucun plan associé à cette release
      (`docs/PLAN_refonte-v3.0.0.md` vise la v3.0.0 et reste en place)
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur
- [x] Migrations SQL appliquées — **aucune migration dans cette release** (schéma inchangé
      depuis la v2.12.0 ; utiliser `docs/v-2-12-0/build_DB-v-2.12.0.sql`)
- [ ] `.htaccess` déployé (indispensable : le plafond de 100 Mo en dépend)
- [ ] `FILES_MAX_UPLOAD_MB=100` présent dans le `.env` de production
- [ ] Endpoint `/health` répond correctement

## Checklist journauxdebord.com

- [ ] Fiche `cmem2` mise à jour (`version`, `features`)
- [ ] Page publique vérifiée

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.13.0 -m "Release v2.13.0"` puis `git push origin v2.13.0`.
- Clients à prévenir du changement de comportement `media_type` : voir
  `docs/v-2-13-0/2.13.0_CLIENT.md`.
