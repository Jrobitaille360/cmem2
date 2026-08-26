# RELEASE NOTES — cmem2_API v2.14.0

## Description courte

Le chiffrement de bout en bout arrive sur les journaux, les tâches et les contacts : le contenu est
chiffré sur l'appareil de l'usager, et l'API n'en conserve que des octets qu'elle ne peut pas lire.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- **Chiffrement de bout en bout des journaux, des tâches et des contacts.** Le contenu est chiffré
  sur l'appareil avant d'être envoyé. Le serveur le stocke sans jamais pouvoir l'ouvrir : aucune
  clé, aucune passphrase, aucun code de secours ne lui parvient.
- **Gestion de la clé personnelle** (`/users/me/e2e-key`) : activation du chiffrement, changement
  de passphrase, code de secours, et usage sur plusieurs appareils.
- **Code de secours facultatif** : l'usager qui le refuse garde un compte fonctionnel — il devra
  simplement retenir sa passphrase.
- **Modification partielle des tâches** (`PATCH`), au même titre que les journaux.
- **Recherche de contacts par catégorie** en plus du nom, du prénom, de l'organisation et du
  courriel.

### Modifié

- Les titres et les contenus acceptent des textes nettement plus longs, pour laisser la place au
  contenu chiffré (qui occupe environ un tiers de plus que le texte d'origine).
- Sur une fiche contact chiffrée, la recherche serveur ne porte plus que sur le prénom, le nom et
  les catégories : le courriel et les notes ne sont plus lisibles par le serveur. La recherche fine
  se fait dans l'application, une fois le contenu déchiffré.
- L'export vCard d'une fiche chiffrée ne contient que les champs restés lisibles. La sauvegarde
  complète et déchiffrée se fait depuis l'application.
- Les notifications de rappel n'affichent jamais le titre d'une tâche ni le nom d'un contact
  chiffré — un libellé générique est utilisé.

### Corrigé

- La suppression des métadonnées de clé supprimait le compte de l'usager au lieu de la seule clé.

### À savoir

- Perdre à la fois sa passphrase **et** son code de secours rend le contenu chiffré définitivement
  illisible. C'est le prix du bout en bout : personne, pas même l'exploitant du service, ne peut le
  récupérer.
- Rien n'est chiffré automatiquement. Tant que l'usager n'active pas le chiffrement, tout se
  comporte exactement comme avant.

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-14-0/2.14.0_CLIENT.md`.

## Distribution des artefacts

Aucun binaire dans cette release — elle ne concerne que l'API, déployée directement sur le serveur.

| Format | Canal de distribution |
| - | - |
| API | `private/deploy.ps1 -Target prod` → cmem2.journauxdebord.com |

## Instructions de déploiement rapides

```bash
# Migrations SQL : déjà appliquées en dev et en prod le 2026-08-04
# Voir docs/v-2-14-0/2.14.0_PRODUCTION.md pour la checklist complète

# Tag Git
git tag -a v2.14.0 -m "Release v2.14.0"
git push origin v2.14.0

# GitHub Release (sans artefacts joints)
gh release create v2.14.0 \
  --title "v2.14.0" \
  --notes-file docs/v-2-14-0/RELEASE_NOTES.md \
  --draft
```

## Notes hotfix

Sans objet — release ordinaire depuis `main`.
