<?php

declare(strict_types=1);

namespace Emp\Core\Http;

/** Errore previsto di un'API di plugin: il messaggio va all'utente, il codice HTTP alla risposta. */
final class ApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
