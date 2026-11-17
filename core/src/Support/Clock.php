<?php

declare(strict_types=1);

namespace Emp\Core\Support;

/**
 * Orologio dell'installazione. Regola Edomea: data e ora sono sempre impostate
 * esplicitamente dal Core con il fuso dell'installazione, mai dai valori
 * predefiniti del database.
 */
class Clock
{
    private readonly \DateTimeZone $zone;

    public function __construct(string $timezone = 'Europe/Rome')
    {
        $this->zone = new \DateTimeZone($timezone);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->zone);
    }

    /** Formato per il database: Y-m-d H:i:s nel fuso dell'installazione. */
    public function nowForDatabase(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /** Formato per l'interfaccia: gg/mm/aaaa HH:MM. */
    public static function formatDateTime(\DateTimeInterface $dt): string
    {
        return $dt->format('d/m/Y H:i');
    }

    /** Formato per l'interfaccia: gg/mm/aaaa. */
    public static function formatDate(\DateTimeInterface $dt): string
    {
        return $dt->format('d/m/Y');
    }
}
