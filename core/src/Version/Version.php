<?php

declare(strict_types=1);

namespace Emp\Core\Version;

/**
 * Versione semantica MAJOR.MINOR.PATCH con suffisso pre-release opzionale
 * (alpha.N, beta.N, rc.N). Una pre-release è inferiore alla versione finale.
 */
final class Version
{
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:alpha|beta|rc)\.(?:0|[1-9]\d*)))?$/';

    /** @param list<string|int> $pre */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        private readonly array $pre,
    ) {
    }

    public static function parse(string $text): self
    {
        if (strlen($text) > 64 || !preg_match(self::PATTERN, $text, $m)) {
            throw new InvalidVersion(sprintf('Versione non valida: "%s"', $text));
        }
        $pre = [];
        if (isset($m[4]) && $m[4] !== '') {
            [$label, $n] = explode('.', $m[4]);
            $pre = [$label, (int) $n];
        }

        return new self(self::toInt($m[1]), self::toInt($m[2]), self::toInt($m[3]), $pre);
    }

    public static function isValid(string $text): bool
    {
        try {
            self::parse($text);

            return true;
        } catch (InvalidVersion) {
            return false;
        }
    }

    public function isPrerelease(): bool
    {
        return $this->pre !== [];
    }

    /** @return int -1, 0 o 1 */
    public function compare(self $other): int
    {
        $c = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if ($c !== 0) {
            return $c;
        }
        if ($this->pre === [] || $other->pre === []) {
            // La versione finale è maggiore di qualsiasi pre-release.
            return ($this->pre === [] ? 1 : 0) - ($other->pre === [] ? 1 : 0);
        }
        $order = ['alpha' => 0, 'beta' => 1, 'rc' => 2];

        return [$order[$this->pre[0]], $this->pre[1]] <=> [$order[$other->pre[0]], $other->pre[1]];
    }

    public function __toString(): string
    {
        $base = "{$this->major}.{$this->minor}.{$this->patch}";

        return $this->pre === [] ? $base : $base . '-' . $this->pre[0] . '.' . $this->pre[1];
    }

    private static function toInt(string $digits): int
    {
        if (strlen($digits) > 9) {
            throw new InvalidVersion('Numero di versione troppo grande');
        }

        return (int) $digits;
    }
}
