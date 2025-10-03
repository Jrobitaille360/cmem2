# Division de la procédure ResetDatabase()

La procédure originale `ResetDatabase()` a été divisée en deux fichiers distincts pour une meilleure organisation et maintenance, avec intégration des procédures de statistiques :

## 1. Fichiers créés

### `create_proc_reset_auth_groups.sql`
- **Procédure principale** : `ResetAuthenticationGroups()`
- **Contenu** : Authentification, groupes, fichiers et **tags**
- **Tables incluses** :
  - `tags` (tags communs à toutes les entités)
  - `users` (utilisateurs)
  - `login_codes` (codes de connexion)
  - `groups` (groupes)
  - `group_members` (membres de groupe)
  - `group_invitations` (invitations de groupe)
  - `group_tag_relations` (relations groupes-tags)
  - `files` (fichiers)
  - `file_tag_relations` (relations fichiers-tags)
  - `password_resets` (réinitialisations de mot de passe)
  - `email_verifications` (vérifications d'email)
  - `valid_tokens` (tokens valides)
  - `notifications` (notifications)
  - Tables de statistiques (`platform_stats`, `group_stats_snapshot`, `user_stats_snapshot`)
  - Vues associées (`v_active_users`, `v_active_sessions`, `v_online_users_stats`, `group_statistics`, `v_group_dashboard`, `v_admin_dashboard`)
- **Procédures de statistiques incluses** :
  - `GeneratePlatformStats()` - Statistiques globales (users, groups, files uniquement)
  - `GenerateGroupStats()` - Statistiques par groupe (membres uniquement)
  - `GenerateUserStats()` - Statistiques par utilisateur (groups, files uniquement)
  - `CleanupOldStats()` - Nettoyage des anciennes statistiques
- **Ordre d'exécution** : **1er** (obligatoire)

### `create_proc_reset_memories_elements.sql`
- **Procédure principale** : `ResetMemoriesElements()`
- **Contenu** : Mémoires et éléments avec leurs dépendances
- **Tables incluses** :
  - `memories` (mémoires)
  - `memory_tag_relations` (relations mémoires-tags)
  - `memory_user_relations` (relations mémoires-utilisateurs)
  - `elements` (éléments)
  - `element_tag_relations` (relations éléments-tags)
  - `memory_element_relations` (relations mémoires-éléments)
  - `element_file_relations` (relations éléments-fichiers)
  - `element_versions` (versions d'éléments)
  - `memory_group_relations` (relations mémoires-groupes)
  - Vue associée (`memory_statistics`)
- **Procédures de statistiques incluses** :
  - `UpdateMemoryElementStats()` - Met à jour les stats avec les données de memories/elements
  - `GenerateAllStats()` - Procédure coordinatrice qui appelle toutes les autres et fait la mise à jour complète
- **Ordre d'exécution** : **2ème** (dernier)

### `create_scheduled_events.sql`
- **Contenu** : Événements programmés MySQL pour automatiser les statistiques
- **Événements inclus** :
  - `daily_stats_generation` - Génération quotidienne des statistiques
  - `weekly_stats_cleanup` - Nettoyage hebdomadaire des anciennes données
- **Ordre d'exécution** : **3ème** (optionnel - après les procédures)

## 2. Ordre d'exécution obligatoire

Pour réinitialiser complètement la base de données, exécuter les procédures dans cet ordre :

```sql
-- 1. Créer les tables d'authentification, groupes, tags et procédures de stats
CALL ResetAuthenticationGroups();

-- 2. Créer les tables de mémoires, éléments et procédure coordinatrice
CALL ResetMemoriesElements();

-- 3. (Optionnel) Créer les événements programmés pour automatiser les stats
SOURCE create_scheduled_events.sql;

-- 4. (Optionnel) Activer le scheduler d'événements
SET GLOBAL event_scheduler = ON;
```

## 3. Utilisation des procédures de statistiques

```sql
-- Générer toutes les statistiques (approche recommandée)
CALL GenerateAllStats();

-- OU générer par étapes :

-- 1. Générer les statistiques de base (users, groups, files)
CALL GeneratePlatformStats();
CALL GenerateGroupStats();
CALL GenerateUserStats();

-- 2. Mettre à jour avec les données de memories/elements
CALL UpdateMemoryElementStats();

-- Nettoyer les anciennes statistiques
CALL CleanupOldStats();
```

## 4. Architecture des statistiques

### **Étape 1 - Tables de base (auth/groups)**
- Les procédures dans `create_proc_reset_auth_groups.sql` génèrent les statistiques de base
- Elles ne référencent que les tables : `users`, `groups`, `files`, `group_members`, `group_invitations`
- Cela garantit l'indépendance et évite les erreurs de tables manquantes

### **Étape 2 - Complétion (memories/elements)**  
- La procédure `UpdateMemoryElementStats()` met à jour les statistiques existantes
- Elle ajoute les données de `memories`, `elements` et leurs relations
- Utilise des `UPDATE` pour compléter les données déjà générées

## 5. Dépendances entre les procédures

- **`ResetAuthenticationGroups()`** : Aucune dépendance (contient la table `tags` et les procédures de stats de base)
- **`ResetMemoriesElements()`** : Dépend de `users`, `groups`, `files` et `tags` (contient la procédure de mise à jour et coordination)
- **Événements programmés** : Dépendent de toutes les procédures de statistiques

## 6. Points importants

1. **Séparation claire** : Les procédures de stats dans `auth_groups` ne référencent QUE les tables de ce fichier

2. **Approche en deux étapes** :
   - Génération des stats de base (users, groups, files)
   - Mise à jour avec les données complexes (memories, elements)

3. **Pas d'erreurs de dépendances** : Chaque procédure peut s'exécuter même si l'autre fichier n'est pas encore créé

4. **Table tags intégrée** : La table `tags` est créée dans `ResetAuthenticationGroups()` car cette procédure est obligatoire.

5. **Contrainte de clé étrangère** : La contrainte `tag_owner` vers `users(id)` est ajoutée après la création de la table `users` via un `ALTER TABLE`.

6. **Relations croisées** : Les mémoires peuvent être liées aux groupes via `memory_group_relations`, ce qui nécessite que les groupes soient créés avant les mémoires.

## 7. Avantages de cette approche

- **Indépendance** : Chaque fichier peut fonctionner seul pour ses propres statistiques
- **Robustesse** : Pas d'erreur si une table n'existe pas encore
- **Flexibilité** : Possibilité de générer partiellement les statistiques
- **Logique** : Les procédures de stats sont créées en même temps que les tables qu'elles utilisent
- **Simplicité** : Architecture claire et compréhensible
- **Modularité** : Chaque procédure gère un domaine fonctionnel spécifique
- **Maintenance** : Plus facile de modifier une partie sans affecter les autres
- **Automatisation** : Possibilité d'automatiser les statistiques avec les événements programmés