<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;

/**
 * ViewTypeRegistry - Manages view types from TSconfig for the records module.
 *
 * View types can be registered via TSconfig:
 *
 * mod.web_list.viewMode.types {
 *     myview {
 *         label = My Custom View
 *         icon = content-text
 *         description = Custom view for specific content
 *         template = MyView
 *         partial = MyCard
 *         css = EXT:my_ext/Resources/Public/Css/my-view.css
 *         js = @my-ext/my-view.js
 *         displayColumns = title,date,teaser
 *         columnsFromTCA = 1
 *     }
 * }
 *
 * Built-in view types (list, grid, compact, teaser) are always available.
 */
final class ViewTypeRegistry implements SingletonInterface
{
    /**
     * Built-in view types with their default configuration.
     */
    private const BUILTIN_TYPES = [
        'list' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.list',
            'icon' => 'actions-viewmode-list',
            'description' => 'Standard table view',
            'builtin' => true,
            'handler' => 'list', // Special handling - delegates to parent controller
        ],
        'grid' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.grid',
            'icon' => 'actions-viewmode-tiles',
            'description' => 'Card-based grid view',
            'builtin' => true,
            'template' => 'GridView',
            'partial' => 'Card',
            'css' => 'EXT:records_list_types/Resources/Public/Css/grid-view.css',
            'columnsFromTCA' => true,
        ],
        'compact' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.compact',
            'icon' => 'actions-menu',
            'description' => 'Compact single-line view',
            'builtin' => true,
            'template' => 'CompactView',
            'partial' => 'CompactRow',
            'css' => 'EXT:records_list_types/Resources/Public/Css/compact-view.css',
            'columnsFromTCA' => true,
        ],
        'teaser' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.teaser',
            'icon' => 'content-news',
            'description' => 'Teaser list with title, date, description',
            'builtin' => true,
            'template' => 'TeaserView',
            'partial' => 'TeaserCard',
            'css' => 'EXT:records_list_types/Resources/Public/Css/teaser-view.css',
            'displayColumns' => 'label,datetime,teaser', // Special column names resolved at runtime
            'columnsFromTCA' => false,
        ],
    ];

    /**
     * Cached view types per page
     * @var array<int, array<string, array>>
     */
    private array $cache = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Get all registered view types for a page.
     *
     * @param int $pageId The page ID for TSconfig resolution
     * @return array<string, array> Map of view type ID => configuration
     */
    public function getViewTypes(int $pageId): array
    {
        if (isset($this->cache[$pageId])) {
            return $this->cache[$pageId];
        }

        // Start with built-in types
        $types = self::BUILTIN_TYPES;

        // Allow extensions to register types via PSR-14 event
        $event = new RegisterViewModesEvent($types);
        $this->eventDispatcher->dispatch($event);
        $types = $event->getViewModes();

        // Merge TSconfig types
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $tsTypes = $tsConfig['mod.']['web_list.']['viewMode.']['types.'] ?? [];

        foreach ($tsTypes as $typeId => $config) {
            $typeId = rtrim($typeId, '.');
            if (!is_array($config)) {
                continue;
            }

            // Merge with existing type or create new
            if (isset($types[$typeId])) {
                // Override existing type's config
                $types[$typeId] = array_merge($types[$typeId], $this->normalizeConfig($config, $typeId));
            } else {
                // New custom type
                $types[$typeId] = $this->normalizeConfig($config, $typeId);
            }
        }

        $this->cache[$pageId] = $types;
        return $types;
    }

    /**
     * Get configuration for a specific view type.
     *
     * @param string $typeId The view type identifier
     * @param int $pageId The page ID for TSconfig resolution
     * @return array|null The type configuration or null if not found
     */
    public function getViewType(string $typeId, int $pageId): ?array
    {
        $types = $this->getViewTypes($pageId);
        return $types[$typeId] ?? null;
    }

    /**
     * Check if a view type exists.
     */
    public function hasViewType(string $typeId, int $pageId): bool
    {
        return $this->getViewType($typeId, $pageId) !== null;
    }

    /**
     * Get allowed view types for a page.
     *
     * @param int $pageId The page ID
     * @return array<string, array> Filtered map of allowed view types
     */
    public function getAllowedViewTypes(int $pageId): array
    {
        $allTypes = $this->getViewTypes($pageId);
        
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $allowedString = $tsConfig['mod.']['web_list.']['viewMode.']['allowed'] 
            ?? $tsConfig['mod.']['web_list.']['allowedViews'] 
            ?? implode(',', array_keys($allTypes));
        
        $allowedIds = array_map('trim', explode(',', $allowedString));
        
        return array_filter(
            $allTypes,
            fn(string $typeId) => in_array($typeId, $allowedIds, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Get the default view type for a page.
     */
    public function getDefaultViewType(int $pageId): string
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $default = $tsConfig['mod.']['web_list.']['viewMode.']['default'] 
            ?? $tsConfig['mod.']['web_list.']['gridView.']['default'] 
            ?? 'list';
        
        // Validate it exists
        if (!$this->hasViewType($default, $pageId)) {
            return 'list';
        }
        
        return $default;
    }

    /**
     * Get template paths for a view type.
     *
     * @param string $typeId The view type identifier
     * @param int $pageId The page ID
     * @return array{template: string, partial: string, templatePaths: array, partialPaths: array}
     */
    public function getTemplatePaths(string $typeId, int $pageId): array
    {
        $config = $this->getViewType($typeId, $pageId);
        
        if ($config === null) {
            // Fallback to grid
            $config = self::BUILTIN_TYPES['grid'];
        }

        // Default paths
        $templatePaths = ['EXT:records_list_types/Resources/Private/Templates/'];
        $partialPaths = ['EXT:records_list_types/Resources/Private/Partials/'];
        $layoutPaths = ['EXT:records_list_types/Resources/Private/Layouts/'];

        // Add custom paths from TSconfig if specified
        if (!empty($config['templateRootPath'])) {
            array_unshift($templatePaths, $config['templateRootPath']);
        }
        if (!empty($config['partialRootPath'])) {
            array_unshift($partialPaths, $config['partialRootPath']);
        }
        if (!empty($config['layoutRootPath'])) {
            array_unshift($layoutPaths, $config['layoutRootPath']);
        }

        return [
            'template' => $config['template'] ?? $this->getDefaultTemplateName($typeId),
            'partial' => $config['partial'] ?? 'Card',
            'templateRootPaths' => $templatePaths,
            'partialRootPaths' => $partialPaths,
            'layoutRootPaths' => $layoutPaths,
        ];
    }

    /**
     * Get CSS files for a view type.
     */
    public function getCssFiles(string $typeId, int $pageId): array
    {
        $config = $this->getViewType($typeId, $pageId);
        if ($config === null) {
            return [];
        }

        $files = [];
        if (!empty($config['css'])) {
            $files = is_array($config['css']) ? $config['css'] : [$config['css']];
        }

        return $files;
    }

    /**
     * Get JS modules for a view type.
     */
    public function getJsModules(string $typeId, int $pageId): array
    {
        $config = $this->getViewType($typeId, $pageId);
        if ($config === null) {
            return ['@webconsulting/records-list-types/GridViewActions.js'];
        }

        $modules = ['@webconsulting/records-list-types/GridViewActions.js']; // Always include base
        if (!empty($config['js'])) {
            $custom = is_array($config['js']) ? $config['js'] : [$config['js']];
            $modules = array_merge($modules, $custom);
        }

        return array_unique($modules);
    }

    /**
     * Get display columns configuration for a view type.
     *
     * @return array{columns: string[], fromTCA: bool}
     */
    public function getDisplayColumnsConfig(string $typeId, int $pageId): array
    {
        $config = $this->getViewType($typeId, $pageId);
        
        if ($config === null) {
            return ['columns' => [], 'fromTCA' => true];
        }

        $columns = [];
        if (!empty($config['displayColumns'])) {
            $columns = is_array($config['displayColumns']) 
                ? $config['displayColumns'] 
                : GeneralUtility::trimExplode(',', $config['displayColumns'], true);
        }

        $fromTCA = (bool)($config['columnsFromTCA'] ?? true);

        return [
            'columns' => $columns,
            'fromTCA' => $fromTCA,
        ];
    }

    /**
     * Check if a view type is built-in (has special handling).
     */
    public function isBuiltinType(string $typeId): bool
    {
        return isset(self::BUILTIN_TYPES[$typeId]) && !empty(self::BUILTIN_TYPES[$typeId]['builtin']);
    }

    /**
     * Check if a view type should delegate to parent controller (list view).
     */
    public function shouldDelegateToParent(string $typeId, int $pageId): bool
    {
        $config = $this->getViewType($typeId, $pageId);
        return $config !== null && ($config['handler'] ?? '') === 'list';
    }

    /**
     * Normalize TSconfig to internal format.
     */
    private function normalizeConfig(array $config, string $typeId): array
    {
        return [
            'label' => $config['label'] ?? $typeId,
            'icon' => $config['icon'] ?? 'actions-viewmode-list',
            'description' => $config['description'] ?? '',
            'template' => $config['template'] ?? $this->getDefaultTemplateName($typeId),
            'partial' => $config['partial'] ?? 'Card',
            'css' => $config['css'] ?? null,
            'js' => $config['js'] ?? null,
            'templateRootPath' => $config['templateRootPath'] ?? null,
            'partialRootPath' => $config['partialRootPath'] ?? null,
            'layoutRootPath' => $config['layoutRootPath'] ?? null,
            'displayColumns' => $config['displayColumns'] ?? null,
            'columnsFromTCA' => isset($config['columnsFromTCA']) ? (bool)$config['columnsFromTCA'] : true,
            'builtin' => false,
        ];
    }

    /**
     * Get default template name for a type ID.
     */
    private function getDefaultTemplateName(string $typeId): string
    {
        // Convert type-id to TypeIdView format
        $parts = explode('_', str_replace('-', '_', $typeId));
        $name = implode('', array_map('ucfirst', $parts));
        return $name . 'View';
    }

    /**
     * Clear the cache (useful for testing).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}

