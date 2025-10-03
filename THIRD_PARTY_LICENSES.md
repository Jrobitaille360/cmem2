# Licences des Dépendances Tierces

Ce fichier liste toutes les dépendances externes et leurs licences respectives utilisées dans l'API Collective Memories.

## Dépendances de Production

### Firebase JWT
- **Package** : `firebase/php-jwt` v6.11.1
- **Licence** : BSD-3-Clause
- **Copyright** : Copyright (c) 2011, Neuman Vong
- **Usage** : Authentification JWT
- **Compatibilité** : ✅ Compatible avec MIT

### PHPMailer
- **Package** : `phpmailer/phpmailer` v6.10.0  
- **Licence** : GNU LGPL v2.1
- **Usage** : Envoi d'emails
- **Compatibilité** : ✅ Compatible (usage en tant que bibliothèque)
- **Note** : LGPL permet l'usage commercial en tant que bibliothèque liée

## Dépendances de Développement (Test)

### PHPUnit Framework
- **Package** : `phpunit/phpunit` v9.6.23
- **Licence** : BSD-3-Clause
- **Usage** : Tests unitaires
- **Compatibilité** : ✅ Compatible avec MIT

### Sebastian Components
Toutes les dépendances Sebastian (code coverage, assertions, etc.) :
- **Licence** : BSD-3-Clause  
- **Compatibilité** : ✅ Compatible avec MIT

### Autres Dépendances
- **Doctrine Instantiator** : MIT License ✅
- **Nikic PHP-Parser** : BSD-3-Clause ✅
- **MyClabs Deep-Copy** : MIT License ✅

## Fichiers Média

✅ **Sécurisé** : Les fichiers dans `tmp_assets/` sont **exclus de Git** :
- `avatar01.jpg` - Fichier local uniquement (non versionné)
- `IMG_4354.MOV` - Fichier local uniquement (non versionné)
- Ces fichiers restent sur votre machine et ne sont **pas transférés sur GitHub**

## Conformité

✅ Toutes les dépendances sont **compatibles** avec la licence MIT du projet principal.

⚠️ **Important** : Les fichiers uploadés par les utilisateurs dans `uploads/` restent sous les droits d'auteur de leurs propriétaires respectifs.

---

*Dernière mise à jour : 10 septembre 2025*
