
# Améliorations — Système de plugins cmem2

> Analyse du code source existant — 1 avril 2026

## État actuel

Le système de plugins (`PluginManager` + `PluginInterface` + plugin ICS) est fonctionnel :
auto-découverte via `plugin.json`, lazy factories, pipeline middleware. Trois problèmes immédiats
doivent être corrigés avant d'ajouter de nouveaux plugins.

---

## Problèmes identifiés

### 1. `safeLog()` dupliqué

La méthode est copiée identiquement dans `src/Core/PluginManager.php` ET dans
`src/ics/CalendarPlugin.php`. Tout nouveau plugin devra la copier à nouveau.

### 2. Pas de classe de base pour les plugins

Chaque plugin implémente `PluginInterface` directement et repart de zéro.
Les comportements par défaut (`deactivate()` vide, `getDependencies()` vide,
`runMigrations()` fictive dans ICS) sont à réécrire à chaque fois.

### 3. Exclusions hardcodées dans `scanPluginDirectories()`

`'auth_groups'` et `'Core'` sont exclus par liste de strings. L'ajout de tout nouveau
dossier non-plugin (`notifications`, `cron`, `pomo` avant activation) doit être maintenu manuellement.
La présence de `plugin.json` est déjà le seul critère suffisant.

### 4. Redondance dans `CalendarPlugin`

Les factories de route handlers sont déclarées deux fois : dans `initialize()` via
`registerPluginRoutes()`, et dans `getRouteHandlers()`. Source de désynchronisation potentielle.

---

## Plan d'amélioration

### Phase 0-A — Créer `src/Core/AbstractPlugin.php`

Classe abstraite implémentant `PluginInterface` avec des comportements par défaut.

**Contenu :**

```php
namespace Core;

abstract class AbstractPlugin implements PluginInterface
{
    protected function safeLog(string $level, string $message, array $context = []): void
    {
        if (!class_exists('\AuthGroups\Services\LogService')) return;
        try {
            match($level) {
                'info'    => \AuthGroups\Services\LogService::info($message, $context),
                'warning' => \AuthGroups\Services\LogService::warning($message, $context),
                'error'   => \AuthGroups\Services\LogService::error($message, $context),
                default   => null,
            };
        } catch (\Exception $e) {}
    }

    public function deactivate(): void {}
    public function getDependencies(): array { return []; }

    protected function runMigrations(string $migrationsPath): void
    {
        // Hook — override dans les plugins qui ont des tables
    }
}
```

**Impact :** `CalendarPlugin` et tous les futurs plugins (Pomo, etc.) n'ont plus besoin
de `safeLog()` ni d'implémenter `deactivate()`/`getDependencies()` si les valeurs par défaut
conviennent.

### Phase 0-B — Refactorer `src/ics/CalendarPlugin.php`

1. Hériter de `AbstractPlugin` au lieu d'implémenter `PluginInterface` directement
2. Supprimer `safeLog()` dupliqué
3. Dans `initialize()`, appeler `$this->getRouteHandlers()` plutôt que redéclarer les factories

```php
public function initialize(): void
{
    $this->loadConfig();
    date_default_timezone_set($this->config['config']['default_timezone'] ?? 'America/Montreal');

    PluginManager::getInstance()->registerPluginRoutes('ics', $this->getRouteHandlers());

    $this->runMigrations(__DIR__ . '/../../docs/docs_ICS/migrations/');
}
```

### Phase 0-C — Nettoyer `src/Core/PluginManager.php`

Supprimer les exclusions hardcodées dans `scanPluginDirectories()`. La présence de `plugin.json`
est déjà le seul critère utilisé — les exclusions créent une fausse impression de nécessité.

```php
// Avant (fragile)
if ($dir === 'auth_groups' || $dir === 'Core') continue;

// Après — supprimer ces lignes. Le check file_exists('.../plugin.json') suffit.
```

---

## Fichiers à modifier

| Fichier                       | Action   | Description                                                    |
| ----------------------------- | -------- | -------------------------------------------------------------- |
| `src/Core/AbstractPlugin.php` | CRÉER    | Classe de base avec `safeLog()` et defaults                    |
| `src/Core/PluginManager.php`  | Modifier | Supprimer exclusions hardcodées dans `scanPluginDirectories()` |
| `src/ics/CalendarPlugin.php`  | Modifier | Hériter `AbstractPlugin`, corriger `initialize()`              |

> `PluginManager::safeLog()` est **conservé** tel quel — il s'exécute dans un contexte
> pré-plugin, avant qu'`AbstractPlugin` soit disponible, et reste donc nécessaire.

---

## Vérification

- Charger l'API → plugin ICS se charge toujours, aucune régression
- Tests existants passent
- Créer un deuxième plugin minimal (ex. plugin Pomo Phase 0) → pas de `safeLog()` à copier

---

## Prochaines étapes

Une fois cette phase terminée, les nouveaux plugins peuvent hériter directement d'`AbstractPlugin`.
Voir [PLAN_pomo.md](PLAN_pomo.md) pour l'implémentation du plugin Pomodoro.
