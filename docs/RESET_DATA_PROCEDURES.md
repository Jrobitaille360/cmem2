# Procédures de Réinitialisation des Données de Test

## Vue d'ensemble

La procédure originale `ResetData` a été divisée en deux procédures modulaires suivant l'architecture séparée de la base de données :

1. **`ResetAuthGroupsData()`** - Réinitialise les données d'authentification, groupes, fichiers et tags
2. **`ResetMemoriesElementsData()`** - Réinitialise les données de mémoires, éléments et leurs relations

## Fichiers

- `create_proc_reset_auth_groups_data.sql` - Procédure pour le module authentification/groupes
- `create_proc_reset_memories_elements_data.sql` - Procédure pour le module mémoires/éléments

## Ordre d'Exécution

### Pour une réinitialisation complète :

```sql
-- 1. Réinitialiser les données d'authentification et groupes (infrastructure de base)
CALL ResetAuthGroupsData();

-- 2. Réinitialiser les données de mémoires et éléments (dépend des users/groups/tags)
CALL ResetMemoriesElementsData();
```

### Exécution individuelle :

```sql
-- Seulement les données d'authentification/groupes
CALL ResetAuthGroupsData();

-- Seulement les données de mémoires/éléments (nécessite que les users/groups/tags existent)
CALL ResetMemoriesElementsData();
```

## Contenu des Procédures

### ResetAuthGroupsData()

**Tables supprimées et recréées :**
- `users` (10 utilisateurs de test)
- `groups` (2 groupes de test)
- `tags` (3 tags de test)
- `files` (3 fichiers de test)
- `group_members` (relations membres-groupes)
- `group_invitations` (invitations de groupes)
- `group_tag_relations` (tags de groupes)
- `file_tag_relations` (tags de fichiers)
- `valid_tokens` (tokens d'authentification)

**Données de test incluses :**
- 10 utilisateurs avec rôles ADMIN/UTILISATEUR
- 2 groupes (public/privé)
- 3 tags thématiques
- 3 fichiers de test (image, audio, PDF)
- Relations groupes-membres et invitations

### ResetMemoriesElementsData()

**Tables supprimées et recréées :**
- `memories` (mémoires de base + mémoires étendues pour tests)
- `elements` (4 éléments de test)
- `memory_element_relations` (relations mémoires-éléments)
- `element_file_relations` (relations éléments-fichiers)
- `memory_tag_relations` (tags de mémoires)
- `element_tag_relations` (tags d'éléments)
- `memory_group_relations` (partage groupes)
- `memory_user_relations` (partage utilisateurs)

**Données de test incluses :**
- 3 mémoires de base + 12 mémoires étendues pour tests
- 4 éléments (texte, image, audio, document)
- Différents niveaux de visibilité (public, private, shared)
- Relations complètes entre toutes les entités

## Avantages de la Séparation

1. **Modularité** : Possibilité de réinitialiser seulement une partie des données
2. **Performance** : Évite de recréer toutes les données si seules certaines sont nécessaires
3. **Maintenance** : Plus facile de modifier les données de test d'un module spécifique
4. **Flexibilité** : Permet des scénarios de test ciblés

## Scénarios d'Usage

### Développement
```sql
-- Setup initial complet
CALL ResetAuthGroupsData();
CALL ResetMemoriesElementsData();
```

### Tests d'API authentification
```sql
-- Seulement les données d'auth
CALL ResetAuthGroupsData();
```

### Tests de mémoires (avec données auth existantes)
```sql
-- Seulement reset des mémoires
CALL ResetMemoriesElementsData();
```

### Reset partiel en développement
```sql
-- Garder les users mais reset les mémoires
CALL ResetMemoriesElementsData();
```

## Notes Importantes

- **Dépendances** : `ResetMemoriesElementsData()` nécessite que les users, groups et tags existent
- **Ordre** : Toujours exécuter `ResetAuthGroupsData()` avant `ResetMemoriesElementsData()` pour un reset complet
- **Clés étrangères** : Les DELETE sont organisés dans l'ordre inverse des dépendances
- **Data integrity** : Chaque procédure maintient la cohérence de son module