<?php

declare(strict_types=1);

namespace Emp\Core\Module;

use Emp\Core\Version\Constraint;
use Emp\Core\Version\InvalidVersion;
use Emp\Core\Version\Version;

/**
 * Manifest emp-module.json validato. Vedi docs/architettura-moduli.md §2.
 */
final class Manifest
{
    public const FILE = 'emp-module.json';
    public const CORE_ID = 'emp-core';
    private const MAX_BYTES = 65536;
    private const ID_PATTERN = '/^[a-z][a-z0-9-]{2,63}$/';
    private const PREFIX_PATTERN = '/^[a-z][a-z0-9_]{0,30}_$/';
    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';
    private const NAMESPACE_PATTERN = '/^([A-Za-z_][A-Za-z0-9_]*\\\\)+$/';
    private const RELPATH_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\/-]*$/';

    /**
     * @param array<string, Constraint> $requires
     * @param array<string, string> $autoload namespace => cartella relativa
     * @param list<string> $permissions
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Version $version,
        public readonly string $type,
        public readonly string $description,
        public readonly Constraint $php,
        public readonly array $requires,
        public readonly string $class,
        public readonly array $autoload,
        public readonly ?string $tablePrefix,
        public readonly ?string $migrations,
        public readonly array $permissions,
        public readonly string $directory,
    ) {
    }

    public function isCore(): bool
    {
        return $this->type === 'core';
    }

    public static function fromDirectory(string $directory): self
    {
        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
        if (!is_file($file) || is_link($file)) {
            throw new ManifestException(sprintf('Manifest mancante: %s', $file));
        }
        $size = filesize($file);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new ManifestException(sprintf('Manifest troppo grande: %s', $file));
        }
        $json = file_get_contents($file);
        if ($json === false) {
            throw new ManifestException(sprintf('Manifest illeggibile: %s', $file));
        }
        $manifest = self::fromJson($json, $directory);
        if (basename(rtrim($directory, '/\\')) !== $manifest->id) {
            throw new ManifestException(sprintf('La cartella "%s" non coincide con l\'id "%s"', basename($directory), $manifest->id));
        }

        return $manifest;
    }

    public static function fromJson(string $json, string $directory = ''): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ManifestException('JSON non valido: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ManifestException('Il manifest deve essere un oggetto JSON');
        }

        $allowed = ['schema', 'id', 'name', 'version', 'type', 'description', 'php', 'requires', 'class', 'autoload', 'table_prefix', 'migrations', 'permissions'];
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new ManifestException('Campi sconosciuti: ' . implode(', ', $unknown));
        }
        if (($data['schema'] ?? null) !== 1) {
            throw new ManifestException('Campo "schema" mancante o non supportato (atteso 1)');
        }

        $id = self::str($data, 'id');
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new ManifestException(sprintf('Id non valido: "%s"', $id));
        }
        $type = self::str($data, 'type');
        if (!in_array($type, ['core', 'plugin'], true)) {
            throw new ManifestException('Tipo non valido: atteso "core" o "plugin"');
        }
        if (($type === 'core') !== ($id === self::CORE_ID)) {
            throw new ManifestException('Solo "emp-core" può essere di tipo "core"');
        }

        try {
            $version = Version::parse(self::str($data, 'version'));
            $php = Constraint::parse(self::str($data, 'php'));
            $requires = [];
            $rawRequires = $data['requires'] ?? [];
            if (!is_array($rawRequires) || ($rawRequires !== [] && array_is_list($rawRequires))) {
                throw new ManifestException('"requires" deve essere un oggetto');
            }
            foreach ($rawRequires as $dep => $constraint) {
                if (!is_string($dep) || !preg_match(self::ID_PATTERN, $dep) || !is_string($constraint)) {
                    throw new ManifestException('Dipendenza non valida in "requires"');
                }
                if ($dep === $id) {
                    throw new ManifestException('Un modulo non può dipendere da se stesso');
                }
                $requires[$dep] = Constraint::parse($constraint);
            }
        } catch (InvalidVersion $e) {
            throw new ManifestException($e->getMessage(), 0, $e);
        }
        if ($type === 'plugin' && !isset($requires[self::CORE_ID])) {
            throw new ManifestException('Ogni plugin deve dichiarare la dipendenza da "emp-core"');
        }
        if ($type === 'core' && $requires !== []) {
            throw new ManifestException('Il Core non può avere dipendenze');
        }

        $class = self::str($data, 'class');
        if (!preg_match(self::CLASS_PATTERN, $class)) {
            throw new ManifestException('Classe non valida');
        }

        $autoload = $data['autoload'] ?? null;
        if (!is_array($autoload) || $autoload === [] || array_is_list($autoload)) {
            throw new ManifestException('"autoload" deve essere un oggetto namespace => cartella');
        }
        foreach ($autoload as $ns => $path) {
            if (!is_string($ns) || !preg_match(self::NAMESPACE_PATTERN, $ns) || !is_string($path) || !self::safeRelative($path)) {
                throw new ManifestException('Voce "autoload" non valida');
            }
        }
        $classCovered = false;
        foreach (array_keys($autoload) as $ns) {
            $classCovered = $classCovered || str_starts_with($class, $ns);
        }
        if (!$classCovered) {
            throw new ManifestException('La classe del modulo non è coperta da "autoload"');
        }

        $prefix = $data['table_prefix'] ?? null;
        if ($prefix !== null && (!is_string($prefix) || !preg_match(self::PREFIX_PATTERN, $prefix))) {
            throw new ManifestException('"table_prefix" non valido (minuscole, cifre, _ finale)');
        }
        $migrations = $data['migrations'] ?? null;
        if ($migrations !== null) {
            if (!is_string($migrations) || !self::safeRelative($migrations)) {
                throw new ManifestException('"migrations" non valido');
            }
            if ($prefix === null) {
                throw new ManifestException('Un modulo con migrazioni deve dichiarare "table_prefix"');
            }
        }

        $permissions = $data['permissions'] ?? [];
        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new ManifestException('"permissions" deve essere una lista');
        }
        foreach ($permissions as $p) {
            if (!is_string($p) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $p)) {
                throw new ManifestException('Permesso non valido');
            }
        }

        $description = $data['description'] ?? '';
        if (!is_string($description) || mb_strlen($description) > 500) {
            throw new ManifestException('"description" non valida');
        }

        return new self(
            $id,
            self::str($data, 'name'),
            $version,
            $type,
            $description,
            $php,
            $requires,
            $class,
            $autoload,
            $prefix,
            $migrations,
            array_values(array_unique($permissions)),
            $directory,
        );
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $v = $data[$key] ?? null;
        if (!is_string($v) || $v === '' || mb_strlen($v) > 200) {
            throw new ManifestException(sprintf('Campo "%s" mancante o non valido', $key));
        }

        return $v;
    }

    private static function safeRelative(string $path): bool
    {
        if (!preg_match(self::RELPATH_PATTERN, $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }
}
