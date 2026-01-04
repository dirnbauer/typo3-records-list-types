<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * GridConfigurationService - Resolves per-table field mappings from TSconfig.
 *
 * Parses and caches TSconfig configuration for the Grid View, providing
 * field mappings for card rendering (title, description, image fields).
 */
final class GridConfigurationService implements SingletonInterface
{
    /**
     * @var array<string, array<string, mixed>> Runtime cache for table configurations
     */
    private array $tableConfigCache = [];

    /**
     * Default configuration applied when no TSconfig is specified.
     */
    private const DEFAULT_CONFIG = [
        'titleField' => null,      // Will fall back to TCA label
        'descriptionField' => null,
        'imageField' => null,
        'preview' => true,
    ];

    /**
     * Default number of columns in the grid.
     */
    private const DEFAULT_COLS = 4;

    /**
     * Get the configuration for a specific table.
     *
     * @param string $table The database table name
     * @param int $pageId The page ID for TSconfig resolution
     * @return array{titleField: ?string, descriptionField: ?string, imageField: ?string, preview: bool}
     */
    public function getTableConfig(string $table, int $pageId): array
    {
        $cacheKey = $table . '_' . $pageId;

        if (isset($this->tableConfigCache[$cacheKey])) {
            return $this->tableConfigCache[$cacheKey];
        }

        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $tableConfig = $tsConfig['mod.']['web_list.']['gridView.']['table.'][$table . '.'] ?? [];

        // Get the hidden field from TCA
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $hiddenField = $tca['ctrl']['enablecolumns']['disabled'] ?? 'hidden';

        $config = [
            'titleField' => $this->getTitleField($table, $tableConfig),
            'descriptionField' => $tableConfig['descriptionField'] ?? null,
            'imageField' => $tableConfig['imageField'] ?? null,
            'preview' => $this->parseBoolean($tableConfig['preview'] ?? '1'),
            'hiddenField' => $hiddenField,
        ];

        $this->tableConfigCache[$cacheKey] = $config;

        return $config;
    }

    /**
     * Get the global grid configuration (not table-specific).
     *
     * @param int $pageId The page ID for TSconfig resolution
     * @return array{cols: int, default: string, allowedViews: string[]}
     */
    public function getGlobalConfig(int $pageId): array
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $gridViewConfig = $tsConfig['mod.']['web_list.']['gridView.'] ?? [];

        $allowedViewsString = $tsConfig['mod.']['web_list.']['allowedViews'] ?? 'list,grid';
        $allowedViews = array_map('trim', explode(',', $allowedViewsString));

        return [
            'cols' => $this->validateCols((int)($gridViewConfig['cols'] ?? self::DEFAULT_COLS)),
            'default' => $gridViewConfig['default'] ?? 'list',
            'allowedViews' => $allowedViews,
        ];
    }

    /**
     * Get the number of columns for the grid layout.
     *
     * @param int $pageId The page ID for TSconfig resolution
     * @return int Number of columns (2-6)
     */
    public function getColumnCount(int $pageId): int
    {
        return $this->getGlobalConfig($pageId)['cols'];
    }

    /**
     * Determine the title field for a table.
     *
     * Falls back to the TCA label field if not configured.
     *
     * @param string $table The database table name
     * @param array<string, mixed> $tableConfig The table-specific TSconfig
     * @return string The field name to use for the card title
     */
    private function getTitleField(string $table, array $tableConfig): string
    {
        // Use configured title field if available
        if (!empty($tableConfig['titleField'])) {
            return $tableConfig['titleField'];
        }

        // Fall back to TCA label field
        $tca = $GLOBALS['TCA'][$table] ?? null;
        if ($tca !== null) {
            $labelField = $tca['ctrl']['label'] ?? null;
            if ($labelField !== null) {
                return $labelField;
            }
        }

        // Ultimate fallback
        return 'uid';
    }

    /**
     * Validate and normalize the column count.
     *
     * @param int $cols The configured column count
     * @return int A valid column count between 2 and 6
     */
    private function validateCols(int $cols): int
    {
        if ($cols < 2) {
            return 2;
        }
        if ($cols > 6) {
            return 6;
        }
        return $cols;
    }

    /**
     * Parse a string value as boolean.
     *
     * @param string|int|bool $value The value to parse
     * @return bool The boolean result
     */
    private function parseBoolean(string|int|bool $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return !in_array(strtolower($value), ['0', 'false', 'no', ''], true);
    }

    /**
     * Check if a table has an image field configured.
     *
     * @param string $table The database table name
     * @param int $pageId The page ID for TSconfig resolution
     * @return bool True if an image field is configured
     */
    public function hasImageField(string $table, int $pageId): bool
    {
        $config = $this->getTableConfig($table, $pageId);
        return !empty($config['imageField']);
    }

    /**
     * Check if thumbnails/previews are enabled for a table.
     *
     * @param string $table The database table name
     * @param int $pageId The page ID for TSconfig resolution
     * @return bool True if previews are enabled
     */
    public function isPreviewEnabled(string $table, int $pageId): bool
    {
        $config = $this->getTableConfig($table, $pageId);
        return $config['preview'] === true;
    }

    /**
     * Clear the runtime cache.
     *
     * Useful in testing or when TSconfig changes dynamically.
     */
    public function clearCache(): void
    {
        $this->tableConfigCache = [];
    }

    /**
     * Get all configured tables from TSconfig.
     *
     * @param int $pageId The page ID for TSconfig resolution
     * @return string[] List of table names with explicit Grid View configuration
     */
    public function getConfiguredTables(int $pageId): array
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $tableConfigs = $tsConfig['mod.']['web_list.']['gridView.']['table.'] ?? [];

        $tables = [];
        foreach (array_keys($tableConfigs) as $key) {
            // TSconfig array keys have trailing dots
            $tableName = rtrim($key, '.');
            if (!empty($tableName)) {
                $tables[] = $tableName;
            }
        }

        return $tables;
    }
}

