<?php

declare(strict_types=1);

namespace Emp\Core\Access;

/** Errore del servizio Accesso, con il codice HTTP da restituire. */
final class AccessException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
