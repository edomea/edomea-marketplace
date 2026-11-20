<?php

declare(strict_types=1);

namespace Emp\Core\Brand;

use Emp\Core\Support\AuditLog;
use Emp\Core\Support\Clock;

/**
 * Marchio white label: nome dell'attività, colore principale e logo, mostrati nelle pagine
 * di /admin e /techadmin (intestazione, titolo della scheda). Lo modifica solo il Tecnico.
 * Senza impostazioni si usa il marchio predefinito del software.
 */
final class BrandService
{
    public const DEFAULT_NAME = 'EMP';
    public const DEFAULT_COLOR = '#1e3a5f';
    public const MAX_LOGO_BYTES = 524288;
    public const MAX_LOGO_SIDE = 2000;
    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

    private readonly string $table;

    public function __construct(private readonly \PDO $pdo, string $tablePrefix, private readonly Clock $clock, private readonly AuditLog $audit)
    {
        $this->table = $tablePrefix . 'marchio';
    }

    /** @return array{nome: string, colore: string, logo: ?string, predefinito: bool} logo = impronta (per l'indirizzo) o null */
    public function get(): array
    {
        $row = $this->row();
        if ($row === null) {
            return ['nome' => self::DEFAULT_NAME, 'colore' => self::DEFAULT_COLOR, 'logo' => null, 'predefinito' => true];
        }

        return [
            'nome' => (string) $row['nome'],
            'colore' => (string) $row['colore'],
            'logo' => $row['logo_impronta'] !== null ? substr((string) $row['logo_impronta'], 0, 16) : null,
            'predefinito' => false,
        ];
    }

    public function update(string $actor, string $name, string $color): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
            throw new BrandException('Nome non valido: da 1 a 80 caratteri, senza < e >');
        }
        $color = strtolower(trim($color));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new BrandException('Colore non valido (formato #RRGGBB)');
        }
        if (self::contrastWithWhite($color) < 4.5) {
            throw new BrandException('Colore troppo chiaro: il testo bianco dell\'intestazione non si leggerebbe. Scegliere un colore più scuro.');
        }
        $before = $this->get();
        $this->save(['nome' => $name, 'colore' => $color], $actor);
        $this->audit->record($actor, 'emp-core', 'marchio.modifica', 'marchio', '1', 0,
            json_encode(['nome' => $before['nome'], 'colore' => $before['colore']], JSON_UNESCAPED_UNICODE) ?: null,
            json_encode(['nome' => $name, 'colore' => $color], JSON_UNESCAPED_UNICODE) ?: null);
    }

    public function setLogo(string $actor, string $bytes): void
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_LOGO_BYTES) {
            throw new BrandException('Logo vuoto o troppo grande (massimo 512 KB)');
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || !isset(self::TYPES[$info[2]])) {
            throw new BrandException('Formato del logo non ammesso: PNG, JPEG o WebP');
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > self::MAX_LOGO_SIDE || $info[1] > self::MAX_LOGO_SIDE) {
            throw new BrandException('Dimensioni del logo non ammesse: da 16 a 2000 pixel per lato');
        }
        $hash = hash('sha256', $bytes);
        $this->save(['logo_tipo' => self::TYPES[$info[2]], 'logo' => base64_encode($bytes), 'logo_impronta' => $hash], $actor);
        $this->audit->record($actor, 'emp-core', 'marchio.logo', 'marchio', '1', 0, null,
            sprintf('%s %dx%d, %d byte, SHA-256 %s', self::TYPES[$info[2]], $info[0], $info[1], strlen($bytes), $hash));
    }

    public function removeLogo(string $actor): void
    {
        if ($this->row() === null) {
            return;
        }
        $this->save(['logo_tipo' => null, 'logo' => null, 'logo_impronta' => null], $actor);
        $this->audit->record($actor, 'emp-core', 'marchio.logo_rimosso', 'marchio', '1', 0);
    }

    /** Torna al marchio predefinito del software (nome, colore e logo). */
    public function reset(string $actor): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = 1")->execute();
        $this->audit->record($actor, 'emp-core', 'marchio.predefinito', 'marchio', '1', 0);
    }

    /** @return array{0: string, 1: string}|null tipo MIME e contenuto del logo */
    public function logo(): ?array
    {
        $row = $this->row();
        if ($row === null || $row['logo'] === null || $row['logo_tipo'] === null) {
            return null;
        }
        $bytes = base64_decode((string) $row['logo'], true);

        return $bytes === false ? null : [(string) $row['logo_tipo'], $bytes];
    }

    /** Rapporto di contrasto WCAG tra il colore e il bianco. */
    public static function contrastWithWhite(string $hex): float
    {
        $channel = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $channel((int) hexdec(substr($hex, 1, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 3, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 5, 2)));

        return 1.05 / ($l + 0.05);
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->table} WHERE id = 1");
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

        return is_array($row) ? $row : null;
    }

    /** @param array<string, ?string> $values */
    private function save(array $values, string $actor): void
    {
        $now = $this->clock->nowForDatabase();
        if ($this->row() === null) {
            $this->pdo->prepare("INSERT INTO {$this->table} (id, nome, colore, logo_tipo, logo, logo_impronta, updated_at, updated_by) VALUES (1, ?, ?, NULL, NULL, NULL, ?, ?)")
                ->execute([self::DEFAULT_NAME, self::DEFAULT_COLOR, $now, $actor]);
        }
        $sets = [];
        $args = [];
        foreach ($values as $column => $value) {
            $sets[] = $column . ' = ?';
            $args[] = $value;
        }
        $args[] = $now;
        $args[] = $actor;
        $this->pdo->prepare("UPDATE {$this->table} SET " . implode(', ', $sets) . ', updated_at = ?, updated_by = ? WHERE id = 1')->execute($args);
    }
}
