# Guide — Notifications push web (module `push`)

Web Push (RFC 8030) signé VAPID (RFC 8292). Par défaut, le serveur pousse un rappel générique
vers chaque appareil abonné ; le détail de l'entité n'apparaît qu'après ouverture de
l'application. Opt-in par kind (`show_entity_detail`) : le titre du push peut porter le titre
réel de l'entité — voir § Préférences.

Directive d'origine : `cmem_web` 20260726_140426.

## Vue d'ensemble

| Élément | Valeur |
| - | - |
| Base path | `/push` |
| Authentification | JWT Bearer (les 5 routes) |
| Multi-tenant | `app_id` — `cmemweb` pour cmem_web, défaut serveur `puzzle` |
| Portée des préférences | **Compte** (jamais par appareil) |
| Tables | `push_subscriptions`, `notification_prefs`, `push_notification_log` |
| Cron | `src/push/send_push_notifications.php`, toutes les 5 minutes |
| Bibliothèque | `minishlink/web-push` ^9 (ext-openssl, ext-curl, ext-gmp/bcmath) |

## Flux d'intégration client

1. `GET /push/vapid-public-key` → `data.publicKey`.
2. `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: publicKey })`.
3. `POST /push/subscribe` avec `endpoint`, `keys.p256dh`, `keys.auth`, `device_label`,
   `app_id: 'cmemweb'`.
   - Premier envoi → `201`. Ré-abonnement du même appareil → `200`, même `id`, aucune
     ligne dupliquée.
4. `GET /push/preferences` puis `PUT /push/preferences` pour régler les 4 `kind`.
5. Désinstallation / retrait du consentement → `DELETE /push/subscribe` (corps :
   `endpoint`) → `204`.

Le service worker reçoit :

```json
{
  "title": "Rappel",
  "body": "Vous avez un événement dans 15 minutes.",
  "data": { "type": "event", "id": 1234, "occurrence": "2026-07-27T09:00:00+00:00" }
}
```

`data.type` reprend le `kind`, `data.id` l'identifiant de l'entité : c'est tout ce dont
le client a besoin pour router le clic. Aucun titre d'événement, de tâche ou
d'opportunité n'est transmis (exigence de confidentialité de la directive §6).

## Préférences — portée compte

`notification_prefs` est unique sur `(owner_id, app_id, kind)`. C'est la réponse au point
laissé à trancher par la directive : **les préférences font autorité au niveau du compte**.

Raison : l'exigence « une échéance ne doit compter que pour un envoi logique » est
incompatible avec des `lead_minutes` divergents d'un appareil à l'autre — deux appareils
réglés à 15 et 60 minutes produiraient deux notifications pour la même échéance.

Les 4 `kind` sont présents dès la première migration, `contact_followup` compris :

| `kind` | Source de l'échéance |
| - | - |
| `event` | `calendar_events` sans `recurrence_rule` — `start_datetime` |
| `recurring` | `event_occurrences` des événements récurrents — occurrence par occurrence |
| `task_due` | `calendar_todos.due`, statut ≠ `COMPLETED`/`CANCELLED` |
| `contact_followup` | Deux sources : `contacts.date_relance` (relance non faite) et `opportunite.date_cloture_prevue`, étape ≠ `gagne`/`perdu` |

`contact_followup` couvre deux entités sous un seul `kind` (décision cmem_web du 2026-07-26) :
le payload `data` porte `entity` (`contact` ou `opportunite`) en plus de `type` et `id`, ce qui
évite une migration d'`ENUM` à chaque nouvelle source. Une relance n'est retenue que si
`date_relance IS NOT NULL AND relance_faite_le IS NULL` sur une fiche non supprimée.

L'`occurrence_key` des relances est préfixée `relance:` — `push_notification_log` est unique
sur `(owner_id, kind, entity_id, occurrence_key)`, et sans ce préfixe un contact et une
opportunité de même id échéant le même jour s'annuleraient mutuellement.

`lead_minutes` accepte `5`, `15`, `60`, `1440`. `quiet_from`/`quiet_to` vont par paire
(fournir l'un sans l'autre → `422`) et gèrent le passage de minuit (`22:00` → `07:00`).

Par défaut, un `kind` jamais réglé est renvoyé avec `enabled = false` : le push est
**opt-in**.

### `show_entity_detail` — titre réel de l'entité (task cmem #199)

Par kind, opt-in, **défaut `false`** : le `title` du push reste générique (`PUSH_GENERIC_TITLE`).
Réglé à `true`, `title` devient le titre réel de l'entité — titre événement/tâche, nom
contact, titre opportunité — au lieu du générique. `body` reste **toujours** le texte
générique par délai (« dans 15 minutes », etc.), même quand `show_entity_detail = true` :
seul `title` porte le détail.

## Sélection des échéances

Une échéance est due dès qu'elle tombe dans `]maintenant − grâce ; maintenant + lead_minutes]`.

La fenêtre n'est pas bornée par la période du cron : l'idempotence
(`push_notification_log`) suffit à garantir l'envoi unique, ce qui rend le balayage
insensible à un cron en retard, arrêté quelques heures, ou relancé à la main.

Grâce (échéances déjà passées) : 60 minutes pour `event`, `recurring`, `task_due` ;
7 jours pour `contact_followup` (un suivi en retard doit rester signalé).

Fuseaux : les datetimes sont stockés dans le fuseau de l'entité
(`calendar_events.timezone`, `calendar_todos.timezone`) ; les échéances sans heure
(relances de contact, opportunités) sont fixées à 00:00 dans le fuseau de l'usager. Toutes les comparaisons se
font en UTC, jamais avec `NOW()` côté SQL.

Le fuseau de l'usager est retenu dans cet ordre : `users.timezone` (posé par le client via
`PUT /users/me`), à défaut le fuseau de son premier calendrier, à défaut
`America/Montreal`. La plage « ne pas déranger » est évaluée dans ce fuseau.

Un identifiant absent de la base IANA du serveur est ignoré au profit du repli suivant —
jamais passé à `DateTimeZone`.

## Idempotence et purge

- Avant tout envoi, le cron réserve l'échéance dans `push_notification_log`
  (`INSERT IGNORE` sur `(owner_id, kind, entity_id, occurrence_key)`). Réservation
  refusée = déjà notifiée = rien n'est envoyé.
- Une ligne de journal par échéance, quel que soit le nombre d'appareils : `devices` et
  `delivered` gardent la trace du fan-out.
- Toute subscription rejetée en `404`/`410` par le service de push est supprimée
  immédiatement. C'est la maintenance principale du module : aucun ménage périodique
  n'est requis en plus.

## Configuration `.env`

```env
VAPID_PUBLIC_KEY=<base64url, 87 caractères>
VAPID_PRIVATE_KEY=<base64url, 43 caractères>
VAPID_SUBJECT=mailto:support@journauxdebord.com
PUSH_GENERIC_TITLE=Rappel
PUSH_TTL_SECONDS=86400
```

Génération de la paire :

```bash
php src/push/generate_vapid.php
```

La clé privée reste sur le serveur — jamais versionnée, jamais transmise au client.
Regénérer la paire invalide toutes les subscriptions existantes : les navigateurs
refusent un envoi signé par une autre clé que celle passée à `pushManager.subscribe()`.

## Cron

Ligne crontab (production, toutes les 5 minutes) :

```crontab
*/5 * * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/push/send_push_notifications.php >> /home/lmdkhdg5/logs/push-$(date +\%Y-\%m-\%d).log 2>&1
```

Ne pas utiliser `php` seul : en contexte cron cPanel, il pointe vers `php-cgi`.

Options :

| Option | Effet |
| - | - |
| `--dry-run` | Liste les échéances sans envoyer et sans écrire au journal |
| `--verbose` | Ajoute une ligne `SCAN {...}` par usager (préférences actives, candidats, retenus) |
| `--batch=N` | Nombre maximal d'usagers traités (défaut 200) |
| `--user=ID` | Restreint le balayage à un usager |
| `--now="Y-m-d H:i:s"` | Instant de référence — tests déterministes |

Sortie, une ligne par échéance :

```txt
DUE user=297 kind=contact_followup entity=41 entity_type=opportunite occ=2026-07-27 devices=2 title="Rappel" body="Un suivi de contact est à faire dans 1 jour(s)."
DUE user=297 kind=contact_followup entity=10 entity_type=contact occ=relance:2026-07-27 devices=2 title="Rappel" body="Un suivi de contact est à faire dans 1 jour(s)."
```

## Tests

```bash
php private/tests/test_push.php
```

Le cron y est exécuté **par SSH sur le serveur dev**, pas en local : l'OpenSSL fourni par
XAMPP ne sait pas générer la clé éphémère P-256 exigée par le chiffrement `aes128gcm`
(« Unable to create the local key »), donc ni l'envoi réel ni la purge sur `410` ne sont
observables sur un poste Windows. Le serveur dev partage la base de données utilisée par
les appels HTTP des tests.

## Codes d'erreur

| Code | Quand |
| - | - |
| `401` | JWT absent ou invalide (toutes les routes) |
| `404` | `DELETE /push/subscribe` sur un endpoint inconnu **ou** appartenant à un tiers |
| `422` | `app_id`/`endpoint`/`keys` manquants, `endpoint` non-https, `kind` inconnu, `lead_minutes` hors valeurs, horaire ≠ `HH:MM`, `quiet_from` sans `quiet_to` |
| `503` | `GET /push/vapid-public-key` alors que le serveur n'a pas de clé VAPID |
