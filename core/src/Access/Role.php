<?php

declare(strict_types=1);

namespace Emp\Core\Access;

/**
 * Ruoli del servizio Accesso (allegato Account, ruoli e sicurezza, §2).
 *
 * - tecnico: unico; Control Center, plugin, sicurezza. Crea e gestisce gli amministratori.
 *   Non assume automaticamente le funzioni gestionali.
 * - amministratore: organizzazione e utenti operativi. Crea e gestisce gli operatori.
 *   Non può gestire il Tecnico né le funzioni tecniche.
 * - operatore: funzioni di cassa, magazzino, ecc. secondo i permessi dei plugin.
 */
final class Role
{
    public const TECNICO = 'tecnico';
    public const AMMINISTRATORE = 'amministratore';
    public const OPERATORE = 'operatore';

    public const ALL = [self::TECNICO, self::AMMINISTRATORE, self::OPERATORE];

    /** Ruolo che un utente con $actorRole può creare e gestire. */
    public static function managedBy(string $actorRole): ?string
    {
        return match ($actorRole) {
            self::TECNICO => self::AMMINISTRATORE,
            self::AMMINISTRATORE => self::OPERATORE,
            default => null,
        };
    }

    public static function canManage(string $actorRole, string $targetRole): bool
    {
        return self::managedBy($actorRole) === $targetRole;
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::TECNICO => 'Amministratore Tecnico',
            self::AMMINISTRATORE => 'Amministratore',
            self::OPERATORE => 'Operatore',
            default => $role,
        };
    }
}
