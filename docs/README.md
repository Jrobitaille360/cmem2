# API Collective Memories - Documentation Complète

## 📋 Informations générales

**Nom** : Collective Memories API  
**Version** : 1.1.0  
**Description** : API REST pour l'application de mémoires collectives. Gestion utilisateurs, groupes, mémoires, fichiers, tags. Authentification JWT. SMTP intégré.  
**Base URL** : https://votre site/cmem1_API/  
**Documentation** : https://votre site/api/docs  
**Format** : JSON  
**Encodage** : UTF-8  
**Authentification** : JWT Bearer Token  
**CORS** : Ouvert (Access-Control-Allow-Origin: *)  

### 🔐 Types d'endpoints
- ⭐ **PUBLIC** : Accessible sans authentification
- 🔒 **USER** : Nécessite une authentification utilisateur
- 🔒 **ADMIN** : Nécessite des privilèges administrateur

### 📊 Statut système
✅ **API** : Opérationnelle  
✅ **Base de données** : Connectée (charset: utf8mb4)  
✅ **SMTP** : Intégré (votre serveur:587, TLS)  
✅ **Tests** : 100% de réussite sur tous les endpoints  
🕐 **Heure serveur** : Dynamique (ex: 2025-09-10 17:56:34)  

### 🔄 Dernières améliorations (Septembre 2025)
- ✅ **API REST complète** : Tous les endpoints avec méthodes HTTP appropriées (GET, POST, PUT, DELETE)
- ✅ **Sections validation complètes** : Règles de validation détaillées pour tous les endpoints
- ✅ **Error responses standardisées** : Codes d'erreur cohérents 
- ✅ **Conformité API_ENDPOINTS.json** : Documentation synchronisée avec la spécification officielle
- ✅ **Messages d'erreur en français** : Amélioration de l'expérience utilisateur
- ✅ **Sistema de tags avancé** : Gestion complète des tags avec associations multiples

## 📚 Documentation par sections

### 🔧 Configuration et spécifications techniques
📖 **[Spécifications Techniques](./SPECIFICATIONS.md)**
- En-têtes HTTP
- Exigences Frontend
- Limites système
- Validation des données
- Système d'emails

### 📡 Endpoints par catégorie

#### Authentification et accès public
📖 **[Endpoints Public](./ENDPOINTS_PUBLIC.md)**
- Informations API
- Santé système  
- Inscription utilisateur
- Connexion
- Réinitialisation mot de passe
- Vérification email
- Groupes publics

#### Gestion des utilisateurs
📖 **[Endpoints Users](./ENDPOINTS_USERS.md)**
- Profil utilisateur
- Gestion des avatars
- Changement mot de passe
- Administration utilisateurs

#### Mémoires collectives
📖 **[Endpoints Memories](./ENDPOINTS_MEMORIES.md)**
- Création et modification de mémoires
- Recherche et filtrage
- Upload de fichiers pour mémoires
- Gestion de la visibilité

#### Éléments multimédias
📖 **[Endpoints Elements](./ENDPOINTS_ELEMENTS.md)**
- Création d'éléments (texte, image, audio, vidéo, document)
- Gestion et modification
- Types de médias supportés

#### Système d'étiquetage
📖 **[Endpoints Tags](./ENDPOINTS_TAGS.md)**
- Création et gestion des tags
- Association aux différents éléments
- Tags les plus utilisés
- Recherche de tags

#### Gestion des fichiers
📖 **[Endpoints Files](./ENDPOINTS_FILES.md)**
- Upload de fichiers
- Téléchargement
- Information des fichiers
- Gestion par utilisateur

#### Groupes d'utilisateurs
📖 **[Endpoints Groups](./ENDPOINTS_GROUPS.md)**
- Création et administration de groupes
- Invitations et membres
- Recherche de groupes
- Gestion des rôles

#### Statistiques et analytiques
📖 **[Endpoints Stats](./ENDPOINTS_STATS.md)**
- Statistiques de la plateforme
- Statistiques par utilisateur
- Statistiques par groupe
- Génération des rapports  
- Inscription utilisateurs
- Connexion
- Réinitialisation mot de passe
- Vérification email
- Groupes publics

#### Gestion des utilisateurs
📖 **[Endpoints Users](./ENDPOINTS_USERS.md)**
- Gestion du profil
- Avatar utilisateur
- Changement mot de passe
- Suppression et restauration de compte
- Administration des utilisateurs

#### Gestion du contenu
📖 **[Endpoints Memories](./ENDPOINTS_MEMORIES.md)**
- Création et gestion des mémoires
- Recherche dans les mémoires
- Upload de fichiers pour mémoires

📖 **[Endpoints Elements](./ENDPOINTS_ELEMENTS.md)**
- Création d'éléments multimédia
- Gestion des éléments (texte, image, audio, vidéo, documents)

📖 **[Endpoints Tags](./ENDPOINTS_TAGS.md)**
- Système de tags avancé
- Associations multiples
- Tags par table
- Statistiques d'usage

📖 **[Endpoints Files](./ENDPOINTS_FILES.md)**
- Upload de fichiers génériques
- Téléchargement et information sur fichiers
- Gestion par utilisateur

#### Fonctionnalités collaboratives
📖 **[Endpoints Groups](./ENDPOINTS_GROUPS.md)**
- Création et gestion de groupes
- Système d'invitations
- Gestion des membres et rôles

#### Administration et monitoring
📖 **[Endpoints Stats](./ENDPOINTS_STATS.md)**
- Statistiques globales
- Statistiques par utilisateur/groupe
- Génération des rapports

## 🚀 Guide de démarrage rapide

### Authentification
```bash
# Inscription
POST /users/register
{
  "name": "Jean Dupont",
  "email": "user@example.com", 
  "password": "motdepasse123"
}

# Connexion
POST /users/login
{
  "email": "user@example.com",
  "password": "motdepasse123"
}
```

### Utilisation du token
```javascript
const headers = {
  'Authorization': 'Bearer ' + token,
  'Content-Type': 'application/json'
};
```

## 📝 Notes importantes

### Validation des données
L'API effectue une validation stricte des données d'entrée. En cas d'erreur, elle retourne un code **400** avec le détail des erreurs :

```json
{
  "success": false,
  "message": "Données de validation invalides", 
  "errors": {
    "field": ["Message de validation spécifique"]
  }
}
```

### Codes de statut HTTP
- **200** : Succès
- **201** : Ressource créée
- **400** : Données invalides
- **401** : Authentification requise
- **403** : Accès non autorisé
- **404** : Ressource non trouvée
- **409** : Conflit (ressource existe déjà)
- **413** : Fichier trop volumineux
- **415** : Type de fichier non supporté
- **422** : Entité non traitable
- **500** : Erreur serveur

### Support technique
Pour toute question technique ou assistance, contactez l'équipe de développement.

---
**Dernière mise à jour** : 10 septembre 2025  
**Version** : 1.1.0  
**Statut** : Documentation complète et à jour
