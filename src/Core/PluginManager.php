<?php

namespace Core;

use AuthGroups\Utils\Response;

class PluginManager
{
    private static ?PluginManager $instance = null;
    private array $loadedPlugins = [];
    private array $pluginConfigs = [];
    private string $pluginsPath;

    private function __construct()
    {
        $this->pluginsPath = __DIR__ . '/../';
    }

    public static function getInstance(): PluginManager
    {
        if (self::$instance === null) {
            self::$instance = new PluginManager();
        }
        return self::$instance;
    }

    /**
     * Logging sûr qui vérifie si LogService est disponible
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        // Vérifier si LogService est disponible et chargé
        if (class_exists('\AuthGroups\Services\LogService')) {
            try {
                switch ($level) {
                    case 'info':
                        \AuthGroups\Services\LogService::info($message, $context);
                        break;
                    case 'warning':
                        \AuthGroups\Services\LogService::warning($message, $context);
                        break;
                    case 'error':
                        \AuthGroups\Services\LogService::error($message, $context);
                        break;
                }
            } catch (\Exception $e) {
                // Si LogService échoue, ne rien faire pour éviter les boucles
            }
        }
        // Sinon, les logs sont ignorés silencieusement pendant le chargement initial
    }

    /**
     * Charge tous les plugins disponibles
     */
    public function loadPlugins(): void
    {
        $pluginDirectories = $this->scanPluginDirectories();
        
        foreach ($pluginDirectories as $pluginDir) {
            $this->loadPlugin($pluginDir);
        }
    }

    /**
     * Charge un plugin spécifique
     */
    public function loadPlugin(string $pluginName): bool
    {
        try {
            $pluginPath = $this->pluginsPath . $pluginName;
            $configFile = $pluginPath . '/plugin.json';
            
            if (!file_exists($configFile)) {
                $this->safeLog('warning', "Configuration plugin manquante", [
                    'plugin' => $pluginName,
                    'config_file' => $configFile
                ]);
                return false;
            }
            
            $config = json_decode(file_get_contents($configFile), true);
            
            if (!$this->validatePluginConfig($config)) {
                $this->safeLog('error', "Configuration plugin invalide", [
                    'plugin' => $pluginName
                ]);
                return false;
            }
            
            // Vérifier les dépendances
            if (!$this->checkDependencies($config)) {
                $this->safeLog('error', "Dépendances plugin non satisfaites", [
                    'plugin' => $pluginName,
                    'dependencies' => $config['dependencies'] ?? []
                ]);
                return false;
            }
            
            // Charger l'autoloader du plugin si présent
            $this->loadPluginAutoloader($pluginPath);
            
            // Initialiser le plugin
            $pluginClass = $config['main_class'];
            if (class_exists($pluginClass)) {
                $pluginInstance = new $pluginClass();

                // Enregistrer AVANT initialize() pour que registerPluginRoutes() trouve l'entrée
                $this->loadedPlugins[$pluginName] = [
                    'instance' => $pluginInstance,
                    'config' => $config,
                    'status' => 'loaded'
                ];

                // Charger les gestionnaires de routes du plugin (différé)
                $this->storePluginRouteHandlersConfig($pluginName, $config);

                if (method_exists($pluginInstance, 'initialize')) {
                    $pluginInstance->initialize();
                }
                
                $this->safeLog('info', "Plugin chargé avec succès", [
                    'plugin' => $pluginName,
                    'version' => $config['version']
                ]);
                
                return true;
            }
            
        } catch (\Exception $e) {
            $this->safeLog('error', "Erreur lors du chargement du plugin", [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Enregistre les routes d'un plugin
     */
    public function registerPluginRoutes(string $pluginName, array $routeHandlers): void
    {
        if (isset($this->loadedPlugins[$pluginName])) {
            $this->loadedPlugins[$pluginName]['routes'] = $routeHandlers;
        }
    }

    /**
     * Obtient les route handlers de tous les plugins
     */
    public function getPluginRouteHandlers(): array
    {
        $handlers = [];
        
        foreach ($this->loadedPlugins as $pluginName => $plugin) {
            if (isset($plugin['routes'])) {
                $handlers = array_merge($handlers, $plugin['routes']);
            }
        }
        
        return $handlers;
    }

    /**
     * Obtient les gestionnaires de routes spécifiques des plugins
     */
    public function getPluginRouteHandlerClasses(): array
    {
        $handlers = [];
        
        foreach ($this->loadedPlugins as $pluginName => $plugin) {
            if (isset($plugin['route_handlers'])) {
                $handlers[$pluginName] = $plugin['route_handlers'];
            }
        }
        
        return $handlers;
    }

    /**
     * Obtient les gestionnaires de routes publiques des plugins
     */
    public function getPublicRouteHandlers(): array
    {
        $handlers = [];
        
        foreach ($this->loadedPlugins as $pluginName => $plugin) {
            // Chercher d'abord dans route_handlers (chargé)
            if (isset($plugin['route_handlers']['public'])) {
                $handlers[$pluginName] = $plugin['route_handlers']['public'];
            }
            // Sinon chercher dans route_handlers_config (config stockée)
            elseif (isset($plugin['route_handlers_config']['public'])) {
                $handlers[$pluginName] = $plugin['route_handlers_config']['public'];
            }
            // Sinon chercher dans config (directement depuis plugin.json)
            elseif (isset($plugin['config']['route_handlers']['public'])) {
                $handlers[$pluginName] = $plugin['config']['route_handlers']['public'];
            }
        }
        
        return $handlers;
    }

    /**
     * Obtient les gestionnaires de routes authentifiées des plugins
     */
    public function getAuthenticatedRouteHandlers(): array
    {
        $handlers = [];
        
        foreach ($this->loadedPlugins as $pluginName => $plugin) {
            // Chercher d'abord dans route_handlers (chargé)
            if (isset($plugin['route_handlers']['authenticated'])) {
                $handlers[$pluginName] = $plugin['route_handlers']['authenticated'];
            }
            // Sinon chercher dans route_handlers_config (config stockée)
            elseif (isset($plugin['route_handlers_config']['authenticated'])) {
                $handlers[$pluginName] = $plugin['route_handlers_config']['authenticated'];
            }
            // Sinon chercher dans config (directement depuis plugin.json)
            elseif (isset($plugin['config']['route_handlers']['authenticated'])) {
                $handlers[$pluginName] = $plugin['config']['route_handlers']['authenticated'];
            }
        }
        
        return $handlers;
    }

    /**
     * Stocke la configuration des gestionnaires de routes (sans les instancier)
     */
    private function storePluginRouteHandlersConfig(string $pluginName, array $config): void
    {
        if (isset($config['route_handlers'])) {
            $this->loadedPlugins[$pluginName]['route_handlers_config'] = $config['route_handlers'];
        }
    }

    /**
     * Charge tous les gestionnaires de routes des plugins (à appeler après le chargement complet)
     */
    public function loadAllPluginRouteHandlers(): void
    {
        foreach ($this->loadedPlugins as $pluginName => $plugin) {
            if (isset($plugin['route_handlers_config']) && !isset($plugin['route_handlers'])) {
                $this->loadPluginRouteHandlers($pluginName, ['route_handlers' => $plugin['route_handlers_config']]);
            }
        }
    }

    /**
     * Charge les gestionnaires de routes d'un plugin
     */
    private function loadPluginRouteHandlers(string $pluginName, array $config): void
    {
        if (isset($config['route_handlers'])) {
            $routeHandlers = [];
            
            foreach ($config['route_handlers'] as $type => $handlerClass) {
                try {
                    if (class_exists($handlerClass)) {
                        $routeHandlers[$type] = $handlerClass;
                        $this->safeLog('info', "Gestionnaire de routes chargé", [
                            'plugin' => $pluginName,
                            'type' => $type,
                            'handler' => $handlerClass
                        ]);
                    } else {
                        $this->safeLog('warning', "Classe de gestionnaire de routes introuvable", [
                            'plugin' => $pluginName,
                            'type' => $type,
                            'handler' => $handlerClass
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->safeLog('error', "Erreur lors du chargement du gestionnaire de routes", [
                        'plugin' => $pluginName,
                        'type' => $type,
                        'handler' => $handlerClass,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            if (!empty($routeHandlers)) {
                $this->loadedPlugins[$pluginName]['route_handlers'] = $routeHandlers;
            }
        }
    }

    /**
     * Scan des répertoires de plugins
     */
    private function scanPluginDirectories(): array
    {
        $plugins = [];
        $directories = scandir($this->pluginsPath);
        
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === 'auth_groups' || $dir === 'Core') {
                continue;
            }
            
            $fullPath = $this->pluginsPath . $dir;
            if (is_dir($fullPath) && file_exists($fullPath . '/plugin.json')) {
                $plugins[] = $dir;
            }
        }
        
        return $plugins;
    }

    /**
     * Valide la configuration d'un plugin
     */
    private function validatePluginConfig(array $config): bool
    {
        $required = ['name', 'version', 'main_class', 'namespace'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Vérifie les dépendances d'un plugin
     */
    private function checkDependencies(array $config): bool
    {
        if (!isset($config['dependencies'])) {
            return true;
        }
        
        foreach ($config['dependencies'] as $dependency => $version) {
            if ($dependency === 'cmem2_core') {
                // Vérifier la version du core CMEM2
                continue;
            }
            
            if (!isset($this->loadedPlugins[$dependency])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Charge l'autoloader d'un plugin
     */
    private function loadPluginAutoloader(string $pluginPath): void
    {
        $autoloadFile = $pluginPath . '/autoloader.php';
        if (file_exists($autoloadFile)) {
            require_once $autoloadFile;
        }
    }

    /**
     * Liste des plugins chargés
     */
    public function getLoadedPlugins(): array
    {
        return array_map(function($plugin) {
            return [
                'name' => $plugin['config']['name'],
                'version' => $plugin['config']['version'],
                'status' => $plugin['status'],
                'description' => $plugin['config']['description'] ?? ''
            ];
        }, $this->loadedPlugins);
    }
}