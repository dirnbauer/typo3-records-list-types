<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Doctrine\DBAL\ParameterType;
use RuntimeException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * RecordGridDataProvider - Fetches records with resolved FAL references for Grid View.
 *
 * Provides record data enriched with thumbnails, icons, and other metadata
 * needed for card-based rendering in the Grid View.
 */
final class RecordGridDataProvider implements SingletonInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly IconFactory $iconFactory,
        private readonly GridConfigurationService $configurationService,
        private readonly ThumbnailService $thumbnailService,
    ) {}

    /**
     * Get records for a table formatted for Grid View display.
     *
     * @param string $table The database table name
     * @param int $pageId The page ID to fetch records from
     * @param int $limit Maximum number of records (0 = no limit)
     * @param int $offset Starting offset for pagination
     * @param string $searchTerm Search term to filter records
     * @param string $sortField Field to sort by (empty = default TCA sorting)
     * @param string $sortDirection Sort direction: 'asc' or 'desc'
     * @return array<int, array<string, mixed>> Array of record data
     */
    public function getRecordsForTable(
        string $table,
        int $pageId,
        int $limit = 0,
        int $offset = 0,
        string $searchTerm = '',
        string $sortField = '',
        string $sortDirection = 'asc',
    ): array {
        $tableConfig = $this->configurationService->getTableConfig($table, $pageId);
        $queryBuilder = $this->createQueryBuilder($table, $pageId, $searchTerm, $sortField, $sortDirection);

        // Apply pagination
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }
        if ($offset > 0) {
            $queryBuilder->setFirstResult($offset);
        }

        // Note: We skip dispatching ModifyDatabaseQueryForRecordListingEvent here
        // because it requires a DatabaseRecordList instance. The Grid View uses
        // its own query building logic. Extensions that need to filter records
        // should also listen to TCA restrictions or use other mechanisms.

        $result = $queryBuilder->executeQuery();
        $records = [];

        while ($row = $result->fetchAssociative()) {
            // Apply workspace overlay to get the correct version for the current workspace
            BackendUtility::workspaceOL($table, $row);

            // workspaceOL returns false/null if record is deleted in workspace or should not be shown
            if (!is_array($row)) {
                continue;
            }

            $records[] = $this->enrichRecord($table, $row, $tableConfig, $pageId);
        }

        return $records;
    }

    /**
     * Get total count of records for a table.
     *
     * Uses TYPO3's WorkspaceRestriction for proper workspace support.
     *
     * @param string $table The database table name
     * @param int $pageId The page ID
     * @return int Total number of records
     */
    public function getRecordCount(string $table, int $pageId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $backendUser = $this->getBackendUserAuthentication();

        // Use TYPO3's standard restrictions for proper workspace handling
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $backendUser->workspace));

        $queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER),
                ),
            );

        return (int) $queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * Get the current backend user authentication.
     *
     * @throws RuntimeException If no backend user is available
     */
    private function getBackendUserAuthentication(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            throw new RuntimeException(
                'No backend user available. RecordGridDataProvider requires an authenticated backend user.',
                1735700000,
            );
        }
        return $backendUser;
    }

    /**
     * Create a QueryBuilder for fetching records.
     *
     * Uses TYPO3's WorkspaceRestriction for proper workspace support.
     *
     * @param string $table The database table name
     * @param int $pageId The page ID
     * @param string $searchTerm Search term to filter records
     * @param string $sortField Field to sort by (empty = default TCA sorting)
     * @param string $sortDirection Sort direction: 'asc' or 'desc'
     */
    private function createQueryBuilder(
        string $table,
        int $pageId,
        string $searchTerm = '',
        string $sortField = '',
        string $sortDirection = 'asc',
    ): QueryBuilder {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $backendUser = $this->getBackendUserAuthentication();

        // Use TYPO3's standard restrictions for proper workspace handling
        // Remove all default restrictions and add only the ones we need
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $backendUser->workspace));

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER),
                ),
            );

        // Apply search term filter
        if ($searchTerm !== '') {
            $this->applySearchFilter($queryBuilder, $table, $searchTerm);
        }

        // Apply custom sorting or fall back to TCA default
        if ($sortField !== '' && $this->isValidSortField($table, $sortField)) {
            $direction = strtoupper($sortDirection) === 'DESC' ? 'DESC' : 'ASC';
            $queryBuilder->orderBy($sortField, $direction);
        } else {
            // Default ordering by TCA default sortby or uid
            $sortBy = $GLOBALS['TCA'][$table]['ctrl']['default_sortby'] ?? 'uid DESC';
            $sortBy = str_replace('ORDER BY ', '', $sortBy);
            $sortParts = GeneralUtility::trimExplode(',', $sortBy);

            foreach ($sortParts as $sortPart) {
                $parts = GeneralUtility::trimExplode(' ', $sortPart);
                $field = $parts[0] ?? 'uid';
                $direction = strtoupper($parts[1] ?? 'ASC');

                if ($direction === 'DESC') {
                    $queryBuilder->addOrderBy($field, 'DESC');
                } else {
                    $queryBuilder->addOrderBy($field, 'ASC');
                }
            }
        }

        return $queryBuilder;
    }

    /**
     * Check if a field is valid for sorting.
     *
     * @param string $table The database table name
     * @param string $field The field name to validate
     * @return bool True if the field can be used for sorting
     */
    private function isValidSortField(string $table, string $field): bool
    {
        // Core system fields that always exist
        $coreSystemFields = ['uid', 'pid'];
        if (in_array($field, $coreSystemFields, true)) {
            return true;
        }

        // Check TCA ctrl fields that might exist
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $ctrl = $tca['ctrl'] ?? [];

        // Check if field is defined as a ctrl field (crdate, tstamp, sortby, etc.)
        $ctrlFields = [
            'crdate' => $ctrl['crdate'] ?? null,
            'tstamp' => $ctrl['tstamp'] ?? null,
            'sorting' => $ctrl['sortby'] ?? null,
        ];

        foreach ($ctrlFields as $alias => $actualField) {
            if ($field === $alias && $actualField !== null) {
                return true;
            }
            if ($field === $actualField) {
                return true;
            }
        }

        // Check if field exists in TCA columns
        return isset($tca['columns'][$field]);
    }

    /**
     * Get sortable fields for a table (for UI dropdown).
     *
     * @param string $table The database table name
     * @return array<int, array{field: string, label: string}> Array of sortable fields
     */
    public function getSortableFields(string $table): array
    {
        $fields = [];
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $ctrl = $tca['ctrl'] ?? [];
        $tcaColumns = $tca['columns'] ?? [];

        // Excluded fields (workspace, versioning, internal fields)
        $excludedFields = [
            't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_count',
            't3_origuid', 'l10n_parent', 'l10n_source', 'l10n_diffsource', 'l10n_state',
            'sys_language_uid', 'editlock', 'fe_group', 'perms_userid', 'perms_groupid',
            'perms_user', 'perms_group', 'perms_everybody',
        ];

        // Also exclude password fields and other internal field types
        $excludedTypes = ['passthrough', 'none', 'user', 'flex'];

        // Add system fields first
        $fields[] = [
            'field' => 'uid',
            'label' => 'UID',
        ];

        // Add label field (title)
        $labelField = $ctrl['label'] ?? '';
        if ($labelField !== '' && isset($tcaColumns[$labelField])) {
            $label = $tcaColumns[$labelField]['label'] ?? $labelField;
            $label = $this->translateLabel($label, $labelField);
            $fields[] = [
                'field' => $labelField,
                'label' => $label,
            ];
        }

        // Add creation date (use actual field name from TCA)
        if (!empty($ctrl['crdate'])) {
            $fields[] = [
                'field' => $ctrl['crdate'],
                'label' => $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.creationDate') ?: 'Created',
            ];
        }

        // Add modification date (use actual field name from TCA)
        if (!empty($ctrl['tstamp'])) {
            $fields[] = [
                'field' => $ctrl['tstamp'],
                'label' => $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.timestamp') ?: 'Modified',
            ];
        }

        // Add sorting field if available
        if (isset($ctrl['sortby'])) {
            $fields[] = [
                'field' => $ctrl['sortby'],
                'label' => $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.sorting') ?: 'Sorting',
            ];
        }

        // Add other TCA columns that are suitable for sorting
        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            // Skip if already added
            if ($fieldName === $labelField) {
                continue;
            }

            // Skip excluded fields
            if (in_array($fieldName, $excludedFields, true)) {
                continue;
            }

            $config = $fieldConfig['config'] ?? [];
            $type = $config['type'] ?? '';

            // Skip excluded field types
            if (in_array($type, $excludedTypes, true)) {
                continue;
            }

            // Skip relations with MM tables (complex to sort)
            if (!empty($config['MM'])) {
                continue;
            }

            // Skip large text fields (not useful for sorting)
            if ($type === 'text' && ($config['rows'] ?? 1) > 3) {
                continue;
            }

            // Get field label and translate it
            $label = $fieldConfig['label'] ?? $fieldName;
            $label = $this->translateLabel($label, $fieldName);

            // Limit label length
            if (strlen($label) > 40) {
                $label = substr($label, 0, 37) . '...';
            }

            $fields[] = [
                'field' => $fieldName,
                'label' => $label,
            ];
        }

        return $fields;
    }

    /**
     * Translate a TCA label.
     *
     * Handles both traditional LLL: format and TYPO3 v12+ translation domain format
     * (e.g., 'frontend.db.tt_content:header').
     *
     * @param string $label The label to translate
     * @param string $fallback Fallback value if translation fails
     * @return string The translated label
     */
    private function translateLabel(string $label, string $fallback = ''): string
    {
        // Empty label - return fallback
        if ($label === '') {
            return $fallback ?: $label;
        }

        // Traditional LLL: format
        if (str_starts_with($label, 'LLL:')) {
            $translated = $GLOBALS['LANG']->sL($label);
            return $translated !== '' ? $translated : ($fallback ?: $label);
        }

        // TYPO3 v12+ translation domain format (contains a colon but doesn't start with LLL:)
        // Examples: 'frontend.db.tt_content:header', 'core.messages:labels.depth_0'
        if (str_contains($label, ':')) {
            $translated = $GLOBALS['LANG']->sL($label);
            return $translated !== '' ? $translated : ($fallback ?: $label);
        }

        // Plain string - return as-is
        return $label;
    }

    /**
     * Apply search filter to the query builder.
     *
     * Searches in the fields defined in TCA ctrl.searchFields, or falls back
     * to the label field if no searchFields are defined.
     *
     * @param QueryBuilder $queryBuilder The query builder to modify
     * @param string $table The database table name
     * @param string $searchTerm The search term
     */
    private function applySearchFilter(QueryBuilder $queryBuilder, string $table, string $searchTerm): void
    {
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $ctrl = $tca['ctrl'] ?? [];

        // Get searchable fields from TCA ctrl.searchFields or fall back to label field
        $searchFieldsString = $ctrl['searchFields'] ?? '';
        if ($searchFieldsString === '') {
            $searchFieldsString = $ctrl['label'] ?? 'uid';
        }

        $searchFields = GeneralUtility::trimExplode(',', $searchFieldsString, true);

        // Also search in uid if the search term is numeric
        if (is_numeric($searchTerm)) {
            $searchFields[] = 'uid';
        }

        // Build OR conditions for each search field
        $searchConstraints = [];
        $likeValue = '%' . $queryBuilder->escapeLikeWildcards($searchTerm) . '%';

        foreach ($searchFields as $field) {
            // Skip fields that don't exist in the table
            if ($field !== 'uid' && !isset($tca['columns'][$field])) {
                continue;
            }

            if ($field === 'uid' && is_numeric($searchTerm)) {
                // For uid, do an exact match
                $searchConstraints[] = $queryBuilder->expr()->eq(
                    $field,
                    $queryBuilder->createNamedParameter((int) $searchTerm, ParameterType::INTEGER),
                );
            } else {
                // For text fields, use LIKE
                $searchConstraints[] = $queryBuilder->expr()->like(
                    $field,
                    $queryBuilder->createNamedParameter($likeValue),
                );
            }
        }

        if (!empty($searchConstraints)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(...$searchConstraints),
            );
        }
    }

    /**
     * Enrich a raw database record with Grid View data.
     *
     * @param string $table The database table name
     * @param array<string, mixed> $row The raw database row
     * @param array<string, mixed> $tableConfig The table configuration
     * @param int $pageId The page ID
     * @return array<string, mixed> Enriched record data
     */
    private function enrichRecord(string $table, array $row, array $tableConfig, int $pageId): array
    {
        $uid = (int) $row['uid'];

        // Get title
        $titleField = $tableConfig['titleField'];
        $title = $row[$titleField] ?? '[No title]';
        if (is_array($title)) {
            $title = reset($title);
        }
        $title = (string) $title;

        // Get description
        $description = null;
        if (!empty($tableConfig['descriptionField']) && isset($row[$tableConfig['descriptionField']])) {
            $description = $row[$tableConfig['descriptionField']];
            if (is_array($description)) {
                $description = reset($description);
            }
            // Strip HTML and limit length
            $description = strip_tags((string) $description);
        }

        // Get thumbnail
        $thumbnail = null;
        $thumbnailUrl = null;
        if ($tableConfig['preview'] && !empty($tableConfig['imageField'])) {
            $thumbnailData = $this->thumbnailService->getThumbnailData(
                $table,
                $uid,
                $tableConfig['imageField'],
            );
            $thumbnail = $thumbnailData['file'];
            $thumbnailUrl = $thumbnailData['url'];
        }

        // Get icon identifier
        $iconIdentifier = $this->getIconIdentifier($table, $row);

        // Check hidden status
        $hiddenField = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] ?? null;
        $hidden = $hiddenField ? (bool) ($row[$hiddenField] ?? false) : false;

        // Detect workspace state for visual indicators
        // t3ver_state values: 0=default, 1=new, 2=deleted, 3=moved placeholder, 4=move pointer
        $workspaceState = $this->getWorkspaceState($row);

        return [
            'uid' => $uid,
            'pid' => (int) $row['pid'],
            'tableName' => $table,
            'title' => $title,
            'description' => $description,
            'thumbnail' => $thumbnail,
            'thumbnailUrl' => $thumbnailUrl,
            'iconIdentifier' => $iconIdentifier,
            'hidden' => $hidden,
            'workspaceState' => $workspaceState,
            'rawRecord' => $row,
            'actions' => [], // To be filled by RecordActionsListener
        ];
    }

    /**
     * Get the icon identifier for a record.
     *
     * @param string $table The database table name
     * @param array<string, mixed> $row The raw database row
     * @return string The icon identifier
     */
    private function getIconIdentifier(string $table, array $row): string
    {
        $icon = $this->iconFactory->getIconForRecord($table, $row, IconSize::SMALL);
        return $icon->getIdentifier();
    }

    /**
     * Determine workspace state for visual indicators.
     *
     * Returns a string identifier for the workspace state that can be used
     * as a CSS class modifier for card styling.
     *
     * @param array<string, mixed> $row The raw database row
     * @return string|null Workspace state: 'new', 'changed', 'move', 'deleted', or null for live/unchanged
     */
    private function getWorkspaceState(array $row): ?string
    {
        // Check if we're in a workspace context
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || $backendUser->workspace === 0) {
            return null; // Live workspace, no special state
        }

        // Check t3ver_state field
        $t3verState = (int) ($row['t3ver_state'] ?? 0);

        // t3ver_state values (from TYPO3 VersionState):
        // 0 = DEFAULT_STATE (live or unchanged in workspace)
        // 1 = NEW_PLACEHOLDER (new record created in workspace)
        // 2 = DELETE_PLACEHOLDER (record deleted in workspace)
        // 3 = MOVE_PLACEHOLDER (original location of moved record)
        // 4 = MOVE_POINTER (new location of moved record)
        return match ($t3verState) {
            1 => 'new',
            2 => 'deleted',
            3, 4 => 'move',
            default => $this->isChangedInWorkspace($row) ? 'changed' : null,
        };
    }

    /**
     * Check if a record has been modified in the current workspace.
     *
     * A record is considered "changed" if it has a t3ver_oid pointing to a live record
     * and t3ver_state is 0 (meaning it's a modified version, not new/deleted/moved).
     *
     * @param array<string, mixed> $row The raw database row
     * @return bool True if the record is a workspace modification
     */
    private function isChangedInWorkspace(array $row): bool
    {
        // If t3ver_oid > 0, this is a workspace version of a live record
        $t3verOid = (int) ($row['t3ver_oid'] ?? 0);
        return $t3verOid > 0;
    }

    /**
     * Get records with their actions (for use after actions have been collected).
     *
     * @param string $table The database table name
     * @param int $pageId The page ID
     * @param array<int, string[]> $actionsMap Map of UID => actions array
     * @param int $limit Maximum number of records
     * @param int $offset Starting offset
     * @param string $searchTerm Search term to filter records
     * @param string $sortField Field to sort by
     * @param string $sortDirection Sort direction
     * @return array<int, array<string, mixed>> Array of record data with actions
     */
    public function getRecordsWithActions(
        string $table,
        int $pageId,
        array $actionsMap,
        int $limit = 0,
        int $offset = 0,
        string $searchTerm = '',
        string $sortField = '',
        string $sortDirection = 'asc',
    ): array {
        $records = $this->getRecordsForTable($table, $pageId, $limit, $offset, $searchTerm, $sortField, $sortDirection);

        foreach ($records as &$record) {
            $uid = $record['uid'];
            $record['actions'] = $actionsMap[$uid] ?? [];
        }

        return $records;
    }
}
