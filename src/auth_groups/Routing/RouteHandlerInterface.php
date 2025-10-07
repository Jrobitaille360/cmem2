<?php

namespace AuthGroups\Routing;

interface RouteHandlerInterface 
{
    /**
     * Retourne true si la route a été traitée, false sinon
     */
    public function handle(array $request): bool;
    public function canHandle(string $controller): bool;
}