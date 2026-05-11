# CRON — Maintenance centralisée

## Script

`src/cron/maintenance.php`

## Commandes crontab (production — cPanel)

```bash
# Maintenance quotidienne à 03h00
0 3 * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/maintenance.php >> /home/lmdkhdg5/logs/maintenance-$(date +\%Y-\%m-\%d).log 2>&1

# Rotation des logs : garder 7 jours
5 3 * * * find /home/lmdkhdg5/logs/ -name "maintenance-*.log" -mtime +7 -delete
```

## Options CLI

| Option | Effet |
| - | - |
| _(aucune)_ | Exécution complète — modifications appliquées |
| `--dry-run` | Simule sans toucher la base ; rapport envoyé quand même |

## Test manuel (SSH)

```bash
# Dry-run
/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/maintenance.php --dry-run

# Exécution réelle
/usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/maintenance.php
```

## Rapport courriel

Envoyé à `support@journauxdebord.com` **uniquement si des erreurs sont détectées**.

Sujet : `[cmem2 API] Maintenance — YYYY-MM-DD HH:MM:SS — ✗ ERREURS`

En l'absence d'erreur, seul le log fichier est écrit (aucun courriel).

## Protection contre l'exécution simultanée

Lock file : `/tmp/cmem2_maintenance.lock` (`flock` exclusif non-bloquant).
Si une instance tourne déjà, le second appel se termine immédiatement avec exit 1.

## Modules et ordre d'exécution

| # | Module | Tâches principales |
| - | - | - |
| 1 | quiz | Sessions terminées (>90j), sessions orphelines (>7j), quiz archivés (>180j) |
| 2 | puzzle | Événements polling (>7j, par lots 10k), parties archivées (>180j), appareils inactifs |
| 3 | ics | Queue email, verrous CalDAV, sync log (>90j), soft-deletes (>90j), régénération occurrences |
| 4 | items | Items soft-deleted (>90j) |
| 5 | auth_groups | Notifications, stats, invitations, tokens, sessions, abonnements |
| 6 | pomo | Comptage uniquement (aucune suppression) |

## Journalisation

Fichier dédié dans `LOG_DIR` : `maintenance-YYYY-MM-DD.log`

Niveaux utilisés :

- `info` — début/fin de tâche, lignes traitées
- `warning` — volume inhabituel (ex : puzzle_shared_events > 500 000 suppressions)
- `error` — exception attrapée dans une tâche

## Alertes à surveiller

| Indicateur | Seuil | Action |
| - | - | - |
| `puzzle_shared_events` suppressions | > 500 000 | Avertissement dans le rapport |
| Erreurs dans le rapport | ≥ 1 | Sujet courriel `✗ ERREURS` |
| Durée totale | > 120 s | Investiguer la tâche lente |
