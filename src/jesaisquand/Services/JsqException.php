<?php

namespace Jesaisquand\Services;

/** Erreur métier portant son code HTTP (traduite en Response::error par le contrôleur). */
class JsqException extends \RuntimeException
{
    public function __construct(string $message, private int $http, private ?array $errors = null)
    {
        parent::__construct($message);
    }

    public function http(): int
    {
        return $this->http;
    }

    public function errors(): ?array
    {
        return $this->errors;
    }
}
