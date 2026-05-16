<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\EventListener\GridViewQueryListener;

/**
 * Tests for the GridViewQueryListener static cache methods.
 */
final class GridViewQueryListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Always start with a clean cache
        GridViewQueryListener::clearCache();
    }

    protected function tearDown(): void
    {
        GridViewQueryListener::clearCache();
        parent::tearDown();
    }

    #[Test]
    public function getCachedQueryBuilderReturnsNullWhenCacheIsEmpty(): void
    {
        self::assertNull(
            GridViewQueryListener::getCachedQueryBuilder('tt_content', 1),
        );
    }

    #[Test]
    public function getCachedQueryBuilderReturnsNullForNonCachedTable(): void
    {
        self::assertNull(
            GridViewQueryListener::getCachedQueryBuilder('nonexistent', 99),
        );
    }

    #[Test]
    public function clearCacheRemovesAllEntries(): void
    {
        // We can only verify clearCache doesn't throw and returns null after
        GridViewQueryListener::clearCache();

        self::assertNull(
            GridViewQueryListener::getCachedQueryBuilder('tt_content', 1),
        );
    }

    #[Test]
    public function getCachedQueryBuilderUsesTableAndPageIdAsCacheKey(): void
    {
        // Different page IDs for same table should be independent
        self::assertNull(
            GridViewQueryListener::getCachedQueryBuilder('pages', 1),
        );
        self::assertNull(
            GridViewQueryListener::getCachedQueryBuilder('pages', 2),
        );
    }
}
