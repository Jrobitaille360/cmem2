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
| POST | `/contacts/{id}/messages` | Envoi courriel + journalisation |
| GET | `/contacts/{id}/messages` | Historique des courriels |
| GET | `/contacts/{id}/interactions` | Historique CRM unifié (email + saisies) |
| POST | `/contacts/{id}/interactions` | Saisie manuelle (appel/note/rdv/sms) |
| DELETE | `/contacts/{id}/interactions/{interactionId}` | Soft-delete d'une interaction |

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

## Communication — envoi de courriel

Directive cmem_web `20260724_090048` (Phase G-B). Envoyer un courriel depuis une fiche contact
via l'infra mail serveur (SPF/DKIM), plutôt qu'un simple `mailto:` dépendant du client mail.

### `POST /contacts/{id}/messages`

Envoie un courriel au contact **au nom de l'usager courant** et journalise l'envoi.

- `From` = adresse serveur ; `Reply-To` = courriel de l'usager courant (les réponses lui reviennent).
- Owner-strict : `403` si la fiche appartient à un autre usager, `404` si absente.
- Corps envoyé en **texte brut**.

Body :

```json
{ "app_id": "cmemweb", "canal": "email",
  "destinataire": "marie@exemple.ca", "sujet": "Suivi devis", "corps": "Bonjour Marie, ..." }
```

- `canal` doit valoir `email` (seul canal supporté en v1) — sinon `422`.
- `sujet` et `corps` requis non vides — sinon `422`.
- `destinataire` optionnel : si absent, on résout le **courriel principal** du contact — le premier
  courriel de type `pro`, sinon le premier de `courriels[]`. Si le contact n'a aucun courriel et
  qu'aucun `destinataire` n'est fourni → `422`. Un `destinataire` fourni mais non valide → `422`.

Réponse `201` :

```json
{ "success": true, "data": { "message": {
  "id": 5, "contact_id": 10, "canal": "email",
  "destinataire": "marie@exemple.ca", "sujet": "Suivi devis",
  "statut": "envoye", "envoye_le": "2026-07-24 09:10:00" } } }
```

### `GET /contacts/{id}/messages`

Historique des courriels envoyés depuis la fiche (canal email), owner-strict, plus récents d'abord.
Accepte `?limit=` et `?offset=`.

### Journalisation — table `interaction`

Chaque envoi est journalisé dans `interaction` (`type='email'`, `direction='sortant'`,
`statut='envoye'|'echec'`). La table est **générique** : elle anticipe la directive
`crm-interactions` (Phase C — historique unifié appels/sms/notes) pour éviter un doublon.

### Rate-limit anti-abus

Réutilise `RateLimitService` (table `login_attempts`, endpoint `contact-message`), clé =
courriel de l'usager courant + IP. Dépassement → `429`. Seuil : `RATE_LIMIT_AUTH_MAX_ATTEMPTS`
envois par `RATE_LIMIT_AUTH_WINDOW_MINUTES` minutes.

### Position CASL / RGPD

La v1 ne couvre que du courriel **transactionnel / personnel**, initié manuellement par le
propriétaire vers sa propre fiche : pas de consentement commercial ni de lien de désabonnement
requis. La colonne `contacts.optout_courriel` (défaut `0`) est **réservée** et non bloquante en
v1 ; elle devra bloquer l'envoi si l'usage devient commercial (envoi de masse).

## CRM — historique d'interactions (Phase G-C)

Directive cmem_web `20260724_143353`. Réutilise la table `interaction` (pas de nouvelle table)
pour offrir un **historique 360°** par contact et l'**enregistrement manuel** d'interactions.

### `GET /contacts/{id}/interactions`

Historique **unifié** de toutes les interactions de la fiche — les courriels journalisés par
`/messages` (Phase G-B) y apparaissent aussi. Plus récentes d'abord. Owner-strict. Filtres
`?type=`, `?limit=`, `?offset=`. Les interactions soft-supprimées sont exclues.

Contrat unifié par entrée : `{ id, contact_id, type, direction, date, resume, statut,
piece_jointe_file_id }`. Pour un courriel, `date` = date d'envoi (`envoye_le`) et `resume` =
`sujet` — le mapping est fait à l'hydratation, sans dupliquer les données.

### `POST /contacts/{id}/interactions`

Saisie manuelle : `type` ∈ `appel|note|rdv|sms` (le `type='email'` est **refusé** — passe par
`/messages`), `direction` défaut `sortant`, `date` défaut = maintenant, `resume` requis,
`piece_jointe_file_id` optionnel. `422` si `resume` vide, `type` invalide/`email`, ou `date`
mal formée. `statut` reste `null` pour les saisies manuelles.

### `DELETE /contacts/{id}/interactions/{interactionId}`

Soft-delete (`interaction.supprime_le`). L'interaction disparaît ensuite du `GET`. Owner-strict :
`404` si l'interaction n'existe pas, est déjà supprimée, ou n'appartient pas à la fiche.

### Cascade

Le soft-delete d'un **contact** masque ses interactions : le `GET` passe par la vérification de
propriété de la fiche (`404` si la fiche est supprimée), rendant ses interactions inaccessibles
sans purge physique.

## CRM — pipeline d'opportunités (Phase G-D)

Directive cmem_web `20260724_154618`. Table `opportunite` : opportunités commerciales rattachées
à un contact, affichées en **Kanban** côté client. Owner-strict, multi-tenant `app_id`,
devise par défaut `CAD`, soft-delete (`supprime_le`).

### `GET /contacts/{id}/opportunites`

Opportunités actives de la fiche, plus récentes d'abord. `403` fiche d'autrui, `404` fiche
absente ou supprimée.

### `POST /contacts/{id}/opportunites`

Corps : `{ titre, etape?, montant?, devise?, date_cloture_prevue?, notes? }`.
Défauts : `etape='prospect'`, `devise='CAD'`. `422` si `titre` vide, `etape` hors
`prospect|qualifie|proposition|gagne|perdu`, `montant` non numérique, `devise` non ISO 4217
(3 lettres) ou `date_cloture_prevue` hors format `Y-m-d`.

### `GET /opportunites?etape=`

**Board Kanban global** : toutes les opportunités du propriétaire, toutes fiches confondues.
Filtre `?etape=` optionnel (`422` si étape inconnue), pagination `?limit=` / `?offset=`,
réponse `{ opportunites, total }`. Les opportunités des contacts supprimés sont exclues.

### `PUT /opportunites/{opId}`

Mise à jour **partielle** — seuls les champs transmis sont écrits ; sert au changement d'étape
par glisser-déposer (`{ "etape": "proposition" }`). `PATCH` est accepté à l'identique.
`403` opportunité d'autrui, `404` inexistante ou supprimée.

### `DELETE /opportunites/{opId}`

Soft-delete. L'opportunité disparaît du board et de la fiche ; ses liens croisés (`/links`)
sont purgés.

### Cascade

Le soft-delete d'un **contact** masque ses opportunités (`Contact::softDeleteContact` appelle
`Opportunite::softDeleteByContact`) et purge les liens croisés du contact, de ses interactions
et de ses opportunités. Le board global exclut de toute façon les opportunités dont le contact
est supprimé.

## GED — documents rattachés (Phase G-E)

Directive cmem_web `20260724_154619`. **Aucune nouvelle mécanique** : les documents rattachés
sont des liens `/links` (voir `docs/links/GUIDE.md`) dont l'enum de types accepte désormais
`file`, `contact`, `interaction` et `opportunite`.

Rattacher un fichier à une fiche :

```json
POST /links
{ "app_id": "cmemweb", "src_type": "file", "src_id": 42, "dst_type": "contact", "dst_id": 10 }
```

Bibliothèque documentaire d'une fiche : `GET /links?type=contact&id=10&app_id=cmemweb` —
`other_title` vaut le nom du fichier (`files.original_name`). Inversement,
`GET /links?type=file&id=42` liste toutes les entités auxquelles le document est rattaché ;
`other_title` d'un contact vaut « prénom nom » (ou l'organisation si les deux sont vides).

La suppression d'un fichier ou d'un contact **purge** les liens correspondants — aucun orphelin.

## Relance de contact (Phase G-F)

Directive `20260726_161400`, modèle **A1** retenu par cmem_web : la relance est portée par la
**fiche**, pas par l'interaction. Une seule relance en cours par contact.

| Champ | Type | Rôle |
| - | - | - |
| `date_relance` | `date\|null` | Date de la prochaine relance à faire |
| `motif_relance` | `string\|null` | Motif libre, tronqué à 255 caractères |
| `relance_faite_le` | `datetime\|null` | Horodatage du traitement ; `null` = relance en cours |

Une fiche est **à relancer** quand `date_relance IS NOT NULL AND relance_faite_le IS NULL`.

### Écriture

Les trois champs s'écrivent par `POST /contacts` et `PUT /contacts/{id}` :

```json
PUT /contacts/10
{ "app_id": "cmemweb", "date_relance": "2026-08-03", "motif_relance": "Rappeler après le devis" }
```

- `relance_faite_le: true` horodate côté serveur ; `null` annule la marque ; une chaîne
  `AAAA-MM-JJ HH:MM:SS` est acceptée telle quelle.
- Poser une `date_relance` **différente** de l'existante remet `relance_faite_le` à `null` —
  une nouvelle échéance rouvre le suivi. Fournir `relance_faite_le` dans la même requête
  désactive cette remise à zéro.
- `date_relance: null` efface la relance et la marque.
- Une date mal formée (`2026-13-40`, `demain`) renvoie `422` sans rien écrire.

L'API **ne pose jamais** de relance automatiquement, notamment pas à la création d'une
interaction : un « rappeler dans 7 jours » est un préréglage côté client.

### Notification push

Le cron `contact_followup` balaie **deux** sources : les relances de fiche et les échéances
`opportunite.date_cloture_prevue`. Pas de 5e `kind` — le payload `data` porte
`entity: "contact" | "opportunite"` pour savoir quelle vue ouvrir. Le corps reste générique :
ni le nom du contact ni le motif n'y figurent. Détails : `docs/push/GUIDE.md`.

## Partage (réservé P1)

La table `contact_shares` et la colonne `partage_scope` existent mais ne sont **pas exploitées**
en v1 : aucune route ne les lit ni ne les écrit. Elles évitent une migration cassante lors de
l'ajout du partage.

## Tests

```bash
php private/tests/test_contacts.php
php private/tests/test_contacts_messages.php
php private/tests/test_contacts_interactions.php
php private/tests/test_contacts_opportunites.php
php private/tests/test_links_ged.php
```

`test_contacts.php` couvre : sécurité, CRUD et scoping, filtres et pagination, export vCard,
import vCard/CSV, cap `max_contacts`.

`test_contacts_messages.php` couvre : sécurité, validation, résolution du destinataire,
envoi + journalisation `interaction`, historique, rate-limit anti-abus.
