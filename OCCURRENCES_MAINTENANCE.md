# Gestion des Accès Hors Fenêtre

## Problématique

Par défaut, le système ne pré-calcule que les occurrences dans la fenêtre glissante (-6 mois à +1 an). Si un utilisateur demande des occurrences en dehors de cette fenêtre, il n'obtient aucun résultat.

## Solution Implémentée

### Génération à la Demande

Le système détecte automatiquement quand une requête porte sur une plage hors fenêtre et génère les occurrences à la demande :

1. **Détection** : Vérifie si la plage demandée chevauche la fenêtre glissante
2. **Génération** : Calcule les occurrences pour tous les événements récurrents du calendrier
3. **Stockage** : Enregistre les occurrences générées pour les futures requêtes
4. **Limitation** : Restreint à 1 an maximum pour éviter les abus

### Avantages

- ✅ **Transparence** : L'utilisateur voit toutes les occurrences pertinentes
- ✅ **Performance** : Les occurrences générées sont mises en cache
- ✅ **Sécurité** : Limitation à 1 an pour éviter les calculs excessifs
- ✅ **Compatibilité** : Fonctionne avec tous les types d'événements récurrents

### Exemple d'Usage

```php
// Requête pour des occurrences dans 3 ans
GET /calendars/{id}/events/occurrences?start_date=2028-01-01&end_date=2028-12-31

// Le système va :
// 1. Constater que cette plage est hors fenêtre
// 2. Générer les occurrences pour tous les événements récurrents
// 3. Retourner les résultats
// 4. Les stocker pour les futures requêtes identiques
```

## Limites et Protections

### Restrictions Implémentées

- **Plage maximale** : 1 an pour éviter les calculs trop importants
- **Nombre d'occurrences** : Limité à 1000 par génération
- **Fréquence** : Pas de limitation par utilisateur (pour l'instant)

### Recommandations

Pour les cas d'usage nécessitant des plages très étendues :

1. **Augmenter la fenêtre glissante** dans `OccurrenceMaintenanceService`
2. **Pré-générer** les occurrences pour les calendriers fréquemment consultés
3. **Ajouter une pagination** côté client pour les grandes plages

## Configuration

### Paramètres Modifiables

```php
// Dans src/ics/config/ics_config.php
define('ICS_OCCURRENCES_WINDOW_PAST_MONTHS', $_ENV['ICS_OCCURRENCES_WINDOW_PAST_MONTHS'] ?? 6);     // Mois dans le passé
define('ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS', $_ENV['ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS'] ?? 12); // Mois dans le futur (1 an)
define('ICS_OCCURRENCES_MAX_RANGE_DAYS', $_ENV['ICS_OCCURRENCES_MAX_RANGE_DAYS'] ?? 365);         // Jours maximum pour génération à la demande
define('ICS_OCCURRENCES_MAX_PER_GENERATION', $_ENV['ICS_OCCURRENCES_MAX_PER_GENERATION'] ?? 1000); // Occurrences max par génération
```

### Monitoring

Les générations à la demande sont tracées dans les logs :

```bash
INFO: Occurrences générées à la demande hors fenêtre
- calendar_id: 123
- generated_count: 45
- start_date: 2028-01-01
- end_date: 2028-12-31
```

## Cas d'Usage Supportés

- ✅ Événements quotidiens, hebdomadaires, mensuels
- ✅ Règles RRULE complexes (BYDAY, BYMONTH, etc.)
- ✅ Événements multi-jours
- ✅ Exceptions et modifications
- ✅ Plages passées et futures

## Performance

### Métriques Attendues

- **Génération initiale** : 100-500ms pour 1 an d'occurrences
- **Cache hit** : < 10ms pour les requêtes suivantes
- **Stockage** : ~50-200 octets par occurrence

### Optimisations

- **Batch insert** pour l'enregistrement des occurrences
- **Indexation** automatique sur calendar_id et dates
- **Cache applicatif** pour éviter les recalculs

Le système de pré-calcul des occurrences utilise une **fenêtre glissante** de -6 mois à +1 an pour optimiser les performances des événements récurrents. La fenêtre se déplace automatiquement avec le temps.

## Fenêtre Glissante

- **Début** : 6 mois dans le passé (par rapport à aujourd'hui)
- **Fin** : 1 an dans le futur (12 mois)
- **Maintenance** : Exécution quotidienne pour maintenir la fenêtre à jour

### Exemple de déplacement de fenêtre

Aujourd'hui (26 novembre 2025) :

- Fenêtre : `2025-05-26` → `2026-11-26`

Dans 1 mois (26 décembre 2025) :

- Fenêtre : `2025-06-26` → `2026-12-26`
- Les occurrences avant le 26 juin 2025 seront supprimées
- De nouvelles occurrences jusqu'au 26 décembre 2026 seront générées

## Script de Maintenance

Le script `src/ics/maintenance_occurrences.php` gère automatiquement :

1. **Nettoyage** : Supprime les occurrences hors de la fenêtre
2. **Régénération** : Génère les nouvelles occurrences dans la fenêtre étendue
3. **Validation** : Vérifie l'intégrité des données

### Commandes disponibles

```bash
# Maintenance complète (nettoyage + régénération)
php src/ics/maintenance_occurrences.php

# Nettoyage uniquement (sans régénération)
php src/ics/maintenance_occurrences.php --cleanup-only

# Afficher les statistiques
php src/ics/maintenance_occurrences.php --stats

# Vérifier si maintenance nécessaire
php src/ics/maintenance_occurrences.php --check
```

### Exemple de sortie

```txt
✓ 5 occurrence(s) périmée(s) supprimée(s)
✓ 50 événement(s) récurrent(s) traité(s)
✓ Total d'occurrences : 552
✓ Événements récurrents : 50
```

## Configuration du Cron Job

### Sur Linux/Unix

Ajoutez cette ligne dans votre crontab (`crontab -e`) :

```bash
# Maintenance quotidienne des occurrences à 3h du matin
0 3 * * * cd /chemin/vers/cmem2_API && php src/ics/maintenance_occurrences.php
```

### Sur Windows (Task Scheduler)

1. Ouvrez le Planificateur de tâches Windows
2. Créez une nouvelle tâche :
   - **Nom** : Maintenance Occurrences CMEM2
   - **Déclencheur** : Quotidien à 3:00
   - **Action** : Démarrer un programme
     - Programme : `C:\chemin\vers\php\php.exe`
     - Arguments : `src\ics\maintenance_occurrences.php`
     - Démarrer dans : `C:\chemin\vers\cmem2_API`

### Fréquence recommandée

- **Production** : Quotidienne (0 3 ** *)
- **Développement** : Manuel ou hebdomadaire
- **Test initial** : Exécuter manuellement après déploiement

## Processus de Déplacement de Fenêtre

### Scénario : Avancement de 1 mois

1. **Avant maintenance** :
   - Date actuelle : 26 novembre 2025
   - Fenêtre : 26 mai 2025 → 26 novembre 2026
   - Occurrences : 554

2. **Après 1 mois** :
   - Date actuelle : 26 décembre 2025
   - Fenêtre : 26 juin 2025 → 26 décembre 2026
   - Anciennes occurrences supprimées : ~30-50 (dépend des événements)
   - Nouvelles occurrences générées : ~30-50

3. **Script exécuté** :

   ```bash
   ✓ 42 occurrence(s) périmée(s) supprimée(s)
   ✓ 50 événement(s) récurrent(s) traité(s)
   ✓ Total d'occurrences : 562
   ```

## Monitoring et Alertes

### Métriques à surveiller

- Nombre total d'occurrences
- Nombre d'occurrences dans/hors fenêtre
- Nombre d'événements récurrents
- Durée d'exécution de la maintenance

### Alertes recommandées

- Maintenance échoue (exit code ≠ 0)
- Nombre d'occurrences anormalement élevé/bas
- Durée d'exécution > 5 minutes

## Dépannage

### Maintenance ne s'exécute pas

1. Vérifiez les permissions du fichier PHP
2. Vérifiez le chemin vers PHP dans le cron
3. Vérifiez les logs d'erreur du système

### Trop d'occurrences supprimées

1. Vérifiez la configuration de la fenêtre dans `OccurrenceMaintenanceService`
2. Vérifiez si des événements ont été modifiés manuellement
3. Examinez les logs pour les erreurs de génération

### Maintenance trop lente

1. Optimisez les requêtes SQL (ajoutez des indexes si nécessaire)
2. Réduisez la taille de la fenêtre si possible
3. Exécutez pendant les heures creuses

## Déploiement Initial

Après déploiement du système d'occurrences :

1. **Migration base de données** :

   ```sql
   CALL ResetICSTables();
   ```

2. **Génération initiale** :

   ```bash
   php src/ics/maintenance_occurrences.php
   ```

3. **Configuration cron job** (voir section ci-dessus)

4. **Test** :

   ```bash
   php src/ics/maintenance_occurrences.php --stats
   ```

## Configuration Avancée

### Modifier la taille de la fenêtre

Dans `src/ics/config/ics_config.php` :

```php
define('ICS_OCCURRENCES_WINDOW_PAST_MONTHS', $_ENV['ICS_OCCURRENCES_WINDOW_PAST_MONTHS'] ?? 6);     // Mois dans le passé
define('ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS', $_ENV['ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS'] ?? 12); // Mois dans le futur
```

Ou via variables d'environnement :

```bash
export ICS_OCCURRENCES_WINDOW_PAST_MONTHS=3    # 3 mois dans le passé
export ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS=6  # 6 mois dans le futur
```

### Limites de génération à la demande

```php
define('ICS_OCCURRENCES_MAX_RANGE_DAYS', $_ENV['ICS_OCCURRENCES_MAX_RANGE_DAYS'] ?? 365);         // Jours maximum
define('ICS_OCCURRENCES_MAX_PER_GENERATION', $_ENV['ICS_OCCURRENCES_MAX_PER_GENERATION'] ?? 1000); // Occurrences max
```

### Seuils de maintenance

```php
define('ICS_OCCURRENCES_MAINTENANCE_THRESHOLD_DAYS', $_ENV['ICS_OCCURRENCES_MAINTENANCE_THRESHOLD_DAYS'] ?? 30);
define('ICS_OCCURRENCES_MAINTENANCE_CLEANUP_DAYS', $_ENV['ICS_OCCURRENCES_MAINTENANCE_CLEANUP_DAYS'] ?? 90);
define('ICS_OCCURRENCES_MAINTENANCE_ALERT_THRESHOLD', $_ENV['ICS_OCCURRENCES_MAINTENANCE_ALERT_THRESHOLD'] ?? 1000);
```

### Logging personnalisé

Le service utilise `LogService` pour tracer les opérations. Configurez la destination des logs selon vos besoins.

## Variables d'Environnement

Pour configurer le système en production, définissez ces variables d'environnement :

```bash
# Fenêtre de pré-calcul (mois)
export ICS_OCCURRENCES_WINDOW_PAST_MONTHS=6
export ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS=12

# Limites de génération à la demande
export ICS_OCCURRENCES_MAX_RANGE_DAYS=365
export ICS_OCCURRENCES_MAX_PER_GENERATION=1000

# Seuils de maintenance
export ICS_OCCURRENCES_MAINTENANCE_THRESHOLD_DAYS=30
export ICS_OCCURRENCES_MAINTENANCE_CLEANUP_DAYS=90
export ICS_OCCURRENCES_MAINTENANCE_ALERT_THRESHOLD=1000
```

### Exemple de fichier .env

```bash
# Configuration des occurrences d'événements
ICS_OCCURRENCES_WINDOW_PAST_MONTHS=6
ICS_OCCURRENCES_WINDOW_FUTURE_MONTHS=12
ICS_OCCURRENCES_MAX_RANGE_DAYS=365
ICS_OCCURRENCES_MAX_PER_GENERATION=1000
ICS_OCCURRENCES_MAINTENANCE_THRESHOLD_DAYS=30
ICS_OCCURRENCES_MAINTENANCE_CLEANUP_DAYS=90
ICS_OCCURRENCES_MAINTENANCE_ALERT_THRESHOLD=1000
```
