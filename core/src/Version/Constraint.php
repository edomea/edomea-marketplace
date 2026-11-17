<?php

declare(strict_types=1);

namespace Emp\Core\Version;

/**
 * Vincolo di versione: condizioni separate da spazi, tutte da soddisfare.
 * Operatori: =, >, >=, <, <=, ^. Senza operatore vale "=".
 * ^1.2.3 = >=1.2.3 <2.0.0 ; ^0.2.3 = >=0.2.3 <0.3.0 ; ^0.0.3 = >=0.0.3 <0.0.4
 */
final class Constraint
{
    /** @param list<array{string, Version}> $conditions */
    private function __construct(private readonly array $conditions, private readonly string $text)
    {
    }

    public static function parse(string $text): self
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 128) {
            throw new InvalidVersion('Vincolo di versione vuoto o troppo lungo');
        }
        $conditions = [];
        foreach (preg_split('/\s+/', $text) ?: [] as $part) {
            if (!preg_match('/^(>=|<=|>|<|=|\^)?(.+)$/', $part, $m)) {
                throw new InvalidVersion(sprintf('Vincolo non valido: "%s"', $text));
            }
            $op = $m[1] === '' ? '=' : $m[1];
            $v = Version::parse($m[2]);
            if ($op === '^') {
                $conditions[] = ['>=', $v];
                $conditions[] = ['<', self::caretUpper($v)];
            } else {
                $conditions[] = [$op, $v];
            }
        }

        return new self($conditions, $text);
    }

    public function isSatisfiedBy(Version $v): bool
    {
        foreach ($this->conditions as [$op, $bound]) {
            $c = $v->compare($bound);
            $ok = match ($op) {
                '=' => $c === 0,
                '>' => $c > 0,
                '>=' => $c >= 0,
                '<' => $c < 0,
                '<=' => $c <= 0,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return $this->text;
    }

    private static function caretUpper(Version $v): Version
    {
        if ($v->major > 0) {
            return Version::parse(($v->major + 1) . '.0.0-alpha.0');
        }
        if ($v->minor > 0) {
            return Version::parse('0.' . ($v->minor + 1) . '.0-alpha.0');
        }

        return Version::parse('0.0.' . ($v->patch + 1) . '-alpha.0');
    }
}
