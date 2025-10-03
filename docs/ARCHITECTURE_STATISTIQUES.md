# Gestion des Statistiques - Architecture en deux étapes

## Vue d'ensemble

Le système de statistiques fonctionne en deux étapes pour respecter l'indépendance des modules :

1. **Étape 1** : Création des structures et génération des stats de base (`ResetAuthenticationGroups`)
2. **Étape 2** : Ajout des colonnes spécialisées et mise à jour (`ResetMemoriesElements`)

## Détail de l'architecture

### 📊 **Tables de statistiques créées dans `ResetAuthenticationGroups`**

```sql
-- Table des statistiques globales (structure de base)
CREATE TABLE platform_stats (
    total_users, active_users_7d, active_users_30d, total_groups,
    total_files, total_storage_mb, pending_invitations, avg_group_size
    -- + colonnes ajoutées par ResetMemoriesElements :
    -- total_memories, total_elements
);

-- Table des statistiques par groupe (structure de base)
CREATE TABLE group_stats_snapshot (
    group_id, group_name, visibility, member_count, days_since_creation
    -- + colonnes ajoutées par ResetMemoriesElements :
    -- memory_count, element_count
);

-- Table des statistiques par utilisateur (structure de base)  
CREATE TABLE user_stats_snapshot (
    user_id, user_name, role, last_login, groups_created, groups_joined,
    files_uploaded, storage_used_mb, invitations_sent, days_since_registration
    -- + colonnes ajoutées par ResetMemoriesElements :
    -- memories_created, elements_created
);
```

### 🔧 **Procédures créées dans `ResetAuthenticationGroups`**

- `GeneratePlatformStats()` - Insère les données de base + valeurs 0 pour memories/elements
- `GenerateGroupStats()` - Insère les données de base + valeurs 0 pour memories/elements  
- `GenerateUserStats()` - Insère les données de base + valeurs 0 pour memories/elements
- `CleanupOldStats()` - Nettoyage des anciennes données

### 🔧 **Procédures créées dans `ResetMemoriesElements`**

- `ALTER TABLE` - Ajoute les colonnes manquantes aux tables de stats
- `UpdateMemoryElementStats()` - Met à jour les colonnes avec les vraies valeurs
- `GenerateAllStats()` - Procédure coordinatrice complète

## Flux d'exécution

### **Ordre obligatoire :**

```sql
-- 1. Création des structures et stats de base
CALL ResetAuthenticationGroups();

-- 2. Ajout des colonnes et mise à jour avec memories/elements  
CALL ResetMemoriesElements();

-- 3. Génération complète des statistiques
CALL GenerateAllStats();
```

### **Que fait chaque étape :**

#### **Étape 1 - `ResetAuthenticationGroups()`**
```sql
-- Crée les tables de stats et les procédures
-- Génère automatiquement des stats partielles :
platform_stats: users=100, groups=20, files=500, memories=0, elements=0
group_stats: group1 members=5, memories=0, elements=0  
user_stats: user1 groups=2, files=10, memories=0, elements=0
```

#### **Étape 2 - `ResetMemoriesElements()`** 
```sql
-- Ajoute les colonnes manquantes :
ALTER TABLE platform_stats ADD COLUMN total_memories int(11) DEFAULT 0;
ALTER TABLE platform_stats ADD COLUMN total_elements int(11) DEFAULT 0;
-- etc.
```

#### **Étape 3 - `GenerateAllStats()`**
```sql
-- Génère les stats de base (users, groups, files)
-- Puis met à jour avec les vraies valeurs :
platform_stats: users=100, groups=20, files=500, memories=150, elements=300
group_stats: group1 members=5, memories=12, elements=25
user_stats: user1 groups=2, files=10, memories=8, elements=15
```

## Avantages de cette approche

✅ **Indépendance complète** : Chaque module peut fonctionner seul  
✅ **Pas d'erreurs de dépendances** : Les tables existent avant d'être utilisées  
✅ **Extensibilité** : Facile d'ajouter de nouvelles colonnes  
✅ **Robustesse** : Fonctionne même si un module n'est pas encore créé  
✅ **Flexibilité** : Possibilité de régénérer partiellement les stats  

## Utilisation courante

```sql
-- Génération complète (recommandée)
CALL GenerateAllStats();

-- Ou génération par étapes (pour debug)
CALL GeneratePlatformStats();
CALL GenerateGroupStats(); 
CALL GenerateUserStats();
CALL UpdateMemoryElementStats();
```