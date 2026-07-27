# Plan — Relance de contact (volet A de la directive 20260726_161400)

Réponse de `cmem_web` du 2026-07-26 : modèle **A1** retenu — la relance est portée par la
**fiche contact**, pas par l'interaction.

## Ce qui est déjà en place

- Pilier Contacts complet : `contacts` (CRUD, vCard, cap `max_contacts`), `interaction`,
  `opportunite` avec pipeline et `date_cloture_prevue`.
- Push web livré : `notification_prefs` (4 `kind`, dont `contact_followup`),
  `push_notification_log` (idempotence), `DueScanner`, cron d'envoi.
- `contact_followup` ne scanne aujourd'hui **qu'une** source : `Opportunite::date_cloture_prevue`
  sur les étapes ouvertes — `DueScanner::scanOpportunites()`.
- `users.timezone` (volet B, même directive) : le fuseau de l'usager fait autorité pour les
  échéances sans heure et la plage silencieuse.

## Ce qui manque

Aucune colonne de relance sur `contacts`. Le suivi « rappeler ce contact le … » n'existe donc
ni en base, ni dans le contrat JSON, ni dans le balayage du cron.

## Critères d'acceptation

### API contacts

| # | Critère |
| - | - |
| C1 | `POST /contacts` accepte `date_relance` et `motif_relance` ; les valeurs sont persistées |
| C2 | `GET /contacts` et `GET /contacts/{id}` exposent `date_relance`, `motif_relance`, `relance_faite_le` |
| C3 | `date_relance` mal formée (`2026-13-40`, `demain`) → `422`, rien n'est écrit |
| C4 | `date_relance: null` efface la relance **et** remet `relance_faite_le` à `null` |
| C5 | `relance_faite_le: true` marque la relance faite (horodatage serveur) ; `null` l'annule |
| C6 | Poser une `date_relance` **différente** de l'existante remet `relance_faite_le` à `null` |
| C7 | Re-poster la **même** `date_relance` conserve `relance_faite_le` |
| C8 | `motif_relance` accepté seul, tronqué à 255 caractères, `null` accepté |

### Cron push

| # | Critère |
| - | - |
| P1 | Contact avec `date_relance` demain et `relance_faite_le IS NULL` → 1 échéance `contact_followup`, `entity` = id du contact |
| P2 | Le `data` du push porte `entity` = `contact` \| `opportunite` ; le `kind` reste `contact_followup` |
| P3 | Relance marquée faite → aucune échéance |
| P4 | Contact soft-supprimé → aucune échéance |
| P5 | Contact et opportunité de **même id** et même date → 2 échéances distinctes, 2 lignes de log |
| P6 | Idempotence : 2e run du cron → 0 échéance |
| P7 | L'échéance est calculée à 00:00 dans `users.timezone` |
| P8 | Le corps du push reste générique — aucun nom de contact ni motif |

## Enjeux

**Collision de clé d'idempotence.** `push_notification_log` porte
`UNIQUE (owner_id, kind, entity_id, occurrence_key)`. Avec deux sources sous le même `kind`,
un contact `id=5` et une opportunité `id=5` échéant le même jour produiraient la même clé :
le second push serait avalé. Correctif retenu : préfixer l'`occurrence_key` des relances
(`relance:2026-08-03`) et laisser celle des opportunités inchangée (`2026-08-03`), ce qui
évite de re-notifier les échéances déjà journalisées.

**Pas de 5e `kind`.** Décision cmem_web : réutilisation de `contact_followup`, donc aucune
migration d'`ENUM` sur `notification_prefs` / `push_notification_log`. Le client distingue les
deux sources par la clé `entity` du payload.

**Pas de pose automatique.** L'API n'écrit jamais de relance à la création d'une interaction.
Le « +7 j » est un préréglage client.

**Écrasement silencieux.** `Contact::updateContact()` n'écrit que les champs présents ; la
remise à zéro de `relance_faite_le` doit donc être calculée dans le contrôleur, pas déduite
d'un champ absent.

## Phases

### Phase 1 — Migration

**Actions.** `docs/20260726_contacts_relance.sql` : trois colonnes nullables sur `contacts`
plus un index de balayage. Application sur la base dev.

**Enjeux.** Colonnes nullables sans valeur par défaut : les fiches existantes restent sans
relance. Aucune donnée réécrite.

**Tests.** `SHOW COLUMNS FROM contacts` ; `test_contacts.php` inchangé dans ses résultats.

**Terminé quand.** Les colonnes existent sur dev et la suite Contacts reste verte.

### Phase 2 — Tests en échec

**Actions.** Section relance dans `test_contacts.php` (C1–C8) ; section relance dans
`test_push.php` (P1–P8).

**Terminé quand.** Les nouveaux tests échouent pour la bonne raison — champs absents du
contrat, échéance non listée par le cron.

### Phase 3 — Code

**Actions.**

1. `Contact` : trois colonnes ajoutées à `SCALAR_FIELDS`, hydratation du contrat.
2. `ContactController::extractFields()` : validation de `date_relance` (format `Y-m-d` réel),
   `motif_relance` (255), `relance_faite_le` (bool / datetime / null).
3. `ContactController::update()` : remise à `null` de `relance_faite_le` quand la nouvelle
   `date_relance` diffère de l'ancienne et que le champ n'est pas fourni explicitement.
4. `DueScanner::scanRelancesContact()` + fusion des deux sources sous `contact_followup`,
   clé `entity` dans le payload, `occurrence_key` préfixée.

**Tests.** `test_contacts.php`, `test_push.php`, puis la suite complète.

**Terminé quand.** Les deux suites passent sur dev, 0 échec.

### Phase 4 — Documentation et clôture

**Actions.** `docs/contacts/GUIDE.md`, `docs/contacts/API_CONTACTS_ENDPOINTS.json`,
`docs/push/GUIDE.md` (section sources de `contact_followup`), `CHANGELOG.md`.
Directive `20260726_161400` : volet A livré côté API.

**Terminé quand.** Suite complète verte et docs à jour.

## Hors périmètre

- Modèle A2 (relance portée par l'interaction) — ouvrable plus tard sans casser A1.
- Toute pose automatique de relance côté serveur.
- L'UI (badge « à relancer », bouton « marquer faite ») — côté cmem_web.

## Journal d'implantation

| Phase | Début | Fin | Résultat |
| - | - | - | - |
| 1 — Migration | 2026-07-26 21:55 | 2026-07-26 21:58 | `docs/20260726_contacts_relance.sql` appliquée sur dev : 3 colonnes + index `idx_contacts_relance` |
| 2 — Tests en échec | 2026-07-26 21:58 | 2026-07-26 22:04 | `test_contacts.php` 24 échecs, `test_push.php` 5 échecs — tous par absence des champs ou de la 2e source |
| 3 — Code | 2026-07-26 22:04 | 2026-07-26 22:11 | Modèle, contrôleur, `DueScanner::scanRelancesContact()`, ligne `DUE` enrichie ; 2 itérations de correction, portant toutes deux sur les tests eux-mêmes |
| 4 — Documentation | 2026-07-26 22:12 | 2026-07-26 22:20 | GUIDE Contacts et Push, `API_CONTACTS_ENDPOINTS.json`, CHANGELOG, directive annotée |

### Écarts par rapport au plan initial

- Aucune correction du code applicatif n'a été nécessaire après la première écriture. Les deux
  itérations ont porté sur des assertions de test fautives : `?? 'x'` ne distingue pas « clé
  absente » de « valeur `null` », et le contrôle de fuseau s'exécutait après la purge des
  subscriptions, donc sans aucun usager à balayer. Une sonde HTTP directe a confirmé le
  comportement réel de l'API avant toute modification de `src/`.
- La ligne `DUE` du cron a reçu un champ `entity_type` non prévu au plan : sans lui, la
  distinction des deux sources n'est pas observable en `--dry-run`.
