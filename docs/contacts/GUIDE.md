# Guide du module Contacts

Pilier Contacts de cmem2 — fiches personnes/organisations, import/export vCard 4.0,
cap de plan `max_contacts`.

Réf. directive `20260723_084409_cmem_web_vers_cmem2_API__contacts-table-crud.md`.
Contrat machine : [API_CONTACTS_ENDPOINTS.json](API_CONTACTS_ENDPOINTS.json).

## Principes

- **Un contact n'est pas un compte.** `user_id` désigne le **propriétaire** de la fiche.
  La personne décrite n'a pas besoin d'exister comme utilisateur de l'app.
- **Portée owner-strict.** Toute lecture ou écriture sur la fiche d'autrui renvoie `403`.
- **Multi-tenant.** `app_id` par défaut `'puzzle'` côté serveur ; le client cmem_web transmet
  `'cmemweb'`. Il doit être transmis **en création comme en lecture de liste** : une liste
  demandée sans `app_id` renvoie le tenant par défaut, donc rien pour un client cmem_web.
- **Soft-delete.** `DELETE` renseigne `supprime_le` ; la ligne reste en base pour la purge RGPD
  par le cron existant. Toutes les lectures excluent les fiches supprimées.
- **Étiquettes libres.** `categories[]` est un tableau de chaînes sur la fiche — aucune entité
  `/contacts/tags` n'existe.

## Renommages par rapport à la directive

| Directive | Retenu | Raison |
| - | - | - |
| cap `contacts_max` | `max_contacts` | aligné sur `max_calendars`, `max_journals`, `max_tasks` |
| quota → `429` | `403` + `QUOTA_EXCEEDED` | aligné sur `EntitlementService::checkQuota` |
| table `contact` | `contacts` | tables du dépôt au pluriel |
| table `contact_partage` | `contact_shares` | noms de tables en anglais |

Les colonnes et les clés JSON restent en français : elles forment le contrat attendu par le front.

## Routes

| Méthode | Chemin | Rôle |
| - | - | - |
| GET | `/contacts` | Liste filtrée du propriétaire |
| POST | `/contacts` | Création (applique `max_contacts`) |
| GET | `/contacts/{id}` | Fiche complète |
| PUT | `/contacts/{id}` | Mise à jour partielle (PATCH équivalent) |
| DELETE | `/contacts/{id}` | Soft-delete |
| GET | `/contacts/{id}.vcf` | Export vCard 4.0 (`text/vcard`) |
| POST | `/contacts/import` | Import vCard ou CSV |

Toutes les routes exigent un JWT valide.

### Filtres de liste

`?q=` cherche dans `prenom`, `nom`, `organisation` et `courriels`.
`?categorie=` filtre sur une valeur de `categories[]`.
`?favori=1` limite aux favoris.
`?limit=` (1..500) et `?offset=` paginent ; `total` renvoie le nombre avant pagination.

## Champs répétables

Stockés en colonnes JSON, jamais `NULL` (tableau vide par défaut) :

```json
{
  "courriels":  [{ "type": "pro", "valeur": "marie@exemple.ca" }],
  "telephones": [{ "type": "mobile", "indicatif": "+1", "valeur": "514 555 0199" }],
  "adresses":   [{ "type": "pro", "ligne1": "10 rue Test", "ville": "Montréal",
                   "region": "QC", "code_postal": "H1A 1A1", "pays": "Canada" }],
  "sites":      [{ "label": "site", "url": "https://exemple.ca" }],
  "reseaux":    [{ "type": "linkedin", "handle": "marietremblay" }],
  "categories": ["client"]
}
```

Types acceptés : `courriels.type` ∈ `perso|pro|autre` ; `telephones.type` ∈ `mobile|fixe|fax|autre`.

## Validation

Une fiche doit porter au moins un de `prenom`, `nom`, `organisation` — une fiche
« organisation seule » est valide. Le vide intégral renvoie `422`, en création comme en
mise à jour.

## vCard 4.0

L'export est sérialisé **côté serveur** (comme `IcsGenerator` pour l'ICS) : lignes `CRLF`,
pliage à 75 octets, échappement RFC 6350. Propriétés émises : `N`, `FN`, `ORG`, `TITLE`,
`EMAIL;TYPE=`, `TEL;TYPE=`, `ADR`, `URL`, `X-SOCIALPROFILE`, `BDAY`, `CATEGORIES`, `NOTE`,
`REV`, `UID` (`urn:cmem2:contact:{id}`).

Correspondance des types :

| Fiche | vCard |
| - | - |
| `perso` | `TYPE=home` |
| `pro` | `TYPE=work` |
| `mobile` | `TYPE=cell` |
| `fixe` | `TYPE=voice` |
| `fax` | `TYPE=fax` |
| `autre` | `TYPE=other` |

L'import accepte les vCard 3.0 et 4.0. Les propriétés inconnues sont ignorées ; les préfixes
de groupe (`item1.EMAIL`) sont acceptés.

## Import

`POST /contacts/import` accepte soit un body JSON (`format` + `content`), soit un envoi
multipart (`file`). Sans `format`, il est déduit du contenu (`BEGIN:VCARD`) ou de l'extension.

Rapport renvoyé :

```json
{ "crees": 12, "maj": 3, "ignores": 1, "erreurs": [{ "ligne": 4, "raison": "..." }] }
```

- **Upsert** : rapprochement d'abord par courriel, sinon par `prenom` + `nom`. Une fiche
  reconnue est mise à jour (`maj`), pas dupliquée.
- **Cap** : une entrée qui dépasserait `max_contacts` est comptée dans `ignores` — l'import
  ne renvoie pas d'erreur globale.
- **Tolérance** : une entrée invalide alimente `erreurs[]` et n'interrompt pas le traitement.

Colonnes CSV reconnues : `prenom`, `nom`, `organisation`, `fonction`, `courriel`, `telephone`,
`categories`, `adresse`, `ville`, `code_postal`, `pays`, `notes`, `anniversaire`, `site`.
En-tête obligatoire ; séparateur détecté parmi `,`, `;` et tabulation ; alias courants acceptés
(`email`, `phone`, `company`, `first_name`…).

## Cap de plan

| Plan | `max_contacts` |
| - | - |
| free | 50 |
| monthly | 2000 |
| yearly | 2000 |
| ami | 2000 |

Exposé dans `GET /auth/me` → `data.user.plan.features.max_contacts`. Dépassement à la création :

```json
{ "success": false, "message": "Quota de contacts atteint",
  "errors": { "code": "QUOTA_EXCEEDED", "resource": "max_contacts", "limit": 50, "current": 50 } }
```

## Partage (réservé P1)

La table `contact_shares` et la colonne `partage_scope` existent mais ne sont **pas exploitées**
en v1 : aucune route ne les lit ni ne les écrit. Elles évitent une migration cassante lors de
l'ajout du partage.

## Tests

```bash
php private/tests/test_contacts.php
```

Couvre : sécurité, CRUD et scoping, filtres et pagination, export vCard, import vCard/CSV,
cap `max_contacts`.
