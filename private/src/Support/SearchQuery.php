<?php

declare(strict_types=1);

namespace App\Support;

final class SearchQuery
{
    /**
     * Literal needles. `|` means OR. This is not a regular expression.
     *
     * @return list<string>
     */
    public static function needles(string $query): array
    {
        $parts = str_contains($query, '|') ? explode('|', $query) : [$query];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $needles
     */
    public static function haystackHasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
