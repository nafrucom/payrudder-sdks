<?php

declare(strict_types=1);

namespace Payrudder\Cache;

/**
 * Exact decimal strings added and compared as scaled integers, without bcmath.
 *
 * @internal
 */
final class Decimal
{
    /** $a + $b, or null when either is not a plain decimal or the sum would not fit in 18 digits. */
    public static function add(string $a, string $b): ?string
    {
        $s = self::scaled($a, $b);
        if ($s === null) {
            return null;
        }
        [$x, $y, $scale] = $s;
        $sum = $x + $y;
        $digits = str_pad((string) abs($sum), $scale + 1, '0', STR_PAD_LEFT);
        $int = substr($digits, 0, \strlen($digits) - $scale);
        $frac = rtrim(substr($digits, \strlen($digits) - $scale), '0');

        return ($sum < 0 ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
    }

    /** -1, 0 or 1, or null when either is not a plain decimal. */
    public static function compare(string $a, string $b): ?int
    {
        $s = self::scaled($a, $b);

        return $s === null ? null : $s[0] <=> $s[1];
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private static function scaled(string $a, string $b): ?array
    {
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $a, $ma) !== 1 || preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $b, $mb) !== 1) {
            return null;
        }
        $scale = max(\strlen($ma[3] ?? ''), \strlen($mb[3] ?? ''));
        $out = [];
        foreach ([$ma, $mb] as $m) {
            $digits = ltrim($m[2] . str_pad($m[3] ?? '', $scale, '0'), '0');
            if (\strlen($digits) > 17) {
                return null; // shortcut: keeps the sum inside int64; such an entry is dropped and refetched instead
            }
            $out[] = ($m[1] === '-' ? -1 : 1) * (int) $digits;
        }

        return [$out[0], $out[1], $scale];
    }
}
