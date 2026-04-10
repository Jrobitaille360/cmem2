---
date: 2026-04-10
status: prêt à implémenter
---

# Plan : Système de sauvegarde par module (cron)

## Contexte

`mysqldump` n'est pas disponible sur le serveur. Les sauvegardes sont générées
par des scripts PHP qui lisent les tables via PDO et génèrent des fichiers SQL
(`INSERT` statements). Chaque script prend le répertoire de destination en
paramètre et écrit une ligne dans un log.

Chaque script de sauvegarde effectue un **ménage pré-backup** de ses tables
(suppression des données obsolètes) avant d'exporter les données utiles.

---

## Décisions

| # | Sujet | Décision |
| - | ----- | -------- |
| 1 | Format de sortie | SQL (`INSERT`) |
| 2 | Répertoire par défaut | `BACKUP_DIR` du `.env` si `argv[1]` absent |
| 3 | Chunking | Activé par défaut, `CHUNK_SIZE = 5000` lignes |
| 4 | Uploads | Incrémental quotidien + complet aux 3 mois |
| 5 | Accès | CLI uniquement |
| 6 | Seuils de ménage | À valider (voir tableaux ménage pré-backup) |

> **Chunking** = découpage d'une grande table en plusieurs fichiers SQL pour
> éviter les dépassements de mémoire ou les timeouts. Exemple : une table de
> 50 000 lignes est exportée en 10 fichiers de 5 000 lignes chacun, nommés
> `cmem2_core_20260409_part01.sql`, `part02.sql`, etc.

---

## Répertoire cible des scripts

```text
src/cron/backup/
├── backup_core.php          ← auth_groups (plans → users → ... → platform_stats)
├── backup_ics.php           ← module ICS / CalDAV
├── backup_pomo.php          ← module Pomodoro
├── backup_quiz.php          ← module Quiz (Kayoot)
├── backup_puzzle.php        ← module Puzzle
├── backup_uploads.php       ← fichiers uploads/ (incrémental + complet)
├── cleanup_logs.php         ← supprime les logs > 28 jours
├── cleanup_backups.php      ← supprime les fichiers de backup > 28 jours
└── run_all.php              ← orchestrateur : appelle tous les scripts dans l'ordre
```

Script ICS existant, appelé par `run_all.php` avant `backup_ics.php` :

```text
src/ics/maintenance_occurrences.php  ← régénère les occurrences récurrentes (jusqu'en 2099)
```

---

## Format de chaque script de sauvegarde

### Paramètre d'entrée

```bash
php backup_core.php /chemin/vers/backup/
```

- `argv[1]` = répertoire de destination (optionnel — utilise `BACKUP_DIR` du `.env` par défaut)

### Fichiers générés (base de données)

```text
{dest_dir}/cmem2_core_YYYYMMDD_HHMMSS.sql
{dest_dir}/cmem2_ics_YYYYMMDD_HHMMSS.sql
{dest_dir}/cmem2_pomo_YYYYMMDD_HHMMSS.sql
{dest_dir}/cmem2_quiz_YYYYMMDD_HHMMSS.sql
{dest_dir}/cmem2_puzzle_YYYYMMDD_HHMMSS.sql
```

### Fichiers générés (uploads)

```text
{dest_dir}/uploads_incr_YYYYMMDD.tar.gz   ← nouveaux fichiers depuis dernier backup
{dest_dir}/uploads_full_YYYYMM.tar.gz     ← archive complète (1 fois / 3 mois)
```

### Sortie (log)

Une seule ligne `echo` en fin de script, redirigée vers le log cron :

```text
[2026-04-09 02:01:14] backup_core OK | 23 tables | 4 182 lignes | 312 Ko | 2.3s
[2026-04-09 02:01:14] backup_core ERREUR | users : SQLSTATE[...] message
```

```bash
php backup_core.php >> /logs/cron_backup.log 2>&1
```

---

## Contenu de chaque fichier SQL généré

```sql
-- cmem2 backup | module: core | date: 2026-04-09 02:01:14
-- Tables: 23 | Lignes: 4 182

SET FOREIGN_KEY_CHECKS = 0;

-- TABLE: plans
TRUNCATE TABLE `plans`;
INSERT INTO `plans` (...) VALUES (...), (...);

-- TABLE: users
TRUNCATE TABLE `users`;
INSERT INTO `users` (...) VALUES (...), (...);

-- ...

SET FOREIGN_KEY_CHECKS = 1;
```

> `TRUNCATE` + `INSERT` permet un restore propre sans doublon.
> `FOREIGN_KEY_CHECKS = 0` permet de restaurer dans n'importe quel ordre si besoin.

---

## Ménage pré-backup par module

Chaque script `backup_*.php` commence par supprimer les données obsolètes
avant d'exporter. La ligne de log inclut le nombre de lignes supprimées.

### backup_core.php — ménage

| Table | Critère de suppression |
| --- | --- |
| `jwt_blacklist` | `expires_at < NOW()` |
| `login_attempts` | `created_at < NOW() - INTERVAL 30 DAY` |
| `otp_codes` | `expires_at < NOW()` |
| `user_sessions` | `expires_at < NOW()` |
| `password_resets` | `expires_at < NOW()` |
| `email_verifications` | `expires_at < NOW()` |
| `notifications` | `created_at < NOW() - INTERVAL 90 DAY` et `read = 1` |

### backup_ics.php — ménage

| Table | Critère de suppression |
| --- | --- |
| `event_occurrences` | `start_datetime < NOW() - INTERVAL 90 DAY` (passées) |
| `caldav_sync_log` | `changed_at < NOW() - INTERVAL 30 DAY` |
| `caldav_locks` | `expires_at < NOW()` |
| `email_notification_queue` | `status IN ('sent','failed')` et `created_at < NOW() - INTERVAL 7 DAY` |

> Avant le ménage et l'export, `run_all.php` appelle
> `maintenance_occurrences.php` pour régénérer les occurrences récurrentes
> à jour.

### backup_quiz.php — ménage

| Table | Critère de suppression |
| --- | --- |
| `quiz_sessions` | `ended_at < NOW() - INTERVAL 90 DAY` |
| `quiz_participants` | cascade depuis `quiz_sessions` supprimées |
| `quiz_participant_answers` | cascade depuis `quiz_participants` supprimés |

### backup_puzzle.php — ménage

| Table | Critère de suppression |
| --- | --- |
| `puzzle_shared_events` | `created_at < NOW() - INTERVAL 30 DAY` |
| `puzzle_shared` | `expires_at < NOW()` |
| `puzzle_shared_pieces` | cascade depuis `puzzle_shared` supprimées |

### backup_pomo.php — ménage

Aucun ménage prévu pour `pomo_engagements` — toutes les données sont utiles
pour les statistiques à long terme.

---

## Sauvegarde des uploads

Le répertoire `uploads/` contient avatars, documents, images puzzle et cache ICS.
Deux modes complémentaires :

### Incrémental (quotidien)

- Copie uniquement les fichiers dont `filemtime` > date du dernier backup incrémental
- Marqueur de date stocké dans un fichier `uploads_last_incr.txt` dans `BACKUP_DIR`
- Archive : `uploads_incr_YYYYMMDD.tar.gz`
- Rétention : supprimés lors du prochain backup complet (voir ci-dessous)

### Complet (tous les 3 mois)

- Archive complète de `uploads/`
- Archive : `uploads_full_YYYYMM.tar.gz`
- Déclenchement : si aucun `uploads_full_*.tar.gz` de moins de 90 jours dans `BACKUP_DIR`
- À la création d'un complet : supprime tous les `uploads_incr_*.tar.gz` existants
- Rétention du complet : 28 jours (géré par `cleanup_backups.php`)

---

## Ordre de sauvegarde — respect des clés étrangères

### backup_core.php (23 tables)

| #  | Table                  | Dépend de     |
| -- | ---------------------- | ------------- |
| 1  | `plans`                | —             |
| 2  | `users`                | plans         |
| 3  | `tags`                 | users         |
| 4  | `groups`               | users         |
| 5  | `files`                | users         |
| 6  | `user_sessions`        | users         |
| 7  | `user_app_setup`       | users         |
| 8  | `password_resets`      | users         |
| 9  | `email_verifications`  | users         |
| 10 | `notifications`        | users         |
| 11 | `otp_codes`            | —             |
| 12 | `device_tokens`        | —             |
| 13 | `jwt_blacklist`        | users         |
| 14 | `login_attempts`       | —             |
| 15 | `group_members`        | groups, users |
| 16 | `group_invitations`    | groups, users |
| 17 | `group_tag_relations`  | groups, tags  |
| 18 | `file_tag_relations`   | files, tags   |
| 19 | `plan_invitations`     | users         |
| 20 | `subscriptions`        | users         |
| 21 | `group_stats_snapshot` | groups        |
| 22 | `user_stats_snapshot`  | users         |
| 23 | `platform_stats`       | —             |

> VUEs (`active_user_sessions`, `user_sessions_stats`, `v_admin_dashboard`) : exclues —
> elles sont recréées par `build_cmem2_DB.sql`.

### backup_ics.php (9 tables)

| # | Table                      | Dépend de                         |
| - | -------------------------- | --------------------------------- |
| 1 | `calendars`                | users (core)                      |
| 2 | `calendar_events`          | calendars                         |
| 3 | `calendar_shares`          | calendars, users                  |
| 4 | `calendar_todos`           | calendars                         |
| 5 | `calendar_journals`        | calendars                         |
| 6 | `event_occurrences`        | calendar_events, calendars        |
| 7 | `caldav_sync_log`          | calendars, calendar_events, users |
| 8 | `caldav_locks`             | calendars, calendar_events        |
| 9 | `email_notification_queue` | —                                 |

### backup_quiz.php (6 tables)

| # | Table                      |
| - | -------------------------- |
| 1 | `quiz_quizzes`             |
| 2 | `quiz_questions`           |
| 3 | `quiz_choices`             |
| 4 | `quiz_sessions`            |
| 5 | `quiz_participants`        |
| 6 | `quiz_participant_answers` |

### backup_puzzle.php (9 tables)

| # | Table                       |
| - | --------------------------- |
| 1 | `puzzle_images`             |
| 2 | `puzzle_image_translations` |
| 3 | `puzzle_themes`             |
| 4 | `puzzle_theme_translations` |
| 5 | `puzzle_image_themes`       |
| 6 | `puzzle_devices`            |
| 7 | `puzzle_shared`             |
| 8 | `puzzle_shared_pieces`      |
| 9 | `puzzle_shared_events`      |

### backup_pomo.php (1+ table)

| #  | Table                                                          |
| -- | -------------------------------------------------------------- |
| 1  | `pomo_engagements`                                             |
| 2+ | (tables futures : pomo_support, pomo_sync, pomo_subscriptions) |

---

## Scripts de ménage

### cleanup_logs.php

- Paramètre : répertoire des logs (argv[1], sinon `LOG_DIR` du `.env`)
- Supprime tout fichier `*.log` dont le `filemtime` > 28 jours
- Echo : `[date] cleanup_logs OK | 3 fichiers supprimés | 1.2 Mo libérés`

### cleanup_backups.php

- Paramètre : répertoire des backups (argv[1], sinon `BACKUP_DIR` du `.env`)
- Supprime tout fichier `*.sql`, `*.tar.gz` dont le `filemtime` > 28 jours
- Ne supprime PAS le dernier `uploads_full_*.tar.gz` s'il est le seul
- Echo : `[date] cleanup_backups OK | 5 fichiers supprimés | 45 Mo libérés`

---

## Orchestrateur run_all.php

Appelle les scripts dans l'ordre avec `proc_open` ou `shell_exec` :

```text
1. maintenance_occurrences.php  (src/ics/ — régénère les occurrences ICS)
2. backup_core.php
3. backup_ics.php
4. backup_pomo.php
5. backup_quiz.php
6. backup_puzzle.php
7. backup_uploads.php
8. cleanup_logs.php
9. cleanup_backups.php
```

Retourne un résumé sur une seule ligne :

```text
[2026-04-09 02:05:00] run_all DONE | 5 modules OK | 0 erreur | 52 tables | 8 241 lignes | 1.1 Mo | 12.4s
```

---

## Planification cron (exemple)

```cron
# Backup quotidien à 02h00
0 2 * * * php /var/www/cmem2_API/src/cron/backup/run_all.php >> /var/www/cmem2_API/logs/cron_backup.log 2>&1
```

Ou scripts individuels si le temps d'exécution dépasse 30 s :

```cron
0 2 * * *  php .../src/ics/maintenance_occurrences.php >> .../cron_backup.log 2>&1
5 2 * * *  php .../backup_core.php    >> .../cron_backup.log 2>&1
10 2 * * * php .../backup_ics.php     >> .../cron_backup.log 2>&1
15 2 * * * php .../backup_quiz.php    >> .../cron_backup.log 2>&1
20 2 * * * php .../backup_puzzle.php  >> .../cron_backup.log 2>&1
25 2 * * * php .../backup_pomo.php    >> .../cron_backup.log 2>&1
30 2 * * * php .../backup_uploads.php >> .../cron_backup.log 2>&1
35 2 * * * php .../cleanup_logs.php   >> .../cron_backup.log 2>&1
40 2 * * * php .../cleanup_backups.php >> .../cron_backup.log 2>&1
```

---

## Chunking (découpage des grandes tables)

Activé par défaut pour toutes les tables. Chaque table est exportée par
tranches de `CHUNK_SIZE` lignes via `SELECT ... LIMIT 5000 OFFSET n`.

- Si la table tient en une seule tranche → un seul fichier (pas de suffixe `_part`)
- Si la table dépasse `CHUNK_SIZE` → fichiers `cmem2_core_YYYYMMDD_part01.sql`, `part02.sql`, ...
- Le script boucle automatiquement jusqu'à épuisement des lignes

Constante dans chaque script : `CHUNK_SIZE = 5000` (lignes par fichier).

---

## Rétention des fichiers

| Type | Durée | Script de ménage |
| --- | --- | --- |
| Fichiers `.sql` backup | 28 jours | `cleanup_backups.php` |
| Fichiers `.log` | 28 jours | `cleanup_logs.php` |
| Archives `uploads_incr_*.tar.gz` | jusqu'au prochain complet | `backup_uploads.php` |
| Archives `uploads_full_*.tar.gz` | 28 jours (min. 1 conservé) | `cleanup_backups.php` |

---

## Rapatriement automatique sur PC personnel (Windows Task Scheduler + SFTP)

### Vue d'ensemble

Le serveur génère les fichiers dans `BACKUP_DIR` chaque nuit à 02h00.
Un script PowerShell tourne sur le PC personnel à 03h00 et télécharge
les nouveaux fichiers via SFTP (WinSCP).

```text
02h00  Serveur   → run_all.php génère les backups dans BACKUP_DIR
03h00  PC local  → Task Scheduler lance fetch_backups.ps1
                 → WinSCP (SFTP) télécharge les nouveaux fichiers
                 → Stockage local : C:\backups\cmem2\
```

### Prérequis — WinSCP

PowerShell n'a pas de client SFTP natif. Installer **WinSCP** (gratuit) :

- Télécharger : <https://winscp.net/eng/download.php>
- L'installateur place `WinSCPnet.dll` dans `C:\Program Files (x86)\WinSCP\`
- Ajuster `$WinScpDll` dans le script si installé ailleurs

### Script PowerShell — fetch_backups.ps1

Le script est dans `private/utilitaires/fetch_backups.ps1`.
Copier le fichier sur le PC personnel, par exemple dans `C:\scripts\fetch_backups.ps1`.

### Configuration Windows Task Scheduler

1. Ouvrir **Task Scheduler** (`taskschd.msc`)
2. **Create Basic Task**
   - Name : `cmem2 backup fetch`
   - Trigger : Daily, 03:00
3. **Action** : Start a program

```text
Program : powershell.exe
Arguments : -NonInteractive -ExecutionPolicy Bypass -File "C:\scripts\fetch_backups.ps1"
```

- Cocher **Run whether user is logged on or not**
- Cocher **Run with highest privileges**

### Paramètres SFTP à renseigner dans le script

| Paramètre | Valeur |
| --- | --- |
| `$SftpHost` | IP ou nom de domaine du serveur |
| `$SftpUser` | Utilisateur SSH/SFTP |
| `$SftpPass` | Mot de passe SSH/SFTP |
| `$SftpDir` | Chemin du répertoire `BACKUP_DIR` sur le serveur |
| `$LocalDir` | Répertoire local de destination |
| `$WinScpDll` | Chemin vers `WinSCPnet.dll` (défaut : `C:\Program Files (x86)\WinSCP\`) |

> `GiveUpSecurityAndAcceptAnySshHostKey` est activé par défaut pour simplifier
> la première connexion. Pour plus de sécurité, remplacer par `SshHostKeyFingerprint`
> avec l'empreinte du serveur (visible dans WinSCP au premier login).

### Rétention locale

Appliquée automatiquement après chaque téléchargement :

| Type | Règle |
| --- | --- |
| `cmem2_*.sql` | 1 seul par module (le plus récent) — les anciens sont supprimés |
| `uploads_full_*.tar.gz` | 1 seul conservé (le plus récent) |
| `uploads_incr_*.tar.gz` | Tous conservés jusqu'à l'arrivée d'un nouveau `uploads_full` |
| Nouveau `uploads_full` reçu | Tous les `uploads_incr_*` sont supprimés |

> Les incrémentiels sont conservés tant qu'aucun backup complet n'est arrivé
> car ils sont tous nécessaires pour reconstituer l'état complet des uploads.

---

## Livrables à créer

- [x] `src/cron/backup/_bootstrap.php`
- [x] `src/cron/backup/_export.php`
- [x] `src/cron/backup/backup_core.php`
- [x] `src/cron/backup/backup_ics.php`
- [x] `src/cron/backup/backup_pomo.php`
- [x] `src/cron/backup/backup_quiz.php`
- [x] `src/cron/backup/backup_puzzle.php`
- [x] `src/cron/backup/backup_uploads.php`
- [x] `src/cron/backup/cleanup_logs.php`
- [x] `src/cron/backup/cleanup_backups.php`
- [x] `src/cron/backup/run_all.php`
- [ ] `docs/cron/GUIDE_backup_system.md`
