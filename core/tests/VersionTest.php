<?php

declare(strict_types=1);

namespace Emp\Core\Tests;

use Emp\Core\Version\Constraint;
use Emp\Core\Version\InvalidVersion;
use Emp\Core\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    /** Regole delle versioni e dei vincoli (semver con pre-release). */
    public static function comparisons(): array
    {
        return [
            ['1.0.0', '1.0.0', 0],
            ['1.0.1', '1.0.0', 1],
            ['1.10.0', '1.9.9', 1],
            ['0.4.0-alpha.4', '0.4.0', -1],
            ['0.4.0-alpha.4', '0.4.0-alpha.10', -1],
            ['0.4.0-beta.1', '0.4.0-alpha.9', 1],
            ['0.4.0-rc.1', '0.4.0-beta.9', 1],
            ['2.0.0', '10.0.0', -1],
        ];
    }

    #[DataProvider('comparisons')]
    public function testCompare(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, Version::parse($a)->compare(Version::parse($b)));
        self::assertSame(-$expected, Version::parse($b)->compare(Version::parse($a)));
    }

    public static function invalid(): array
    {
        return [[''], ['1'], ['1.0'], ['01.0.0'], ['1.0.0-'], ['1.0.0-dev'], ['1.0.0-alpha'], ['v1.0.0'], ['1.0.0 '], ['9999999999.0.0']];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $text): void
    {
        $this->expectException(InvalidVersion::class);
        Version::parse($text);
    }

    public function testRoundTrip(): void
    {
        self::assertSame('0.4.0-alpha.4', (string) Version::parse('0.4.0-alpha.4'));
    }

    public static function constraints(): array
    {
        return [
            ['>=0.1.0 <0.2.0', '0.1.0', true],
            ['>=0.1.0 <0.2.0', '0.1.9', true],
            ['>=0.1.0 <0.2.0', '0.2.0', false],
            ['>=0.1.0 <0.2.0', '0.0.9', false],
            ['^1.2.0', '1.9.0', true],
            ['^1.2.0', '2.0.0', false],
            ['^1.2.0', '2.0.0-alpha.1', false],
            ['^1.2.0', '1.1.9', false],
            ['^0.2.3', '0.2.9', true],
            ['^0.2.3', '0.3.0', false],
            ['^0.0.3', '0.0.3', true],
            ['^0.0.3', '0.0.4', false],
            ['1.2.3', '1.2.3', true],
            ['=1.2.3', '1.2.4', false],
            ['>1.0.0', '1.0.0', false],
            ['<=1.0.0', '1.0.0', true],
            ['>=8.2.0', '8.3.6', true],
        ];
    }

    #[DataProvider('constraints')]
    public function testConstraint(string $constraint, string $version, bool $expected): void
    {
        self::assertSame($expected, Constraint::parse($constraint)->isSatisfiedBy(Version::parse($version)));
    }

    public function testConstraintRejectsGarbage(): void
    {
        $this->expectException(InvalidVersion::class);
        Constraint::parse('>= 1.0.0');
    }
}
