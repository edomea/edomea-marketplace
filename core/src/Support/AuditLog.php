<?php

declare(strict_types=1);

namespace Emp\Core\Support;

/**
 * Registro di tracciabilità del Core (tabella core_audit).
 * Regola Edomea: ogni modifica registra chi, quando, cosa, valore prima/dopo.
 * Non registrare mai password, chiavi o token.
 */
final class AuditLog
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table,
        private readonly Clock $clock,
    ) {
    }

    public function record(
        string $actor,
        string $module,
        string $action,
        string $entity,
        string $entityId,
        int $storeId = 0,
        ?string $before = null,
        ?string $after = null,
    ): void {
        $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, occurred_at, actor, module, action, entity, entity_id, store_id, before_value, after_value)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        )->execute([Uuid::v4(), $this->clock->nowForDatabase(), $actor, $module, $action, $entity, $entityId, $storeId, $before, $after]);
    }
}
