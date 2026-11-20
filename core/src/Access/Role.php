<?php

declare(strict_types=1);

namespace Emp\Core\Access;

/**
 * Livelli di accesso del servizio Accesso (allegato Account, ruoli e sicurezza, §2).
 * Le mansioni di negozio (Cassiere, Magazziniere…) sono i "ruoli" con permessi: vedi JobRoleService.
 *
 * - tecnico: unico; Control Center, plugin, sicurezza. Crea e gestisce amministratori e operatori.
 *   Non assume automaticamente le funzioni gestionali.
 * - amministratore: organizzazione e utenti operativi. Crea e gestisce gli operatori.
 *   Non può gestire il Tecnico né le funzioni tecniche.
 * - operatore: funzioni di cassa, magazzino, ecc. secondo i permessi dei suoi ruoli.
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

    /** @return list<string> livelli che un utente con $actorRole può creare e gestire */
    public static function managedRoles(string $actorRole): array
    {
        return match ($actorRole) {
            self::TECNICO => [self::AMMINISTRATORE, self::OPERATORE],
            self::AMMINISTRATORE => [self::OPERATORE],
            default => [],
        };
    }

    public static function canManage(string $actorRole, string $targetRole): bool
    {
        return in_array($targetRole, self::managedRoles($actorRole), true);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::TECNICO => 'Amministratore Tecnico',
            self::AMMINISTRATORE => 'Amministratore Operativo',
            self::OPERATORE => 'Operatore',
            default => $role,
        };
    }
}
