<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Pagination;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Pagination\DatabasePaginator;

/**
 * Tests for the DatabasePaginator.
 *
 * The DatabasePaginator works with pre-fetched records (already LIMITed/OFFSETed
 * by the database query). It only needs the total count to calculate pagination.
 */
final class DatabasePaginatorTest extends TestCase
{
    // ========================================================================
    // Basic state
    // ========================================================================

    #[Test]
    public function constructorSetsCorrectState(): void
    {
        $records = [['uid' => 1], ['uid' => 2], ['uid' => 3]];
        $paginator = new DatabasePaginator($records, 30, 1, 10);

        self::assertSame(1, $paginator->getCurrentPageNumber());
        self::assertSame(3, $paginator->getNumberOfPages());
        self::assertSame(30, $paginator->getTotalItems());
    }

    #[Test]
    public function defaultsToFirstPageWith10ItemsPerPage(): void
    {
        $paginator = new DatabasePaginator([], 0);

        self::assertSame(1, $paginator->getCurrentPageNumber());
    }

    // ========================================================================
    // Paginated items
    // ========================================================================

    #[Test]
    public function getPaginatedItemsReturnsItemsAsIs(): void
    {
        $records = [['uid' => 1], ['uid' => 2]];
        $paginator = new DatabasePaginator($records, 100, 1, 10);

        $result = iterator_to_array($paginator->getPaginatedItems());

        self::assertCount(2, $result);
        self::assertSame(1, $result[0]['uid']);
        self::assertSame(2, $result[1]['uid']);
    }

    #[Test]
    public function getPaginatedItemsDoesNotSlice(): void
    {
        // Unlike ArrayPaginator, DatabasePaginator should NOT slice --
        // the records are already pre-fetched with LIMIT/OFFSET
        $records = [['uid' => 11], ['uid' => 12], ['uid' => 13]];
        $paginator = new DatabasePaginator($records, 30, 2, 10);

        $result = iterator_to_array($paginator->getPaginatedItems());

        // All 3 records returned regardless of page/itemsPerPage
        self::assertCount(3, $result);
        self::assertSame(11, $result[0]['uid']);
    }

    // ========================================================================
    // Page count calculation
    // ========================================================================

    #[Test]
    public function numberOfPagesCalculatedCorrectly(): void
    {
        // 250 total / 100 per page = 3 pages (ceil)
        $paginator = new DatabasePaginator([], 250, 1, 100);

        self::assertSame(3, $paginator->getNumberOfPages());
    }

    #[Test]
    public function numberOfPagesIsOneWhenTotalFitsInOnePage(): void
    {
        $paginator = new DatabasePaginator([], 5, 1, 10);

        self::assertSame(1, $paginator->getNumberOfPages());
    }

    #[Test]
    public function numberOfPagesIsOneWhenTotalIsZero(): void
    {
        $paginator = new DatabasePaginator([], 0, 1, 10);

        // AbstractPaginator returns at least 1 page
        self::assertGreaterThanOrEqual(1, $paginator->getNumberOfPages());
    }

    #[Test]
    public function numberOfPagesWithExactDivision(): void
    {
        // 100 total / 10 per page = exactly 10 pages
        $paginator = new DatabasePaginator([], 100, 1, 10);

        self::assertSame(10, $paginator->getNumberOfPages());
    }

    // ========================================================================
    // getTotalItems (public accessor)
    // ========================================================================

    #[Test]
    public function getTotalItemsReturnsTotalCount(): void
    {
        $paginator = new DatabasePaginator([], 12345, 1, 100);

        self::assertSame(12345, $paginator->getTotalItems());
    }

    #[Test]
    public function getTotalItemsReturnsZeroForEmptySet(): void
    {
        $paginator = new DatabasePaginator([], 0);

        self::assertSame(0, $paginator->getTotalItems());
    }

    // ========================================================================
    // Current page
    // ========================================================================

    #[Test]
    public function currentPageIsSetCorrectly(): void
    {
        $paginator = new DatabasePaginator([], 100, 5, 10);

        self::assertSame(5, $paginator->getCurrentPageNumber());
    }

    #[Test]
    public function currentPageClampedToMaximum(): void
    {
        // Page 99 requested but only 3 pages exist (30 total / 10 per page)
        $paginator = new DatabasePaginator([], 30, 99, 10);

        // AbstractPaginator clamps to last page
        self::assertLessThanOrEqual(3, $paginator->getCurrentPageNumber());
    }

    #[Test]
    public function currentPageClampedToMinimumOne(): void
    {
        // Page 0 or negative should be clamped to 1
        $paginator = new DatabasePaginator([], 100, 0, 10);

        self::assertSame(1, $paginator->getCurrentPageNumber());
    }

    // ========================================================================
    // Items per page
    // ========================================================================

    #[Test]
    public function customItemsPerPageIsRespected(): void
    {
        $paginator = new DatabasePaginator([], 500, 1, 50);

        self::assertSame(10, $paginator->getNumberOfPages());
    }

    #[Test]
    public function largeItemsPerPageResultsInSinglePage(): void
    {
        $paginator = new DatabasePaginator([], 50, 1, 1000);

        self::assertSame(1, $paginator->getNumberOfPages());
    }

    // ========================================================================
    // Empty records
    // ========================================================================

    #[Test]
    public function emptyRecordsWithNonZeroTotalStillCalculatesPages(): void
    {
        // This happens when the current page has no records but total > 0
        // (e.g., records were deleted between count and fetch)
        $paginator = new DatabasePaginator([], 100, 1, 10);

        self::assertSame(10, $paginator->getNumberOfPages());
        self::assertSame(100, $paginator->getTotalItems());

        $result = iterator_to_array($paginator->getPaginatedItems());
        self::assertCount(0, $result);
    }
}
