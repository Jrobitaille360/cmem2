# Spécifications Techniques - API Collective Memories

## 📡 En-têtes HTTP

### Requêtes
```
Content-Type: application/json
Authorization: Bearer <token> (pour les routes protégées)
```

### Réponses
```
Content-Type: application/json; charset=utf-8
Access-Control-Allow-Origin: *
```

## 🎯 Exigences Frontend

- **Authentification** : JWT Bearer tokens dans l'en-tête Authorization  
- **Content-Type** : application/json pour les données POST/PUT  
- **CORS** : CORS ouvert (Access-Control-Allow-Origin: *)  
- **Uploads** : multipart/form-data pour les uploads (images, docs, audio, vidéo)  

## 📏 Limites système

### Pagination
- **Maximum** : 100 éléments par page

### Uploads
- **Images** : 5 MB maximum  
- **Documents** : 10 MB maximum  
- **Audio** : 20 MB maximum  
- **Vidéo** : 50 MB maximum  
- **Avatars** : 2 MB maximum  

### Rate Limiting
- **Limite** : 100 requêtes/heure/IP

### Types de fichiers supportés
- **Images** : JPEG, PNG, GIF, WebP  
- **Documents** : PDF, TXT, DOC, DOCX  
- **Audio** : MP3, WAV, OGG  
- **Vidéo** : MP4, AVI, MOV  

## ✅ Validation des données

L'API effectue une validation stricte des données d'entrée pour tous les endpoints qui en nécessitent :

### Types de validation
- **Champs requis** : Vérification de présence des données obligatoires
- **Format email** : Validation RFC pour les adresses email  
- **Longueur de chaînes** : Respect des limites min/max de caractères
- **Formats de dates** : Format YYYY-MM-DD strictement appliqué
- **Tokens** : Vérification de validité et d'expiration
- **Mots de passe** : Minimum 6 caractères requis

### Réponses de validation
En cas d'erreur de validation, l'API retourne un code **400** avec le détail des erreurs :
```json
{
  "success": false,
  "message": "Données de validation invalides", 
  "errors": {
    "field": ["Message de validation spécifique"]
  }
}
```

## 📧 Système d'emails

L'API intègre un système d'emails automatique avec SMTP sécurisé :

### Types d'emails automatiques
- **Email de bienvenue** : Envoyé automatiquement lors de l'inscription avec token de vérification  
- **Réinitialisation de mot de passe** : Avec token sécurisé  
- **Vérification d'email** : Token pour activer le compte  
- **Invitation de groupe** : Email avec lien d'acceptation  
- **Notification nouvelle mémoire** : Notification aux membres du groupe (si applicable)  

### Configuration SMTP
- **Serveur** : votre serveur:587  
- **Sécurité** : TLS encryption  
- **Templates** : HTML responsive  
- **Mode développement** : Emails loggés uniquement  

## 🔒 Sécurité

### Authentification JWT
- **Durée de vie** : Configurable (généralement 24h)
- **Algorithme** : HS256
- **Header requis** : `Authorization: Bearer <token>`

### Chiffrement
- **Mots de passe** : Hashés avec bcrypt
- **Tokens** : Signés avec clé secrète
- **HTTPS** : Recommandé en production

### Protection CSRF
- **CORS** : Configuré pour autoriser les domaines approuvés
- **Tokens** : Vérification de l'origine des requêtes

## 📊 Performance et monitoring

### Monitoring
- **Logs** : Enregistrement automatique des requêtes
- **Erreurs** : Tracking des erreurs système
- **Performance** : Métriques de temps de réponse

### Cache
- **Stratégie** : Cache des données fréquemment accédées
- **TTL** : Time-to-live configurable par type de données

### Base de données
- **Optimisations** : Index sur les champs de recherche fréquents
- **Connexions** : Pool de connexions pour optimiser les performances
- **Backup** : Sauvegarde automatique configurable

---
**Retour à** : [Documentation principale](./README.md)
