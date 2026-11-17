<?php

declare(strict_types=1);

namespace Emp\Core\Access;

/** Utente autenticato o letto dal registro (senza dati segreti). */
final class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $role,
        public readonly bool $active,
        public readonly bool $mustChangePassword,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['username'],
            (string) $row['display_name'],
            (string) $row['role'],
            (int) $row['active'] === 1,
            (int) $row['must_change_password'] === 1,
        );
    }

    /** @return array<string, mixed> forma pubblica per le API */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nome' => $this->displayName,
            'ruolo' => $this->role,
            'ruolo_etichetta' => Role::label($this->role),
            'attivo' => $this->active,
            'cambio_password' => $this->mustChangePassword,
        ];
    }
}
