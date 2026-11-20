<?php

declare(strict_types=1);

namespace Emp\Plugin\Studio;

/**
 * Codici a barre GS1: controllo, normalizzazione ed EAN interni.
 *
 * - Si accettano EAN-13, EAN-8 e UPC-A (12 cifre, convertito in EAN-13 con uno 0 davanti).
 * - EAN interni: circolazione ristretta GS1, prefisso 209 (come edomea-ean), solo per articoli senza codice
 *   del produttore. Non vanno usati fuori dal negozio.
 */
final class Ean
{
    public const INTERNAL_PREFIX = '209';

    /** Codice pulito e valido (EAN-13 o EAN-8), oppure null se non è un codice GS1 valido. */
    public static function normalize(string $code): ?string
    {
        $c = preg_replace('/[\s-]+/', '', $code) ?? '';
        if (!preg_match('/^\d{8}$|^\d{12,14}$/', $c)) {
            return null;
        }
        if (strlen($c) === 12) {
            $c = '0' . $c; // UPC-A → EAN-13
        }
        if (strlen($c) === 14) {
            if ($c[0] !== '0') {
                return null; // GTIN-14 di imballo: non è un codice di vendita al dettaglio
            }
            $c = substr($c, 1);
        }

        return self::checkDigit(substr($c, 0, -1)) === (int) substr($c, -1) ? $c : null;
    }

    /** Cifra di controllo GS1 (modulo 10, pesi 3 e 1 da destra). */
    public static function checkDigit(string $body): int
    {
        $sum = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $body[$len - 1 - $i];
            $sum += $i % 2 === 0 ? $digit * 3 : $digit;
        }

        return (10 - $sum % 10) % 10;
    }

    /** EAN-13 interno dal numero progressivo (1 → 2090000000016). */
    public static function internal(int $sequence): string
    {
        if ($sequence < 1 || $sequence > 999_999_999) {
            throw new \InvalidArgumentException('Progressivo EAN interno fuori intervallo');
        }
        $body = self::INTERNAL_PREFIX . str_pad((string) $sequence, 9, '0', STR_PAD_LEFT);

        return $body . self::checkDigit($body);
    }

    public static function isInternal(string $ean): bool
    {
        return strlen($ean) === 13 && str_starts_with($ean, self::INTERNAL_PREFIX);
    }
}
