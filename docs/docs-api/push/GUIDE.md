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
  "body": "14h30 - 15h00",
  "data": { "type": "event", "id": 1234, "occurrence": "2026-07-27T09:00:00+00:00" }
}
```

`data.type` reprend le `kind`, `data.id` l'identifiant de l'entité : c'est tout ce dont
le client a besoin pour router le clic. Aucun titre d'événement, de tâche ou
d'opportunité n'est transmis (exigence de confidentialité de la directive §6).

## Préférences — portée compte

> Dépréciées en v2.22.0 : `GET|PUT /push/preferences` restent compatibles une version
> (projection des défauts `DISPLAY` de `/notifications/defaults`), puis seront retirées.

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
contact, titre opportunité — au lieu du générique. `body` est indépendant de ce réglage :
voir § Corps du rappel — date/heure réelle (seul le motif de relance en dépend).

## Corps du rappel — date/heure réelle (task cmem #215)

Depuis v2.17.4, le `body` des kinds `event`, `recurring` et `task_due` affiche la
date/heure réelle de l'élément, dans le fuseau du compte (`users.timezone`), plutôt que le
délai générique :

| Cas | Exemple de `body` |
| - | - |
| Événement ponctuel (pas journée entière) | `14h30 - 15h00` |
| Événement journée entière, un seul jour | `3 sept.` |
| Événement journée entière ou multi-jours | `3 sept. - 5 sept.` |
| Tâche échue, avec heure | `3 sept. 14h05` |
| Tâche échue, journée entière | `3 sept.` |
| Relance de contact (directive #501) | `Relance : 3 sept.` |
| Relance de contact, `show_entity_detail` (fiche non chiffrée) | `Relance : 3 sept. — Rappeler pour le devis` |
| Clôture d'opportunité (directive #501) | `Clôture prévue : 3 sept.` |

L'année n'est ajoutée que si elle diffère de l'année courante. Depuis la directive #501,
`contact_followup` donne aussi la date (et le motif de relance selon `show_entity_detail`).

Le courriel reprend la phrase de délai (« Un suivi de contact est à faire dans 1 h 33. »)
suivie de ce corps. Délai exact, sans arrondi : `93` → « dans 1 h 33 », `60` → « dans
1 heure », `120` → « dans 2 heures », `1440` → « dans 1 jour », `1533` → « dans 1 jour et
1 h 33 ».

## Sélection des échéances

Depuis la v2.22.0 (contrat #236), la sélection est commune au courriel et au push :
`AuthGroups\Services\ReminderScanner`, appelé par le cron unifié. Règles complètes :
[guide ICS — Rappels unifiés](../ics/GUIDE.md#rappels-unifiés-v2220-contrat-236).

- Rappels effectifs d'un élément = sa liste `notifications`, sinon le défaut de son type
  (`/notifications/defaults`). Les préférences de ce module sont une projection de ces défauts.
- Un rappel est dû quand `échéance − minutes_before ≤ maintenant`, tant que son retard ne
  dépasse pas la tolérance : 60 minutes pour `event`, `recurring`, `task_due` ; 7 jours pour
  `contact_followup`. Un rappel plus en retard (élément créé ou déplacé tard) ne part pas.
- `DISPLAY` : un envoi par appareil dont `kinds` contient le type, hors plage silencieuse du
  type et de l'appareil.

Fuseaux : datetimes dans le fuseau de l'entité ; échéances sans heure (tâche « toute la
journée », tâche de projet, relance, opportunité) à 00:00 dans le fuseau de l'usager
(`users.timezone`, sinon premier calendrier, sinon `America/Montreal`). Comparaisons en UTC.

## Idempotence et purge

- Avant tout envoi, le cron réserve le rappel dans `reminder_log` (`INSERT IGNORE` sur
  élément, occurrence, canal, délai, appareil — `device_id = 0` pour le courriel).
  Réservation refusée = déjà envoyé. Un appareil réabonné reçoit les rappels encore dans
  la tolérance qu'il n'avait pas reçus.
- Abonnement rejeté en `404`/`410` : supprimé immédiatement.
- Abonnement sans envoi réussi depuis 90 jours (`last_seen_at`, sinon `created_at`) :
  supprimé par le cron.
- `push_notification_log` n'est plus écrit (conservé en lecture, retiré à la version suivante).

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

Le script historique `src/push/send_push_notifications.php` lance le cron unifié
`src/notifications/send_reminders.php` sur le canal `DISPLAY` ; la ligne crontab ne change
pas :

```crontab
*/5 * * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/push/send_push_notifications.php >> /home/lmdkhdg5/logs/push-$(date +\%Y-\%m-\%d).log 2>&1
```

Ne pas utiliser `php` seul : en contexte cron cPanel, il pointe vers `php-cgi`.

Options :

| Option | Effet |
| - | - |
| `--dry-run` | Liste les rappels dus sans envoyer et sans écrire au journal |
| `--verbose` | Ajoute une ligne `SCAN {...}` par usager (candidats et rappels dus par type) |
| `--batch=N` | Nombre maximal d'usagers traités (défaut 500) |
| `--user=ID` | Restreint le balayage à un usager |
| `--now=ISO8601` | Instant de référence — tests déterministes |
| `--channel=EMAIL\|DISPLAY\|all` | Canal (fixé par les scripts historiques) |

`.env` : `REMINDER_APP_ID` (défaut `cmemweb`) — app dont les défauts et les appareils sont lus.

Sortie, une ligne par rappel (élément, canal, délai) :

```txt
DUE user=297 channel=DISPLAY kind=contact_followup entity=41 entity_type=opportunite occ=2026-07-27 lead=1440 devices=2 due=2026-07-27T04:00:00+00:00 title="Rappel" body="Clôture prévue : 27 juil."
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
