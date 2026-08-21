<?php

declare(strict_types=1);

namespace App\Support;

final class CssViewport
{
    /** @var array<string, int> */
    public const SIZES = [
        'phone' => 390,
        'tablet' => 768,
        'laptop' => 1280,
        'desktop' => 1440,
        'wide' => 1920,
    ];

    public static function width(mixed $size, mixed $width): int
    {
        if (is_numeric($width)) {
            $w = (int) $width;
        } else {
            $key = is_string($size) ? strtolower(trim($size)) : '';
            $w = self::SIZES[$key] ?? self::SIZES['desktop'];
        }
        if ($w < 320) {
            return 320;
        }
        if ($w > 2560) {
            return 2560;
        }

        return $w;
    }

    public static function height(mixed $height, int $width): int
    {
        if (is_numeric($height)) {
            $h = (int) $height;
        } else {
            $h = (int) round($width * 0.7);
        }
        if ($h < 480) {
            return 480;
        }
        if ($h > 2000) {
            return 2000;
        }

        return $h;
    }

    /**
     * @return list<string>
     */
    public static function matchingMedia(string $css, int $width, int $height): array
    {
        if (preg_match_all('/@media([^{]+)\{/i', $css, $matches) < 1) {
            return [];
        }
        $out = [];
        foreach ($matches[1] as $raw) {
            $query = trim((string) $raw);
            if ($query === '' || !self::queryMatches($query, $width, $height)) {
                continue;
            }
            if (strlen($query) > 160) {
                $query = substr($query, 0, 159) . '…';
            }
            if (!in_array($query, $out, true)) {
                $out[] = $query;
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private static function queryMatches(string $query, int $width, int $height): bool
    {
        $alts = preg_split('/\s*,\s*|\s+or\s+/i', $query) ?: [$query];
        foreach ($alts as $alt) {
            if (self::andMatches(trim((string) $alt), $width, $height)) {
                return true;
            }
        }

        return false;
    }

    private static function andMatches(string $group, int $width, int $height): bool
    {
        $group = strtolower(trim($group));
        if ($group === '' || $group === 'all' || $group === 'screen') {
            return true;
        }
        if (str_contains($group, 'print') && !str_contains($group, 'screen')) {
            return false;
        }
        if (preg_match_all('/\(([^)]+)\)/', $group, $parts) < 1) {
            return !str_contains($group, 'print');
        }
        foreach ($parts[1] as $feat) {
            if (!self::featureMatches(trim((string) $feat), $width, $height)) {
                return false;
            }
        }

        return true;
    }

    private static function featureMatches(string $feat, int $width, int $height): bool
    {
        if (preg_match('/^(min|max)-(width|height)\s*:\s*([0-9.]+)(px|em|rem)?$/i', $feat, $m) === 1) {
            $px = (float) $m[3];
            if (($m[4] ?? 'px') === 'em' || ($m[4] ?? '') === 'rem') {
                $px *= 16;
            }
            $value = $m[2] === 'width' ? $width : $height;
            if ($m[1] === 'min') {
                return $value >= $px;
            }

            return $value <= $px;
        }
        if (preg_match('/^orientation\s*:\s*(landscape|portrait)$/i', $feat, $m) === 1) {
            $landscape = $width >= $height;

            return strtolower($m[1]) === 'landscape' ? $landscape : !$landscape;
        }

        return true;
    }
}
