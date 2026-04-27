# Plan — Système de maintenance centralisée (CRON)

## Vue d'ensemble

Créer un fichier d'entrée `private/maintenance.php` exécuté quotidiennement via CRON.
Il orchestre les tâches de nettoyage de chaque module, dans l'ordre des clés étrangères,
collecte les statistiques, journalise les résultats et envoie un courriel de rapport à
`support@journauxdebord.com`.

---

## 1. Architecture cible

### Ce qui est déjà en place

- `src/ics/maintenance_occurrences.php` — maintenance des occurrences ICS (standalone)
- `src/notifications/send_email_notifications.php` — envoi des notifications email (standalone)
- `AuthGroups\Services\LogService` — journalisation avec rotation automatique
- `AuthGroups\Services\EmailService` — envoi SMTP via PHPMailer
- `AuthGroups\Models\JwtBlacklist::deleteExpired()` — nettoyage JWT
- `AuthGroups\Services\OtpService::cleanup()` — nettoyage OTP
- `AuthGroups\Services\RateLimitService::deleteExpired()` — nettoyage login_attempts
- Procédures stockées : `CleanupExpiredSessions()`, `CleanupOldStats()`, `GeneratePlatformStats()`, etc.

### Structure cible

```text
private/
  maintenance.php                          ← entrée CRON

src/
  Core/
    Maintenance/
      MaintenanceOrchestrator.php          ← coordonne toutes les tâches
      MaintenanceReport.php                ← collecte stats + envoie courriel
      MaintenanceTaskInterface.php         ← contrat commun
  auth_groups/
    Services/
      MaintenanceService.php              ← tâches auth_groups
  ics/
    Services/
      MaintenanceService.php              ← tâches ICS (wrap OccurrenceMaintenanceService)
  items/
    Services/
      MaintenanceService.php              ← tâches items
  pomo/
    Services/
      MaintenanceService.php              ← tâches pomo (aucune, rapport seulement)
  puzzle/
    Services/
      MaintenanceService.php              ← tâches puzzle
  quiz/
    Services/
      MaintenanceService.php              ← tâches quiz
```

### Améliorations à apporter

- Créer `MaintenanceTaskInterface` avec méthodes `run(): array` (retourne stats) et `getName(): string`
- Chaque `MaintenanceService` implémente l'interface
- `MaintenanceOrchestrator` exécute les tâches dans l'ordre FK-safe et agrège les résultats
- `MaintenanceReport` formate le courriel HTML et appelle `EmailService`
- `private/maintenance.php` : bootstrap minimal (loader + autoloaders des modules actifs), puis appel orchestrateur
- Log de chaque opération via `LogService`; log séparé `maintenance-YYYY-MM-DD.log`

### Maintenances à prévoir

- Ajuster les seuils de rétention (INTERVAL 90 DAY, etc.) selon l'évolution du volume
- Ajouter un index sur `created_at` / `expires_at` / `fire_at` si non présents
- Surveiller la taille de `puzzle_shared_events` (table à très haute vélocité)

---

## 2. Ordre d'exécution et tâches par module

> L'ordre respecte les contraintes de clés étrangères : on supprime les enfants avant les parents.

### Phase d'exécution

| # | Module | Tâches principales |
| - | - | - |
| 1 | quiz | Réponses → participants → sessions expirées → quiz archivés |
| 2 | puzzle | Événements de polling → pièces → parties archivées → appareils inactifs |
| 3 | ics | Queue de notifications → occurrences orphelines → verrous CalDAV → logs sync → événements/todos/journaux/calendriers supprimés |
| 4 | items | Purge des items en soft-delete anciens |
| 5 | auth_groups | Notifications lues → snapshots stats anciens → invitations expirées → tentatives de connexion → OTP → JWT blacklist → device tokens → vérifications email → réinitialisations → sessions → abonnements expirés |
| 6 | pomo | Aucun nettoyage — compte des enregistrements pour le rapport |

### Détail des tâches — quiz

| Table | Condition | Action |
| - | - | - |
| `quiz_participant_answers` | `session_id` IN sessions à supprimer | DELETE (cascade) |
| `quiz_participants` | `session_id` IN sessions à supprimer | DELETE (cascade) |
| `quiz_sessions` | `ended_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `quiz_quizzes` | `status = 'archived'` AND `updated_at < NOW() - INTERVAL 180 DAY` | DELETE |

### Détail des tâches — puzzle

| Table | Condition | Action |
| - | - | - |
| `puzzle_shared_events` | `created_at < NOW() - INTERVAL 7 DAY` | DELETE |
| `puzzle_shared_events` | `shared_id` dans parties archivées | DELETE |
| `puzzle_shared_pieces` | `shared_id` dans parties archivées | DELETE |
| `puzzle_shared` | `status = 'archived'` AND `last_activity_at < NOW() - INTERVAL 180 DAY` | DELETE |
| `puzzle_devices` | `token_expires_at < NOW()` AND `last_seen_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `puzzle_devices` | `last_seen_at < NOW() - INTERVAL 365 DAY` | DELETE |

### Détail des tâches — ics

| Table | Condition | Action |
| - | - | - |
| `email_notification_queue` | `status IN ('failed','cancelled')` AND `updated_at < NOW() - INTERVAL 7 DAY` | DELETE |
| `email_notification_queue` | `status = 'pending'` AND `fire_at < NOW() - INTERVAL 24 HOUR` | DELETE |
| `event_occurrences` | `event_id` NOT IN events actifs | DELETE |
| `caldav_locks` | `expires_at < NOW()` | DELETE |
| `caldav_sync_log` | `changed_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `calendar_events` | `deleted_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `calendar_todos` | `deleted_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `calendar_journals` | `deleted_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `calendar_shares` | `deleted_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `calendars` | `deleted_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `event_occurrences` (régénération) | événements récurrents modifiés | REGENERATE (OccurrenceMaintenanceService) |

### Détail des tâches — items

| Table | Condition | Action |
| - | - | - |
| `item_user_access` | `item_id` IN items à purger | DELETE (cascade) |
| `items` | `deleted_at < NOW() - INTERVAL 90 DAY` | DELETE |

### Détail des tâches — auth_groups

| Table | Condition | Action |
| - | - | - |
| `notifications` | `is_read = 1` AND `read_at < NOW() - INTERVAL 30 DAY` | DELETE |
| `notifications` | `created_at < NOW() - INTERVAL 90 DAY` | DELETE |
| `group_stats_snapshot` | `generated_at < NOW() - INTERVAL 30 DAY` | DELETE |
| `user_stats_snapshot` | `generated_at < NOW() - INTERVAL 30 DAY` | DELETE |
| `platform_stats` | garder les 100 derniers | DELETE (anciens) |
| `group_invitations` | `status = 'pending'` AND `expires_at < NOW()` | UPDATE status = 'expired' |
| `login_attempts` | `created_at < NOW() - INTERVAL 24 HOUR` | DELETE |
| `otp_codes` | `expires_at < NOW()` | DELETE |
| `jwt_blacklist` | `expires_at < NOW()` | DELETE |
| `device_tokens` | `expires_at < NOW()` | DELETE |
| `email_verifications` | `expires_at < NOW()` | DELETE |
| `password_resets` | `expires_at < NOW()` | DELETE |
| `user_sessions` | `expires_at < NOW()` AND `is_active = 1` | UPDATE is_active = 0 |
| `user_sessions` | `is_active = 0` AND `login_at < NOW() - INTERVAL 30 DAY` | DELETE |
| `subscriptions` | `status = 'active'` AND `expires_at < NOW()` | UPDATE status = 'expired' |

### Détail des tâches — pomo

- Aucune suppression.
- Compter `pomo_engagements` total → inclure dans le rapport.

---

## 3. Rapport et journalisation

### Ce qui est déjà en place

- `LogService` : singleton, 5 niveaux, rotation quotidienne, archivage ZIP
- `EmailService` : PHPMailer SMTP, mode dev, `sendEmail($to, $subject, $body, $isHtml)`

### Format du rapport courriel

```
Objet : [cmem2 API] Maintenance — 2026-04-23 02:00 — ✓ OK / ✗ ERREURS
```

Corps HTML :

- Tableau par module : lignes supprimées / mises à jour par table
- Durée d'exécution totale
- Liste des erreurs (le cas échéant)
- Taille estimée des tables critiques (`puzzle_shared_events`, `caldav_sync_log`)

### Journalisation

- Fichier dédié : `logs/maintenance-YYYY-MM-DD.log`
- Niveau `info` pour chaque opération réussie
- Niveau `warning` pour les opérations avec 0 résultats attendus > 0
- Niveau `error` pour toute exception attrapée
- Résumé final en niveau `info`

---

## 4. Phases d'implantation

### Phase 1 — Infrastructure (priorité : critique)

**Actions**

1. Créer `src/Core/Maintenance/MaintenanceTaskInterface.php`
2. Créer `src/Core/Maintenance/MaintenanceReport.php`
3. Créer `src/Core/Maintenance/MaintenanceOrchestrator.php`
4. Créer `private/maintenance.php` (bootstrap + appel orchestrateur)

**Enjeux**

- Le bootstrap doit charger uniquement les modules activés dans `.env` (mêmes plugins que `loader.php`)
- La définition de `RUNNING_AS_CRON` doit bloquer l'accès HTTP (identique à `send_email_notifications.php`)
- Gérer les exceptions par tâche sans bloquer les suivantes (try/catch par module)

**Tests**

- Exécuter `php private/maintenance.php` en CLI → sortie sans erreur, courriel reçu
- Vérifier l'entrée dans `logs/maintenance-*.log`
- Tester avec un module en erreur → les autres continuent, le rapport signale l'erreur

**Conditions de fin de phase**

- `private/maintenance.php` s'exécute sans erreur fatale en CLI
- Le courriel de rapport arrive à `support@journauxdebord.com`
- Le log de maintenance est créé

---

### Phase 2 — Tâches quiz et puzzle (priorité : haute — tables à forte croissance)

**Actions**

1. Créer `src/quiz/Services/MaintenanceService.php`
2. Créer `src/puzzle/Services/MaintenanceService.php`
3. Enregistrer dans `MaintenanceOrchestrator`

**Enjeux**

- `puzzle_shared_events` peut contenir des millions de lignes : utiliser `DELETE … LIMIT 10000` en boucle pour éviter un lock de table prolongé
- Vérifier que les FK `ON DELETE CASCADE` sont bien définis avant de supprimer les parents (`puzzle_shared`, `quiz_sessions`)

**Tests**

- Insérer des enregistrements de test datés dans le passé, vérifier qu'ils sont supprimés
- Vérifier que les enregistrements récents ne sont pas touchés
- Mesurer le temps d'exécution avec un volume représentatif

**Conditions de fin de phase**

- Les tables quiz et puzzle sont nettoyées correctement
- Aucune violation de FK
- Temps d'exécution < 30 s pour un volume standard

---

### Phase 3 — Tâches ICS (priorité : haute)

**Actions**

1. Créer `src/ics/Services/MaintenanceService.php`
2. Intégrer l'appel à `OccurrenceMaintenanceService::performMaintenance()` (existant)
3. Ajouter le nettoyage `email_notification_queue`, `caldav_locks`, `caldav_sync_log`, soft-deletes

**Enjeux**

- Ne pas supprimer les entrées `email_notification_queue` en statut `pending` récentes (< 24 h)
- L'ordre exact : queue → occurrences orphelines → locks → sync log → events/todos/journals/shares → calendars
- La régénération des occurrences peut être longue (mode incrémental par défaut)

**Tests**

- Vérifier que `OccurrenceMaintenanceService` est bien appelé et ses stats incluses dans le rapport
- Créer un calendrier en soft-delete > 90 jours → vérifier suppression complète en cascade
- Vérifier qu'un calendrier en soft-delete < 90 jours n'est pas touché

**Conditions de fin de phase**

- Toutes les tâches ICS s'exécutent sans erreur
- Les occurrences orphelines sont supprimées
- Les verrous CalDAV expirés sont nettoyés

---

### Phase 4 — Tâches items et auth_groups (priorité : normale)

**Actions**

1. Créer `src/items/Services/MaintenanceService.php`
2. Créer `src/auth_groups/Services/MaintenanceService.php` (wraps méthodes existantes + nouvelles tâches)
3. Intégrer `JwtBlacklist::deleteExpired()`, `OtpService::cleanup()`, `RateLimitService::deleteExpired()` déjà existants
4. Ajouter : sessions, abonnements, invitations, notifications, snapshots stats, soft-deletes users

**Enjeux**

- Les abonnements expirés doivent passer à `status = 'expired'` (UPDATE), pas DELETE
- Les invitations expirées idem (audit trail conservé)
- Ne pas supprimer `user_sessions` actives récentes même si `expires_at` est passé (grace period de 5 min)

**Tests**

- Vérifier qu'un abonnement expiré change de statut mais n'est pas supprimé
- Vérifier que les sessions actives récentes ne sont pas touchées
- Vérifier que les OTP / JWT / rate limits sont bien purgés

**Conditions de fin de phase**

- Toutes les tâches auth_groups s'exécutent sans erreur
- Aucun abonnement ou invitation actif n'est modifié par erreur

---

### Phase 5 — Tâches pomo et rapport final (priorité : normale)

**Actions**

1. Créer `src/pomo/Services/MaintenanceService.php` (compte uniquement)
2. Finaliser `MaintenanceReport` : tableau HTML complet, seuils d'alerte, durée
3. Tester le courriel sur les deux environnements (dev → log fichier, prod → SMTP)

**Enjeux**

- En mode dev (`APP_ENV=development`), ne pas envoyer le courriel mais écrire dans le log
- Inclure les alertes si `puzzle_shared_events` > 5 000 000 lignes ou si des erreurs ont été levées

**Tests**

- Vérifier le rendu HTML du courriel (tableaux, couleurs d'alerte)
- Tester en mode dry-run (argument `--dry-run` : logguer sans modifier les données)
- Vérifier que le sujet du courriel reflète le statut (OK vs ERREURS)

**Conditions de fin de phase**

- Courriel reçu à `support@journauxdebord.com` avec toutes les statistiques
- Mode dry-run fonctionnel
- Entrée CRON documentée dans les fichiers de production

---

### Phase 6 — Configuration CRON production (priorité : normale)

**Actions**

1. Documenter la commande CRON dans `docs/cron/`
2. Ajouter la rotation du log `maintenance-*.log` (garder 7 jours)
3. Tester en production (SSH + manuel)

**Enjeux**

- Utiliser `/usr/local/bin/php` (binaire CLI de cPanel, comme `send_email_notifications.php`)
- S'assurer que le script ne tourne pas plus d'une instance à la fois (lock file ou `flock`)

**Tests**

- Exécuter manuellement en SSH sur le serveur de prod
- Vérifier le log sur le serveur
- Vérifier la réception du courriel

**Conditions de fin de phase**

- CRON actif, exécution quotidienne confirmée
- Aucune instance parallèle possible
- Log de rotation en place

---

## 5. Commandes CRON recommandées

```bash
# Maintenance quotidienne à 02h00
0 2 * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/private/maintenance.php >> /home/lmdkhdg5/logs/maintenance-$(date +\%Y-\%m-\%d).log 2>&1

# Rotation des logs de maintenance (garder 7 jours)
5 2 * * * find /home/lmdkhdg5/logs/ -name "maintenance-*.log" -mtime +7 -delete
```
