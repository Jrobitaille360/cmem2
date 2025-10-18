<?php

namespace Core;

interface PluginInterface
{
    /**
     * Initialise le plugin
     */
    public function initialize(): void;

    /**
     * Retourne les route handlers du plugin
     */
    public function getRouteHandlers(): array;

    /**
     * Retourne les informations du plugin
     */
    public function getInfo(): array;

    /**
     * Désactive le plugin
     */
    public function deactivate(): void;

    /**
     * Retourne les dépendances du plugin
     */
    public function getDependencies(): array;
}