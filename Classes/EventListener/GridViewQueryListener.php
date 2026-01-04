<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use TYPO3\CMS\Backend\View\Event\ModifyDatabaseQueryForRecordListingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * GridViewQueryListener - Ensures Grid View respects query modifications.
 *
 * This listener observes query modifications made by other extensions
 * and ensures the Grid View uses the same modified queries.
 *
 * Note: This listener primarily serves as a marker to ensure compatibility
 * with other extensions that modify record list queries. The actual query
 * building in RecordGridDataProvider dispatches this same event.
 */
#[AsEventListener]
final class GridViewQueryListener
{
    /**
     * @var array<string, \TYPO3\CMS\Core\Database\Query\QueryBuilder> Cache of modified query builders
     */
    private static array $queryCache = [];

    public function __invoke(ModifyDatabaseQueryForRecordListingEvent $event): void
    {
        // Store the modified query builder for potential reuse
        // This allows the Grid View to use the exact same query as the List View
        $table = $event->getTable();
        $pageId = $event->getPageId();
        $cacheKey = $table . '_' . $pageId;

        self::$queryCache[$cacheKey] = clone $event->getQueryBuilder();
    }

    /**
     * Get a cached query builder if available.
     *
     * @param string $table The table name
     * @param int $pageId The page ID
     * @return \TYPO3\CMS\Core\Database\Query\QueryBuilder|null
     */
    public static function getCachedQueryBuilder(string $table, int $pageId): ?\TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $cacheKey = $table . '_' . $pageId;
        return self::$queryCache[$cacheKey] ?? null;
    }

    /**
     * Clear the query cache.
     */
    public static function clearCache(): void
    {
        self::$queryCache = [];
    }
}

