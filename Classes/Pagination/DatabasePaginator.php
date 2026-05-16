<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Pagination;

use TYPO3\CMS\Core\Pagination\AbstractPaginator;

/**
 * A paginator for database records that have already been fetched with LIMIT/OFFSET.
 *
 * Unlike ArrayPaginator which needs ALL items in memory and slices them,
 * this paginator works with pre-fetched records from a database query
 * that already applied LIMIT and OFFSET. It only needs the total count
 * of records to calculate pagination state.
 *
 * This is the standard pattern for backend modules that use database-level
 * pagination via QueryBuilder::setMaxResults() / setFirstResult().
 *
 * Usage:
 *     $paginator = new DatabasePaginator($records, $totalCount, $currentPage, $itemsPerPage);
 *     $pagination = new SlidingWindowPagination($paginator, 15);
 */
final class DatabasePaginator extends AbstractPaginator
{
    /** @var array<int, array<string, mixed>> */
    private array $items;

    /** @var array<int, array<string, mixed>> */
    private array $paginatedItems = [];

    private int $totalItemCount;

    /**
     * @param array<int, array<string, mixed>> $items The already-paginated records (current page only)
     * @param int $totalItemCount The total number of records across all pages
     * @param int $currentPageNumber The current page number (1-based)
     * @param int $itemsPerPage Number of items per page
     */
    public function __construct(
        array $items,
        int $totalItemCount,
        int $currentPageNumber = 1,
        int $itemsPerPage = 10,
    ) {
        $this->items = $items;
        $this->totalItemCount = $totalItemCount;
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);

        $this->updateInternalState();
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        // Items are already pre-fetched with the correct LIMIT/OFFSET from the database.
        // No slicing needed -- just use them as-is.
        $this->paginatedItems = $this->items;
    }

    protected function getTotalAmountOfItems(): int
    {
        return $this->totalItemCount;
    }

    /**
     * Public accessor for the total item count (for use in Fluid templates).
     *
     * The parent's getTotalAmountOfItems() is protected, but templates need
     * the total count for the range indicator (e.g. "1-100 of 250").
     */
    public function getTotalItems(): int
    {
        return $this->totalItemCount;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
