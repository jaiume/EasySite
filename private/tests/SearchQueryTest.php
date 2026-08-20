<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testPipeMeansLiteralOr(): void
    {
        $needles = SearchQuery::needles('#0d6b|#08533|accent');
        self::assertSame(['#0d6b', '#08533', 'accent'], $needles);
        self::assertTrue(SearchQuery::haystackHasAny('--accent: #0d6b4c;', $needles));
        self::assertFalse(SearchQuery::haystackHasAny('color: navy;', $needles));
    }

    public function testPlainQueryIsOneNeedle(): void
    {
        self::assertSame(['--accent'], SearchQuery::needles('--accent'));
    }
}
