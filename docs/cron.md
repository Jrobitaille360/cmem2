# Tâches Cron — cmem2_API

Snapshot du crontab serveur (`lmdkhdg5@15.235.14.237:27`), relevé le 2026-07-15.

## Tâches actuelles

| Minute | Heure | Jour | Mois | Jour sem. | Commande |
| - | - | - | - | - | - |
| 0 | 2 | * | * | * | `/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/backup/run_all.php >> /home/lmdkhdg5/logs/cron_backup.log 2>&1` |
| * | * | * | * | * | `/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/notifications/send_email_notifications.php >> /home/lmdkhdg5/logs/notifications-$(date +\%Y-\%m-\%d).log 2>&1` |
| 5 | 0 | * | * | * | `find /home/lmdkhdg5/logs/ -name "notifications-*.log" -mtime +2 -delete` |
| 0 | 3 | * | * | * | `/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/maintenance.php >> /home/lmdkhdg5/logs/maintenance-$(date +\%Y-\%m-\%d).log 2>&1` |
| 5 | 3 | * | * | * | `find /home/lmdkhdg5/logs/ -name "maintenance-*.log" -mtime +7 -delete` |
| 10 | 3 | * | * | * | `/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/expire_playstore.php >> /home/lmdkhdg5/logs/cron.log 2>&1` |
| 20 | 3 | * | * | * | `/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/expire_stripe.php >> /home/lmdkhdg5/logs/cron.log 2>&1` |
| * | * | * | * | * | `/usr/local/bin/php /home/lmdkhdg5/dev-cmem2.journauxdebord.com/src/notifications/send_email_notifications.php >> /home/lmdkhdg5/logs/dev-notifications-$(date +\%Y-\%m-\%d).log 2>&1` |

## Notes

- Le lock file (`sys_get_temp_dir() . '/cmem2_maintenance.lock'`) empêche une exécution
  concurrente si les deux créneaux se chevauchaient dans le temps — pas le cas ici (`0 3` et
  `25 3` sont séquentiels), donc les deux tournent bien l'une après l'autre, en double.
- `notifications/send_email_notifications.php` tourne **chaque minute** sur prod et dev — ce
  n'est pas un cron cmem2_API géré via `src/cron/maintenance.php`, à ne pas confondre.
- Référence complète du script maintenance : `src/cron/maintenance.php` (en-tête du fichier).

## Rappels courriel des séries (directive #438)

Depuis le 2026-11-10, `send_email_notifications.php` fait deux passes à chaque minute. La
crontab ne change pas.

1. **Planification** (`planRecurringReminders`) : pour chaque événement récurrent (RRULE/RDATE)
   avec un rappel `EMAIL`, les occurrences sont calculées dans le fuseau de l'événement. Une ligne
   est créée dans `email_notification_queue` pour chaque rappel dont l'heure tombe dans la fenêtre
   glissante (`--horizon`, 48 h par défaut ; marge de retard de 15 min).
   - Occurrence annulée (EXDATE, `DELETE only_this`) : pas de rappel, la ligne en attente est
     retirée.
   - Occurrence déplacée : `fire_at` est recalé sur la nouvelle heure.
   - Idempotence sur (`event_id`, `occurrence_key`, `minutes_before`) : une ligne existante n'est
     jamais recréée, y compris une ligne annulée par l'usager.
2. **Envoi** (`processDueNotifications`) : le courriel reprend la date, l'heure et les
   modifications de l'occurrence visée. Une occurrence annulée entre-temps passe en `cancelled`
   sans envoi.

Un verrou fichier (`/tmp/cmem2_email_notifications_<md5 racine>.lock`, propre à chaque
installation) empêche deux exécutions simultanées.

```bash
php src/notifications/send_email_notifications.php --plan-only --horizon=168   # planifie sans envoyer
php src/notifications/send_email_notifications.php --dry-run                  # liste les envois dus, n'écrit rien
```

## Roulement d'horizon booking (réservation publique)

`src/cron/booking_regenerate.php` — pour chaque `booking_pages.active = 1`, régénère les zones
non réservées futures sur l'horizon configuré (même moteur que `PUT /booking/page`) et resynchronise
contre le calendrier courant de l'hôte. Les zones réservées ne sont jamais touchées.

**Installée sur le crontab prod le 2026-08-14** :

```cron
0 4 * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/booking_regenerate.php >> /home/lmdkhdg5/logs/booking-$(date +\%Y-\%m-\%d).log 2>&1
```

Pas d'entrée sur dev-cmem2 — aucune page de réservation active n'y tourne en continu, la
régénération manuelle (`PUT /booking/page`) suffit pour les tests.

Exécution manuelle (vérification) :

```bash
php src/cron/booking_regenerate.php
```

## Purge des comptes supprimés (Loi 25)

L'effacement physique des comptes au-delà du délai de grâce (`ACCOUNT_PURGE_GRACE_DAYS`, 30 jours)
est une tâche de `maintenance.php` — **aucune entrée crontab supplémentaire n'est requise**.

Le script `src/cron/purge_accounts.php` permet de la déclencher seule, pour vérification ou
exécution manuelle :

```bash
php src/cron/purge_accounts.php --dry-run   # compte sans rien supprimer
php src/cron/purge_accounts.php             # exécution réelle
php src/cron/purge_accounts.php --user=78   # un seul compte (délai de grâce quand même exigé)
php src/cron/purge_accounts.php --json      # rapport machine
```

> Avant la première exécution réelle en production, lancer `--dry-run` : la purge traitera d'un
> seul coup tous les comptes soft-deleted de plus de 30 jours accumulés jusque-là.
