<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use Throwable;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * GridViewRecordActionsListener - Bridges record actions to card footers.
 *
 * This listener collects record actions (edit, copy, delete, custom) from the
 * standard RecordList event and stores them for later use in Grid View cards.
 *
 * It does not modify the actions - it only caches them for the Grid View renderer.
 *
 * Note: In TYPO3 v14, the event API has changed. This listener is kept minimal
 * to avoid compatibility issues.
 */
#[AsEventListener]
final class GridViewRecordActionsListener implements SingletonInterface
{
    /** @var array<string, array<int, array<string, mixed>>> Cached actions per table and record */
    private array $actionsCache = [];

    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        // In TYPO3 v14, we use getActions() to get the current actions
        // and store them for potential Grid View usage
        try {
            $actions = $event->getActions();

            // Store actions with a timestamp-based key for debugging
            // The actual record context will be retrieved from the DatabaseRecordList
            $this->actionsCache['_latest'] = [
                'actions' => $actions,
                'timestamp' => time(),
            ];
        } catch (Throwable $e) {
            // Silently fail - action caching is not critical
        }
    }

    /**
     * Get the latest cached actions.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestActions(): ?array
    {
        return $this->actionsCache['_latest'] ?? null;
    }

    /**
     * Get all cached actions.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAllCachedActions(): array
    {
        return $this->actionsCache;
    }

    /**
     * Get primary actions HTML for a specific record.
     *
     * Primary actions are typically: edit, view, hide/show
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @return array<int, string> Array of action HTML strings
     */
    public function getPrimaryActionsHtml(string $table, int $uid): array
    {
        $cacheKey = $table . '_' . $uid;
        $cached = $this->actionsCache[$cacheKey] ?? null;

        if ($cached === null) {
            return [];
        }

        // Filter to primary actions only
        $primaryActionKeys = ['edit', 'view', 'hide', 'unhide'];
        $actions = [];

        foreach ($cached['actions'] ?? [] as $key => $actionHtml) {
            if (in_array($key, $primaryActionKeys, true) && is_string($actionHtml)) {
                $actions[] = $actionHtml;
            }
        }

        return $actions;
    }

    /**
     * Get all actions HTML for a specific record.
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @return array<int, string> Array of action HTML strings
     */
    public function getAllActionsHtml(string $table, int $uid): array
    {
        $cacheKey = $table . '_' . $uid;
        $cached = $this->actionsCache[$cacheKey] ?? null;

        if ($cached === null) {
            return [];
        }

        $actions = [];
        foreach ($cached['actions'] ?? [] as $actionHtml) {
            if (is_string($actionHtml)) {
                $actions[] = $actionHtml;
            }
        }

        return $actions;
    }

    /**
     * Store actions for a specific record.
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @param array<string, mixed> $actions The actions to store
     */
    public function storeRecordActions(string $table, int $uid, array $actions): void
    {
        $cacheKey = $table . '_' . $uid;
        $this->actionsCache[$cacheKey] = [
            'actions' => $actions,
            'timestamp' => time(),
        ];
    }

    /**
     * Clear the actions cache.
     */
    public function clearCache(): void
    {
        $this->actionsCache = [];
    }
}
