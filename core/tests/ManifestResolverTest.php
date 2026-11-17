<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Module\Manifest;
use Emp\Core\Module\ManifestException;
use Emp\Core\Module\Registry;
use Emp\Core\Module\ResolutionException;
use Emp\Core\Module\Resolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManifestResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private static function m(string $id, array $overrides = []): Manifest
    {
        return Manifest::fromJson((string) json_encode(Fixtures::manifest($id, $overrides)), '/tmp/' . $id);
    }

    public function testRealManifestsAreValid(): void
    {
        $modules = Fixtures::tempDir();
        Fixtures::copy(dirname(__DIR__), $modules . '/emp-core');
        Fixtures::copy(dirname(__DIR__, 2) . '/plugins/emp-esempio', $modules . '/emp-esempio');
        $found = (new Registry($modules))->discover();
        self::assertSame(['emp-core', 'emp-esempio'], array_map(static fn (Manifest $m): string => $m->id, $found));
        self::assertTrue($found[0]->isCore());
    }

    public static function invalidManifests(): array
    {
        return [
            'id maiuscolo' => [['id' => 'Emp-X']],
            'plugin senza core' => [['requires' => []]],
            'plugin di tipo core' => [['type' => 'core']],
            'campo sconosciuto' => [['extra' => 1]],
            'schema' => [['schema' => 2]],
            'autoload con ..' => [['autoload' => ['Emp\\Test\\EmpX\\' => '../fuori']]],
            'classe fuori autoload' => [['class' => 'Altro\\Module']],
            'migrazioni senza prefisso' => [['migrations' => 'migrations']],
            'prefisso non valido' => [['table_prefix' => 'X-']],
            'dipende da se stesso' => [['requires' => ['emp-core' => '>=0.1.0', 'emp-x' => '>=0.1.0']]],
            'versione non valida' => [['version' => '1.0']],
            'permesso non valido' => [['permissions' => ['Tutto']]],
        ];
    }

    #[DataProvider('invalidManifests')]
    public function testRejectsInvalidManifest(array $overrides): void
    {
        $this->expectException(ManifestException::class);
        self::m('emp-x', $overrides);
    }

    public function testFolderMustMatchId(): void
    {
        $modules = Fixtures::tempDir();
        Fixtures::writeModule($modules, Fixtures::manifest('emp-uno'));
        rename($modules . '/emp-uno', $modules . '/altro-nome');
        $this->expectException(ManifestException::class);
        (new Registry($modules))->discover();
    }

    public function testOrderCoreFirstThenDependenciesAlphabetical(): void
    {
        $plan = (new Resolver())->resolve([
            self::m('emp-pos', ['requires' => ['emp-core' => '>=0.1.0', 'emp-magazzino' => '^0.1.0']]),
            self::m('emp-zeta'),
            self::m('emp-magazzino'),
            self::m('emp-alfa'),
            self::m('emp-core'),
        ]);
        self::assertSame(
            ['emp-core', 'emp-alfa', 'emp-magazzino', 'emp-pos', 'emp-zeta'],
            array_map(static fn (Manifest $m): string => $m->id, $plan),
        );
    }

    public function testSelectionAddsDependencies(): void
    {
        $plan = (new Resolver())->resolve([
            self::m('emp-core'),
            self::m('emp-magazzino'),
            self::m('emp-pos', ['requires' => ['emp-core' => '>=0.1.0', 'emp-magazzino' => '>=0.1.0']]),
            self::m('emp-stat'),
        ], ['emp-pos']);
        self::assertSame(['emp-core', 'emp-magazzino', 'emp-pos'], array_map(static fn (Manifest $m): string => $m->id, $plan));
    }

    public function testCoreRequired(): void
    {
        $this->expectExceptionMessage('installare prima il Core');
        (new Resolver())->resolve([self::m('emp-pos')]);
    }

    public function testMissingDependency(): void
    {
        $this->expectException(ResolutionException::class);
        (new Resolver())->resolve([self::m('emp-core'), self::m('emp-pos', ['requires' => ['emp-core' => '>=0.1.0', 'emp-magazzino' => '>=0.1.0']])]);
    }

    public function testIncompatibleCore(): void
    {
        $this->expectExceptionMessage('richiede "emp-core"');
        (new Resolver())->resolve([self::m('emp-core', ['version' => '0.2.0']), self::m('emp-pos')]);
    }

    public function testCycleDetected(): void
    {
        $this->expectExceptionMessage('Dipendenze circolari tra: emp-a, emp-b');
        (new Resolver())->resolve([
            self::m('emp-core'),
            self::m('emp-a', ['requires' => ['emp-core' => '>=0.1.0', 'emp-b' => '>=0.1.0']]),
            self::m('emp-b', ['requires' => ['emp-core' => '>=0.1.0', 'emp-a' => '>=0.1.0']]),
        ]);
    }

    public function testSharedTablePrefixRejected(): void
    {
        $this->expectExceptionMessage('Prefisso tabelle "pos_"');
        (new Resolver())->resolve([
            self::m('emp-core'),
            self::m('emp-pos', ['table_prefix' => 'pos_']),
            self::m('emp-pos2', ['table_prefix' => 'pos_']),
        ]);
    }

    public function testPhpVersionChecked(): void
    {
        $this->expectExceptionMessage('richiede PHP');
        (new Resolver())->resolve([self::m('emp-core', ['php' => '>=9.0.0'])], null, '8.3.6');
    }

    public function testDuplicateRejected(): void
    {
        $this->expectExceptionMessage('Modulo duplicato');
        (new Resolver())->resolve([self::m('emp-core'), self::m('emp-core')]);
    }
}
