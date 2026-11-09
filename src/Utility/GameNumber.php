<?php
declare(strict_types=1);

namespace App\Utility;

/**
 * Numbers the way the game writes them: at most 3 digits and a letter for the
 * size (K, M, B, T, Q), with the zeros after the comma dropped. 1200 is 1,2K,
 * 12345 is 12,3K, 1000000 is 1M, 10000000000 is 10B.
 */
final class GameNumber
{
    private const SUFFIXES = ['', 'K', 'M', 'B', 'T', 'Q'];

    /**
     * A count in units (items, Silver in units) in the game's notation.
     *
     * @param int|float|string|null $value Number.
     * @return string
     */
    public static function format(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $n = (float)$value;
        if ($n < 0) {
            return '-' . self::format(-$n);
        }
        if ($n < 999.5) {
            return (string)(int)round($n);
        }

        $tier = 0;
        $scaled = $n;
        $top = count(self::SUFFIXES) - 1;
        while ($scaled >= 999.5 && $tier < $top) {
            $scaled /= 1000;
            $tier++;
        }
        $places = $scaled < 9.995 ? 2 : ($scaled < 99.95 ? 1 : 0);
        $formatted = number_format($scaled, $places, ',', '');
        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted . self::SUFFIXES[$tier];
    }

    /**
     * An amount of Silver kept in millions, as the clan bank keeps it: 1 is 1M,
     * 1250 is 1,25B, 10000 is 10B.
     *
     * @param int|float|string|null $millions Millions of Silver.
     * @return string
     */
    public static function silver(int|float|string|null $millions): string
    {
        $value = (int)round((float)$millions);

        return $value === 0 ? '0' : self::format($value * 1_000_000);
    }
}
