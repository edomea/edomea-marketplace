<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

/**
 * Crea cartelle di moduli temporanee per i test.
 */
final class Fixtures
{
    /** @var list<string> */
    private static array $dirs = [];

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/emp-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        self::$dirs[] = $dir;

        return $dir;
    }

    /** @param array<string, mixed> $overrides */
    public static function manifest(string $id, array $overrides = []): array
    {
        $isCore = $id === 'emp-core';
        $ns = 'Emp\\Test\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $id))) . '\\';

        return array_merge([
            'schema' => 1,
            'id' => $id,
            'name' => $id,
            'version' => '0.1.0',
            'type' => $isCore ? 'core' : 'plugin',
            'php' => '>=8.2.0',
            'requires' => $isCore ? [] : ['emp-core' => '>=0.1.0 <0.2.0'],
            'class' => $ns . 'Module',
            'autoload' => [$ns => 'src/'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, string> $files percorso relativo => contenuto
     */
    public static function writeModule(string $modulesDir, array $manifest, array $files = []): string
    {
        $dir = $modulesDir . '/' . $manifest['id'];
        @mkdir($dir . '/src', 0700, true);
        file_put_contents($dir . '/emp-module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach ($files as $rel => $content) {
            @mkdir(dirname($dir . '/' . $rel), 0700, true);
            file_put_contents($dir . '/' . $rel, $content);
        }

        return $dir;
    }

    /** Copia ricorsiva (per mettere Core e plugin reali in una cartella modules/). */
    public static function copy(string $from, string $to): void
    {
        @mkdir($to, 0700, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($from) + 1);
            if (str_starts_with($rel, 'vendor') || str_starts_with($rel, 'tests')) {
                continue;
            }
            $target = $to . '/' . $rel;
            $item->isDir() ? @mkdir($target, 0700, true) : copy($item->getPathname(), $target);
        }
    }

    public static function cleanup(): void
    {
        foreach (self::$dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }
        self::$dirs = [];
    }
}
