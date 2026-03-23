# Licences des Dépendances Tierces

Ce fichier liste toutes les dépendances externes et leurs licences respectives utilisées dans l'API AuthGroups.

## Dépendances de Production

### PHPMailer

- **Package** : `phpmailer/phpmailer` v6.12.0
- **Licence** : LGPL-2.1-only
- **Usage** : Envoi d'emails
- **Compatibilité** : ✅ Compatible (usage en tant que bibliothèque)
- **Note** : LGPL permet l'usage commercial en tant que bibliothèque liée

## Dépendances de Développement (Test)

### PHPUnit Framework

- **Package** : `phpunit/phpunit` v9.6.29
- **Licence** : BSD-3-Clause
- **Usage** : Tests unitaires
- **Compatibilité** : ✅ Compatible avec MIT

### Sebastian Components

Toutes les dépendances Sebastian (code coverage, assertions, etc.) :

- **Packages** : sebastian/* (29 packages)
- **Licence** : BSD-3-Clause
- **Compatibilité** : ✅ Compatible avec MIT

### Autres Dépendances de Développement

- **Doctrine Instantiator** (v1.5.0) : MIT License ✅
- **Nikic PHP-Parser** (v5.6.1) : BSD-3-Clause ✅
- **MyClabs Deep-Copy** (v1.13.4) : MIT License ✅
- **Phar-IO Manifest** (v2.0.4) : BSD-3-Clause ✅
- **Phar-IO Version** (v3.2.1) : BSD-3-Clause ✅
- **Theseer Tokenizer** (v1.2.3) : BSD-3-Clause ✅

## Fichiers Média

✅ **Sécurisé** : Les fichiers dans `tmp_assets/` sont **exclus de Git** :

- `avatar01.jpg` - Fichier local uniquement (non versionné)
- `IMG_4354.MOV` - Fichier local uniquement (non versionné)
- Ces fichiers restent sur votre machine et ne sont **pas transférés sur GitHub**

## Conformité

✅ **Toutes les dépendances sont compatibles** avec la licence MIT du projet principal :

- **MIT License** (2 packages) : Doctrine Instantiator, MyClabs Deep-Copy
- **BSD-3-Clause** (28 packages) : PHPUnit, Sebastian, Nikic, Phar-IO, Theseer
- **LGPL-2.1-only** (1 package) : PHPMailer (utilisé en tant que bibliothèque, conforme)

⚠️ **Important** : Les fichiers uploadés par les utilisateurs dans `uploads/` restent sous les droits d'auteur de leurs propriétaires respectifs.

## Résumé des Licences

| Type de Licence | Nombre de Packages | Compatibilité MIT |
| ----------------- | ------------------- | ------------------- |
| MIT | 2 | ✅ Oui |
| BSD-3-Clause | 28 | ✅ Oui |
| LGPL-2.1-only | 1 | ✅ Oui (linking) |
| **Total** | **31** | **100% compatible** |

---

**Dernière vérification** : 23 octobre 2025  
**Méthode** : `composer licenses`  
**Statut** : ✅ Toutes les licences validées
