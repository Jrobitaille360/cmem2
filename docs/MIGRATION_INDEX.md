# Migration de Configuration - Index.php

## 🔄 Changements Effectués

### ✅ Nouveau système de configuration

**Avant :**
```php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
```

**Maintenant :**
```php
require_once __DIR__ . '/config/loader.php';
```

### 🌐 CORS Amélioré

**Avant :** Configuration statique
```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
```

**Maintenant :** Configuration dynamique depuis les constantes modulaires
```php
$allowedOrigins = ALLOWED_ORIGINS;
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
}

header('Access-Control-Allow-Methods: ' . implode(', ', ALLOWED_METHODS));
header('Access-Control-Allow-Headers: ' . implode(', ', ALLOWED_HEADERS));
```

### 🔧 Mode Maintenance

**Nouveau :** Vérification automatique du mode maintenance
```php
if (MAINTENANCE_MODE) {
    Response::error(MAINTENANCE_MESSAGE, null, 503);
    LoggingMiddleware::logExit(503);
    exit();
}
```

## 📁 Fichiers Déplacés

Les anciens fichiers de configuration ont été déplacés vers `config/deprecated/` :
- `config.php` → `config/deprecated/config.php`
- `database.php` → `config/deprecated/database.php`

Ces fichiers peuvent être supprimés une fois que vous êtes sûr que la migration fonctionne correctement.

## ⚡ Avantages

1. **Configuration centralisée** : Un seul point d'entrée
2. **CORS sécurisé** : Configuration dynamique selon l'environnement
3. **Mode maintenance** : Activation facile via variables d'environnement
4. **Architecture modulaire** : Configuration séparée par domaines fonctionnels
5. **Validation automatique** : Vérification des configurations critiques

## 🧪 Test de la Migration

Pour tester que la migration fonctionne :

1. **Vérifier l'API** : Accédez à votre endpoint principal
2. **Tester CORS** : Vérifiez depuis un navigateur que les headers CORS sont corrects
3. **Mode maintenance** : Changez `MAINTENANCE_MODE=true` dans .env et vérifiez la réponse 503
4. **Logs** : Vérifiez que les logs sont générés dans les nouveaux répertoires modulaires

## 🔄 Rollback (si nécessaire)

Si vous devez revenir en arrière temporairement :

```php
// Remplacer dans index.php
require_once __DIR__ . '/config/deprecated/config.php';
require_once __DIR__ . '/config/deprecated/database.php';
```

Puis copier les fichiers depuis `config/deprecated/` vers `config/`.

## 🗑️ Nettoyage Final

Une fois que tout fonctionne parfaitement, supprimez :
```bash
rm -rf config/deprecated/
```