# Documentation de la séparation modulaire des endpoints API

## Vue d'ensemble

La documentation des endpoints API a été divisée en modules logiques suivant la même architecture que celle appliquée aux procédures de base de données. Cette séparation améliore la maintenabilité, la clarté et la spécialisation fonctionnelle.

## Structure modulaire

### Fichiers créés

1. **`API_ENDPOINTS_AUTH_GROUPS.json`** - Module d'authentification et groupes
2. **`API_ENDPOINTS_MEMORIES_ELEMENTS.json`** - Module mémoires et éléments
3. **`API_ENDPOINTS.json`** - Fichier original complet (conservé)

### Logique de séparation

#### Module Authentification & Groupes (`API_ENDPOINTS_AUTH_GROUPS.json`)
**Sections incluses :**
- `Public` - Endpoints d'accès public (inscription, connexion, vérification email)
- `Users` - Gestion des comptes utilisateurs et profils
- `Tags` - Système d'étiquetage pour organisation du contenu
- `Files` - Gestion des fichiers partagés
- `Groups` - Gestion des groupes collaboratifs
- `Stats` - Statistiques système et utilisateur

**Responsabilités :**
- Infrastructure de base (authentification JWT, vérification email)
- Gestion des utilisateurs et permissions
- Système de groupes collaboratifs
- Gestion des fichiers et uploads
- Système de tags transversal
- Statistiques et monitoring

#### Module Mémoires & Éléments (`API_ENDPOINTS_MEMORIES_ELEMENTS.json`)
**Sections incluses :**
- `Memories` - Gestion des mémoires collectives avec géolocalisation
- `Elements` - Gestion des éléments multimédia (texte, image, audio, vidéo, documents, GPX, iCal)

**Responsabilités :**
- Contenu principal de l'application
- Gestion des mémoires avec métadonnées (lieu, temps, visibilité)
- Éléments multimédia avec support complet des types de fichiers
- Relations entre mémoires et éléments
- Recherche et filtrage de contenu

## Avantages de cette architecture

### 1. **Séparation des préoccupations**
- **Infrastructure vs Contenu** : Distinction claire entre les services de base et le contenu métier
- **Responsabilités définies** : Chaque module a un périmètre fonctionnel précis
- **Réutilisabilité** : Le module auth/groupes peut être réutilisé pour d'autres applications

### 2. **Maintenance simplifiée**
- **Modifications ciblées** : Les changements affectent un module spécifique
- **Tests isolés** : Possibilité de tester chaque module indépendamment
- **Documentation focalisée** : Chaque équipe peut se concentrer sur son domaine

### 3. **Évolutivité**
- **Développement parallèle** : Équipes peuvent travailler simultanément sur différents modules
- **Déploiement modulaire** : Possibilité de déployer les modules séparément
- **Microservices ready** : Architecture préparée pour une éventuelle migration vers des microservices

### 4. **Clarté fonctionnelle**
- **Navigation améliorée** : Plus facile de trouver les endpoints pertinents
- **Compréhension rapide** : Chaque module a un objectif clair et délimité
- **Documentation spécialisée** : Informations adaptées au contexte de chaque module

## Configuration des modules

### Module Auth/Groups
```json
{
  "module": "auth_groups",
  "scope": ["Public", "Users", "Tags", "Files", "Groups", "Stats"],
  "main_tables": ["users", "groups", "tags", "files", "group_members", "group_invitations"],
  "description": "Module d'infrastructure gérant l'authentification, la gestion des utilisateurs, groupes collaboratifs, système de tags et gestion des fichiers."
}
```

### Module Memories/Elements
```json
{
  "module": "memories_elements", 
  "scope": ["Memories", "Elements"],
  "main_tables": ["memories", "elements", "memory_element_relations"],
  "description": "Module principal gérant le contenu collectif : mémoires et éléments multimédia avec système de relations et tags."
}
```

## Dépendances entre modules

### Module Memories/Elements → Module Auth/Groups
- **Authentification** : Utilise le système JWT du module auth/groups
- **Utilisateurs** : Référence les utilisateurs pour la propriété des contenus
- **Tags** : Utilise le système de tags pour organiser le contenu
- **Groupes** : Intégration avec les groupes pour le partage de contenu

### Endpoints de liaison
Les modules communiquent via des endpoints de liaison :
- `/memories/{memoryId}/{elementId}/attach` - Association mémoire-élément
- `/tags/{tagId}/{item_id}` - Association tags avec mémoires/éléments
- Permissions basées sur l'appartenance aux groupes

## Utilisation pratique

### Pour les développeurs frontend
1. **Module Auth/Groups** : Implémentation de l'interface d'authentification et de gestion des groupes
2. **Module Memories/Elements** : Développement des interfaces de création/consultation de contenu

### Pour les développeurs backend
1. **Développement spécialisé** : Chaque équipe peut se concentrer sur son module
2. **APIs indépendantes** : Possibilité de développer des APIs séparées
3. **Tests unitaires** : Tests isolés par module

### Pour l'équipe documentation
1. **Documentation ciblée** : Guides spécifiques par module
2. **Exemples d'usage** : Scénarios d'utilisation par domaine fonctionnel
3. **Maintenance simplifiée** : Mise à jour par section

## Migration et compatibilité

### Rétrocompatibilité
- Le fichier `API_ENDPOINTS.json` original est conservé
- Tous les endpoints restent fonctionnels
- Pas d'impact sur les intégrations existantes

### Migration recommandée
1. **Phase 1** : Utiliser les modules pour le développement de nouvelles fonctionnalités
2. **Phase 2** : Migrer progressivement les intégrations existantes
3. **Phase 3** : Déprécier l'usage du fichier monolithique si souhaité

## Cohérence avec l'architecture base de données

Cette séparation des endpoints suit exactement la même logique que la modularisation des procédures de base de données :

### Procédures Base de Données
- `create_proc_reset_auth_groups.sql` ↔ `API_ENDPOINTS_AUTH_GROUPS.json`
- `create_proc_reset_memories_elements.sql` ↔ `API_ENDPOINTS_MEMORIES_ELEMENTS.json`

### Triggers
- `create_triggers_auth_groups.sql` - Triggers pour tables du module auth/groups
- `create_triggers_memories_elements.sql` - Triggers pour tables du module memories/elements

### Cohérence architecturale complète
- **Base de données** : Procédures modulaires
- **API** : Endpoints modulaires  
- **Documentation** : Structure modulaire
- **Tests** : Approche modulaire
- **Déploiement** : Capacité modulaire

Cette approche garantit une architecture cohérente et maintenable à tous les niveaux de l'application.