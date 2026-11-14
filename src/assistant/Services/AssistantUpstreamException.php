<?php

namespace Assistant\Services;

use RuntimeException;

/** Échec de l'appel modèle avant toute sortie → 502. */
class AssistantUpstreamException extends RuntimeException
{
}
