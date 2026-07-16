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
