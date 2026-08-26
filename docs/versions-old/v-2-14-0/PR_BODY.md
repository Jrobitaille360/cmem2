# Release v2.14.0 — cmem2_API

## Résumé

Prépare la release `v2.14.0` de **cmem2_API**. Cette PR fige l'état du dépôt pour produire les
artefacts de publication et poursuivre le développement ensuite.

Version « chiffrement de bout en bout » : les journaux, les tâches et les contacts peuvent
désormais être chiffrés côté client, l'API n'en conservant que des octets opaques. Trois
directives `cmem_web` livrées. Aucune rupture de contrat — tout contenu non chiffré se comporte
exactement comme en v2.13.0.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.14.0] — 2026-08-04`.

### Chiffrement de bout en bout — métadonnées de clé

> Directive `20260803_205805_cmem_web_vers_cmem2_API__e2e-metadonnees-de-cle`

- Trois endpoints `GET` / `PUT` / `DELETE /users/me/e2e-key`, owner-strict, `app_id` toujours
  transmis par le client
- Le serveur stocke le sel PBKDF2, le nombre d'itérations, la clé maîtresse enveloppée deux fois
  et un vérificateur — jamais la passphrase, le code de secours ni la clé en clair
- Bornes explicites, dépassement en `400` sans écriture ; corps des `PUT` non journalisé
- Correctif de routage : `DELETE /users/me/e2e-key` retombait sur `DELETE /users/me` et supprimait
  le compte
- Migration `20260803_user_e2e_keys.sql` (table `user_e2e_keys`)

### Journaux — `enc_alg` / `enc_iv`

> Directive `20260803_165946_cmem_web_vers_cmem2_API__e2e-journaux-champs-chiffres`

- Champs acceptés en `POST` / `PUT` / `PATCH`, restitués par tous les `GET`
- `summary` et `description` traversent l'API sans aucune transformation
- `summary` → `VARCHAR(2000)`, `description` → `MEDIUMTEXT`
- `PATCH` accepté à parité avec `PUT`
- Migration `20260803_journaux_e2e.sql`

### Tâches et contacts

> Directive `20260804_090000_cmem_web_vers_cmem2_API__e2e-taches-contacts`

- Tâches : `enc_alg` / `enc_iv`, chiffrement de `title` et `description`, `title` →
  `VARCHAR(2000)`, `description` → `MEDIUMTEXT`, route `PATCH` ajoutée
- Contacts : `enc_alg` / `enc_iv` / `enc_payload` (`MEDIUMTEXT`, borne 16 000 000 → `400`) ;
  colonnes structurées vides acceptées sans `422`
- Recherche `q=` étendue à `categories` ; `enc_payload` jamais interrogé
- Export vCard limité aux champs en clair ; import ignorant les fiches chiffrées existantes
- Migration `20260804_e2e_taches_contacts.sql`

## Tests effectués

`php private/tests/run_all_tests.php` → **2529 / 2529, 0 échec**.

Suites nouvelles depuis la v2.13.0 :

| Suite | Assertions |
| - | - |
| `test_user_e2e_key.php` | 60 |
| `test_ics_journals_e2e.php` | 52 |
| `test_ics_todos_e2e.php` | 59 |
| `test_contacts_e2e.php` | 56 |

Round-trip octet pour octet vérifié par `strcmp` **et** `md5` sur des blobs base64 de 200 000
caractères, dans les trois entités.

## Références

- `docs/v-2-14-0/2.14.0_CLIENT.md` — changements visibles côté client
- `docs/v-2-14-0/2.14.0_PRODUCTION.md` — checklist de déploiement
- Directives : `20260803_165946`, `20260803_205805`, `20260804_090000` (toutes `complété`)

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`, `README.md`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-14-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-14-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Migrations pendantes déplacées dans `docs/v-2-14-0/` et intégrées à `build_DB-v-2.14.0.sql`
- [ ] CI status checks green
- [ ] Reviewer assigné

## Checklist API PHP

- [x] Migrations SQL appliquées (dev et prod, 2026-08-04)
- [x] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur
- [x] Endpoint racine répond correctement
- [ ] Redéploiement prod après merge du tag

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.14.0 -m "Release v2.14.0"` puis `git push origin v2.14.0`.
- **Ne jamais annuler les migrations de cette release** : supprimer `enc_payload` ou
  `user_e2e_keys` détruirait des données que personne, serveur compris, ne peut reconstituer.
  Un retour arrière se fait par redéploiement du code seul (voir `2.14.0_PRODUCTION.md` §8).
