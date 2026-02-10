<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Backend\Controller\RecordListController as CoreRecordListController;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\RecordSearchBoxComponent;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\RecordsListTypes\Pagination\DatabasePaginator;
use Webconsulting\RecordsListTypes\Service\GridConfigurationService;
use Webconsulting\RecordsListTypes\Service\MiddlewareDiagnosticService;
use Webconsulting\RecordsListTypes\Service\RecordGridDataProvider;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;
use Webconsulting\RecordsListTypes\Service\ViewTypeRegistry;

/**
 * Extended RecordListController with multiple view mode support.
 *
 * This controller extends the core RecordListController to add
 * alternative view modes (grid, compact, teaser, and custom types)
 * alongside the standard list view.
 *
 * Each view mode has its own rendering method, Fluid template, and CSS.
 * Custom view types can be registered via TSconfig or PSR-14 events.
 *
 * IMPORTANT: We replicate the parent's mainAction() initialization flow
 * to ensure all DocHeader buttons, clipboard, page context etc. work correctly.
 */
final class RecordListController extends CoreRecordListController
{
    private ?ViewTypeRegistry $viewTypeRegistry = null;

    /**
     * Clipboard object, stored as property so renderViewContent() can access it.
     */
    private ?\TYPO3\CMS\Backend\Clipboard\Clipboard $clipboardObj = null;

    /**
     * Whether the clipboard is enabled for this request.
     */
    private bool $clipboardEnabled = false;

    /**
     * Get the ViewTypeRegistry from the DI container.
     * XClasses can't use constructor injection for additional dependencies,
     * so we fetch it from the container on demand.
     */
    protected function getViewTypeRegistry(): ViewTypeRegistry
    {
        if ($this->viewTypeRegistry === null) {
            $registry = GeneralUtility::getContainer()->get(ViewTypeRegistry::class);
            if (!$registry instanceof ViewTypeRegistry) {
                throw new RuntimeException('ViewTypeRegistry not available from container', 1735600200);
            }
            $this->viewTypeRegistry = $registry;
        }
        return $this->viewTypeRegistry;
    }

    /**
     * Main action - renders the appropriate view based on displayMode.
     *
     * Supported modes:
     * - list: Standard table view (parent controller)
     * - grid: Card-based grid view with thumbnails, drag-and-drop, and language flags
     * - compact: Dense single-line table view with fixed columns
     * - teaser: News-style card view with title, date, and description
     * - Custom types registered via TSconfig or PSR-14 RegisterViewModesEvent
     *
     * We replicate the parent's initialization to ensure buttons and context are set up.
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        // Get view mode resolver
        $viewModeResolver = GeneralUtility::makeInstance(ViewModeResolver::class);

        // Get page ID from request
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $parsedBodyArray = is_array($parsedBody) ? $parsedBody : [];
        $pageId = (int) ($queryParams['id'] ?? $parsedBodyArray['id'] ?? 0);

        // Get the active view mode
        $viewMode = $viewModeResolver->getActiveViewMode($request, $pageId);

        // Only handle non-list views
        if ($viewMode === 'list' || !$viewModeResolver->isModeAllowed($viewMode, $pageId)) {
            // Delegate to parent for standard List View
            return parent::mainAction($request);
        }

        // =========================================================================
        // Grid View rendering - replicate parent initialization for DocHeader buttons
        // =========================================================================

        // Initialize from parent's flow - type-narrow attributes for PHPStan
        $pageContextAttr = $request->getAttribute('pageContext');
        $moduleDataAttr = $request->getAttribute('moduleData');
        if (!$pageContextAttr instanceof \TYPO3\CMS\Backend\Context\PageContext
            || !$moduleDataAttr instanceof \TYPO3\CMS\Backend\Module\ModuleData) {
            return parent::mainAction($request);
        }
        $this->pageContext = $pageContextAttr;
        $this->moduleData = $moduleDataAttr;

        $languageService = $this->getLanguageService();
        $backendUser = $this->getBackendUserAuthentication();

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/dispatch-modal-button.js');

        BackendUtility::lockRecords();
        $pointer = max(0, (int) ($parsedBodyArray['pointer'] ?? $queryParams['pointer'] ?? 0));
        $this->table = (string) ($parsedBodyArray['table'] ?? $queryParams['table'] ?? '');
        $this->searchTerm = trim((string) ($parsedBodyArray['searchTerm'] ?? $queryParams['searchTerm'] ?? ''));
        $this->returnUrl = GeneralUtility::sanitizeLocalUrl((string) ($parsedBodyArray['returnUrl'] ?? $queryParams['returnUrl'] ?? ''));
        $cmd = (string) ($parsedBodyArray['cmd'] ?? $queryParams['cmd'] ?? '');

        // Ensure default language is included
        $languagesToDisplay = $this->pageContext->selectedLanguageIds;
        if (!in_array(0, $languagesToDisplay, true)) {
            $languagesToDisplay = array_merge([0], $languagesToDisplay);
            $this->pageContext = $this->pageContextFactory->createWithLanguages(
                $request,
                $this->pageContext->pageId,
                $languagesToDisplay,
                $backendUser,
            );
            $request = $request->withAttribute('pageContext', $this->pageContext);
        }
        $this->moduleData->set('languages', $languagesToDisplay);

        $siteLanguages = $this->pageContext->site->getAvailableLanguages($backendUser, false, $this->pageContext->pageId);
        $backendUser->pushModuleData($this->moduleData->getModuleIdentifier(), $this->moduleData->toArray());

        // Load module configuration
        $this->modTSconfig = $this->pageContext->getModuleTsConfig('web_list');

        // Clipboard settings
        if (($this->modTSconfig['enableClipBoard'] ?? '') === 'activated') {
            $this->moduleData->set('clipBoard', true);
            $this->allowClipboard = false;
        } elseif (($this->modTSconfig['enableClipBoard'] ?? '') === 'selectable') {
            $this->allowClipboard = true;
        } elseif (($this->modTSconfig['enableClipBoard'] ?? '') === 'deactivated') {
            $this->moduleData->set('clipBoard', false);
            $this->allowClipboard = false;
        }

        // Search settings
        $this->allowSearch = !(bool) ($this->modTSconfig['disableSearchBox'] ?? false);
        if ($this->searchTerm !== '') {
            $this->allowSearch = true;
            $this->moduleData->set('searchBox', true);
        }
        $searchLevelConfig = $this->modTSconfig['searchLevel'] ?? null;
        $searchLevelDefault = 0;
        if (is_array($searchLevelConfig)) {
            $rawDefault = $searchLevelConfig['default'] ?? 0;
            $searchLevelDefault = is_numeric($rawDefault) ? (int) $rawDefault : 0;
        }
        $searchLevels = (int) ($parsedBodyArray['search_levels'] ?? $queryParams['search_levels'] ?? $searchLevelDefault);

        // Create DatabaseRecordList (needed for URL building and other parent methods)
        $dbList = GeneralUtility::makeInstance(DatabaseRecordList::class);
        $dbList->setRequest($request);
        $dbList->setModuleData($this->moduleData);
        $dbList->calcPerms = $this->pageContext->pagePermissions;
        $dbList->returnUrl = $this->returnUrl;
        $dbList->showClipboardActions = true;
        $dbList->disableSingleTableView = (bool) ($this->modTSconfig['disableSingleTableView'] ?? false);
        $dbList->listOnlyInSingleTableMode = (bool) ($this->modTSconfig['listOnlyInSingleTableView'] ?? false);
        $dbList->hideTables = (string) ($this->modTSconfig['hideTables'] ?? '');
        $dbList->hideTranslations = (string) ($this->modTSconfig['hideTranslations'] ?? '');
        $tableOverTca = is_array($this->modTSconfig['table'] ?? null) ? $this->modTSconfig['table'] : [];
        /** @var array<string, array<string>> $tableOverTca */
        $dbList->tableTSconfigOverTCA = $tableOverTca;
        $dbList->allowedNewTables = GeneralUtility::trimExplode(',', (string) ($this->modTSconfig['allowedNewTables'] ?? ''), true);
        $dbList->deniedNewTables = GeneralUtility::trimExplode(',', (string) ($this->modTSconfig['deniedNewTables'] ?? ''), true);
        /** @var array<string> $pageRecord */
        $pageRecord = $this->pageContext->pageRecord ?? [];
        $dbList->pageRow = $pageRecord;
        $dbList->modTSconfig = $this->modTSconfig;
        $dbList->setLanguagesAllowedForUser($siteLanguages);
        $clickTitleMode = trim((string) ($this->modTSconfig['clickTitleMode'] ?? ''));
        $dbList->clickTitleMode = $clickTitleMode === '' ? 'edit' : $clickTitleMode;
        $tableDisplayOrder = $this->modTSconfig['tableDisplayOrder'] ?? null;
        if (is_array($tableDisplayOrder)) {
            $dbList->setTableDisplayOrder($tableDisplayOrder);
        }

        // Initialize clipboard
        $clipboard = $this->initializeClipboard($request, (bool) $this->moduleData->get('clipBoard'));
        $dbList->clipObj = $clipboard;

        // Store clipboard state for renderViewContent()
        $this->clipboardObj = $clipboard;
        $this->clipboardEnabled = $this->moduleData !== null && (bool) $this->moduleData->get('clipBoard');

        // Dispatch additional content event
        $additionalRecordListEvent = new RenderAdditionalContentToRecordListEvent($request);
        $this->eventDispatcher->dispatch($additionalRecordListEvent);

        // Create module template (this sets up the backend frame)
        $view = $this->moduleTemplateFactory->create($request);

        // Handle delete command if posted
        if ($cmd === 'delete' && $request->getMethod() === 'POST') {
            $this->deleteRecords($request, $clipboard);
        }

        // =========================================================================
        // Render the appropriate view based on mode
        // =========================================================================

        $viewModeResolver = GeneralUtility::makeInstance(ViewModeResolver::class);
        $viewMode = $viewModeResolver->getActiveViewMode($request, $pageId);

        // Initialize dbList for URL building, clipboard functionality, and search queries
        $dbList->start($this->pageContext->pageId, $this->table, $pointer, $this->searchTerm, $searchLevels);

        // Render the appropriate view (all view types use the same render path)
        $customContent = $this->renderViewContent($request, $pageId, $this->table, $this->searchTerm, $searchLevels, $viewMode);

        // Page title
        if ($this->pageContext->pageId === 0) {
            $typo3ConfVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
            $sysConfig = is_array($typo3ConfVars['SYS'] ?? null) ? $typo3ConfVars['SYS'] : [];
            $sitenameVal = $sysConfig['sitename'] ?? '';
            $title = is_string($sitenameVal) ? $sitenameVal : '';
        } else {
            $title = $this->pageContext->getPageTitle();
        }

        // Page translations
        $pageTranslationsHtml = '';
        if ($this->pageContext->pageId !== 0 && $this->searchTerm === '' && $cmd === '' && $this->table === '' && $this->showPageTranslations()) {
            $pageTranslationsHtml = $this->renderPageTranslations($dbList, $siteLanguages);
        }

        // Search box - use full searchLevels (grid/compact now support search properly)
        $searchBoxHtml = '';
        if ($this->allowSearch && $this->moduleData !== null && (bool) $this->moduleData->get('searchBox')) {
            $searchBoxHtml = $this->renderSearchBox($request, $dbList, $this->searchTerm, $searchLevels);
        }

        // Clipboard
        $clipboardHtml = '';
        if ($this->moduleData !== null && (bool) $this->moduleData->get('clipBoard') && ($customContent !== '' || $clipboard->hasElements())) {
            $clipboardHtml = '<hr class="spacer"><typo3-backend-clipboard-panel return-url="' . htmlspecialchars((string) $dbList->listURL()) . '"></typo3-backend-clipboard-panel>';
        }

        // Set page title
        $view->setTitle(
            $languageService->translate('title', 'backend.modules.list') ?? '',
            $title,
        );

        // Add page breadcrumb
        if ($this->pageContext->pageRecord !== null) {
            $view->getDocHeaderComponent()->setPageBreadcrumb($this->pageContext->pageRecord);
        }

        // =========================================================================
        // DocHeader buttons - using parent's method for proper button bar setup
        // =========================================================================
        $this->getDocHeaderButtons($view, $clipboard, $request, $dbList);

        // Assign all view variables
        $view->assignMultiple([
            'pageId' => $this->pageContext->pageId,
            'pageTitle' => $title,
            'isPageEditable' => $this->isPageEditable(),
            'additionalContentTop' => $additionalRecordListEvent->getAdditionalContentAbove(),
            'pageTranslationsHtml' => $pageTranslationsHtml,
            'searchBoxHtml' => $searchBoxHtml,
            'tableListHtml' => $customContent, // Custom view content instead of table
            'clipboardHtml' => $clipboardHtml,
            'additionalContentBottom' => $additionalRecordListEvent->getAdditionalContentBelow(),
        ]);

        return $view->renderResponse('RecordList');
    }

    /**
     * Override renderSearchBox to use the 'records' route with displayMode parameter.
     * This ensures the view mode is preserved when submitting a search and that
     * the search actually goes to the grid view controller, not the core list view.
     */
    protected function renderSearchBox(
        ServerRequestInterface $request,
        DatabaseRecordList $dbList,
        string $searchWord,
        int $searchLevels,
    ): string {
        // Get the current view mode
        $viewModeResolver = GeneralUtility::makeInstance(ViewModeResolver::class);
        $viewMode = $viewModeResolver->getActiveViewMode($request, $this->pageContext->pageId);

        // Build the search URL using the 'records' route (not web_list)
        // This is critical - dbList->listURL() returns a web_list URL which bypasses our controller
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        $searchParams = [
            'id' => $this->pageContext->pageId,
            'displayMode' => $viewMode,
        ];

        // Preserve table filter if set
        if ($this->table !== '') {
            $searchParams['table'] = $this->table;
        }

        try {
            $baseUrl = (string) $uriBuilder->buildUriFromRoute('records', $searchParams);
        } catch (Exception $e) {
            // Fallback to dbList URL if route building fails
            $baseUrl = (string) $dbList->listURL('', '-1', 'pointer,searchTerm,displayMode');
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $baseUrl .= $separator . 'displayMode=' . urlencode($viewMode);
        }

        $searchLevelItems = [];
        $searchLevelCfg = $this->modTSconfig['searchLevel'] ?? null;
        if (is_array($searchLevelCfg) && is_array($searchLevelCfg['items'] ?? null)) {
            $searchLevelItems = $searchLevelCfg['items'];
        }

        $searchBox = GeneralUtility::makeInstance(RecordSearchBoxComponent::class)
            ->setAllowedSearchLevels($searchLevelItems)
            ->setSearchWord($searchWord)
            ->setSearchLevel($searchLevels)
            ->render($request, $baseUrl);

        return $searchBox;
    }

    /**
     * Render any view type (grid, compact, teaser, or custom).
     *
     * All view types share the same data pipeline: fetch records, enrich with
     * display values, build pagination, create action buttons, and render via
     * Fluid. The only differences are handled by ViewTypeRegistry (template,
     * CSS, JS, display columns) and by computing all optional data (sorting
     * toggle, column headers, language flags, middleware warning) for every
     * view -- templates simply ignore what they don't need.
     */
    protected function renderViewContent(
        ServerRequestInterface $request,
        int $pageId,
        string $table,
        string $searchTerm,
        int $searchLevels,
        string $viewMode,
    ): string {
        // Get view type configuration (null = unknown type, fall back to grid)
        $registry = $this->getViewTypeRegistry();
        $viewConfig = $registry->getViewType($viewMode, $pageId);
        if ($viewConfig === null) {
            $viewMode = 'grid';
            $viewConfig = $registry->getViewType($viewMode, $pageId);
        }

        // Services
        $gridConfigurationService = GeneralUtility::makeInstance(GridConfigurationService::class);
        $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
        $middlewareDiagnosticService = GeneralUtility::makeInstance(MiddlewareDiagnosticService::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        // Parse request parameters
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $parsedBodyArray = is_array($parsedBody) ? $parsedBody : [];
        $sortParams = (array) ($queryParams['sort'] ?? $parsedBodyArray['sort'] ?? []);
        $sortingModeParams = (array) ($queryParams['sortingMode'] ?? $parsedBodyArray['sortingMode'] ?? []);

        // Middleware diagnostics (only GridView template shows this)
        $middlewareWarning = null;
        $forceListViewUrl = null;
        $diagnosis = $middlewareDiagnosticService->diagnose($request);
        if ($diagnosis['hasRisk']) {
            $middlewareWarning = $middlewareDiagnosticService->getWarningMessage($request);
            $forceListViewUrl = $diagnosis['forceListViewUrl'];
        }

        // Display columns configuration from ViewTypeRegistry
        $columnsConfig = $registry->getDisplayColumnsConfig($viewMode, $pageId);

        // Get tables to display
        $tablesToRender = $this->getSearchableTables($pageId, $table, $searchTerm, $searchLevels, $request);

        // Collect all records grouped by table
        $tableData = [];
        foreach ($tablesToRender as $tableName) {
            $tableConfig = $gridConfigurationService->getTableConfig($tableName, $pageId);

            // TCA info for sorting capabilities
            $tcaForTable = $this->getTcaForTable($tableName);
            $tcaCtrl = $tcaForTable['ctrl'];
            $sortbyVal = $tcaCtrl['sortby'] ?? '';
            $sortbyFieldName = is_string($sortbyVal) ? $sortbyVal : '';
            $hasSortbyField = $sortbyFieldName !== '';

            // Per-table sorting mode (manual drag vs. field-based)
            $sortingModeVal = $sortingModeParams[$tableName] ?? '';
            $sortingMode = is_string($sortingModeVal) ? $sortingModeVal : '';
            if ($sortingMode === '') {
                $sortingMode = $hasSortbyField ? 'manual' : 'field';
            }

            // Per-table sorting parameters
            $tableSortParams = is_array($sortParams[$tableName] ?? null) ? $sortParams[$tableName] : [];
            $sortFieldVal = $tableSortParams['field'] ?? '';
            $sortField = is_string($sortFieldVal) ? $sortFieldVal : '';
            $sortDirVal = $tableSortParams['direction'] ?? 'asc';
            $sortDirection = is_string($sortDirVal) ? $sortDirVal : 'asc';
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            // In manual mode, sort by the TCA sortby field
            if ($sortingMode === 'manual' && $hasSortbyField) {
                $sortField = $sortbyFieldName;
            }

            $isSingleTableMode = ($table !== '');
            $totalRecordCount = $recordGridDataProvider->getRecordCount($tableName, $pageId);

            // Pagination and record fetching strategy depends on mode + search state
            if ($searchTerm !== '') {
                // ---- SEARCH MODE ----
                // Get total count of matching records for this table
                $searchTotalCount = $this->getSearchRecordCount($tableName, $pageId, $searchTerm, $searchLevels, $request);

                if ($isSingleTableMode) {
                    // Single-table search: full pagination support
                    $itemsPerPage = $this->getItemsPerPage($viewMode, $pageId);
                    $currentPointer = $this->getCurrentPointer($request, $tableName);
                    $offset = ($currentPointer - 1) * $itemsPerPage;
                    $records = $this->getRecordsUsingDbList($request, $tableName, $tableConfig, $pageId, $searchTerm, $searchLevels, $itemsPerPage, $offset);
                    $recordCount = $searchTotalCount;
                    $hasMore = false; // pagination handles navigation
                } else {
                    // Multi-table search: show limited results with "Expand table"
                    $itemsPerPage = $this->getItemsLimitPerTable($pageId);
                    $currentPointer = 1;
                    $offset = 0;
                    $records = $this->getRecordsUsingDbList($request, $tableName, $tableConfig, $pageId, $searchTerm, $searchLevels, $itemsPerPage);
                    $recordCount = $searchTotalCount;
                    $hasMore = $searchTotalCount > count($records);
                }
            } elseif ($isSingleTableMode) {
                // ---- SINGLE TABLE, NO SEARCH ----
                $itemsPerPage = $this->getItemsPerPage($viewMode, $pageId);
                $currentPointer = $this->getCurrentPointer($request, $tableName);
                $offset = ($currentPointer - 1) * $itemsPerPage;
                $records = $recordGridDataProvider->getRecordsForTable($tableName, $pageId, $itemsPerPage, $offset, $searchTerm, $sortField, $sortDirection);
                $recordCount = $totalRecordCount;
                $hasMore = false; // pagination handles navigation
            } else {
                // ---- MULTI TABLE, NO SEARCH ----
                $itemsPerPage = $this->getItemsLimitPerTable($pageId);
                $currentPointer = 1;
                $offset = 0;
                $records = $recordGridDataProvider->getRecordsForTable($tableName, $pageId, $itemsPerPage, $offset, $searchTerm, $sortField, $sortDirection);
                $recordCount = $totalRecordCount;
                $hasMore = $recordCount > count($records);
            }

            if ($records === []) {
                continue;
            }

            // Pagination
            $paginationData = $this->buildPagination(
                $records,
                $recordCount,
                $currentPointer,
                $itemsPerPage,
                $tableName,
                $pageId,
                $viewMode,
                $request,
            );

            // Action buttons
            $actionButtons = $this->createTableActionButtons(
                $tableName,
                $pageId,
                $viewMode,
                $request,
                $recordCount,
                $isSingleTableMode,
            );

            // Table URLs
            $singleTableUrl = '';
            $clearTableUrl = '';
            try {
                $singleTableUrlParams = [
                    'id' => $pageId,
                    'table' => $tableName,
                    'displayMode' => $viewMode,
                ];
                // Preserve search parameters when expanding a table during search
                if ($searchTerm !== '') {
                    $singleTableUrlParams['searchTerm'] = $searchTerm;
                    if ($searchLevels > 0) {
                        $singleTableUrlParams['search_levels'] = $searchLevels;
                    }
                }
                $singleTableUrl = (string) $uriBuilder->buildUriFromRoute('records', $singleTableUrlParams);
                $clearTableUrlParams = [
                    'id' => $pageId,
                    'displayMode' => $viewMode,
                ];
                // Preserve search parameters when collapsing back to multi-table view
                if ($searchTerm !== '') {
                    $clearTableUrlParams['searchTerm'] = $searchTerm;
                    if ($searchLevels > 0) {
                        $clearTableUrlParams['search_levels'] = $searchLevels;
                    }
                }
                $clearTableUrl = (string) $uriBuilder->buildUriFromRoute('records', $clearTableUrlParams);
            } catch (Exception $e) {
            }

            // Display columns (from ViewTypeRegistry config)
            $columnsArray = is_array($columnsConfig['columns'] ?? null) ? $columnsConfig['columns'] : [];
            if ((bool) ($columnsConfig['fromTCA'] ?? false)) {
                $displayColumns = $this->getDisplayColumns($tableName);
            } elseif ($columnsArray !== []) {
                $displayColumns = $this->getSpecificDisplayColumns($tableName, $columnsArray);
            } else {
                $displayColumns = $this->getTeaserDisplayColumns($tableName);
            }

            // Enrich records
            $enrichedRecords = $this->enrichRecordsWithDisplayValues($records, $displayColumns, $tableName);
            $enrichedRecords = $this->enrichRecordsWithLanguageInfo($enrichedRecords, $tableName);

            // Sorting dropdown
            $sortableFields = $recordGridDataProvider->getSortableFields($tableName);
            $sortingDropdownHtml = $this->createSortingDropdown(
                $tableName,
                $sortableFields,
                $sortField,
                $sortDirection,
                $pageId,
                $viewMode,
                $request,
            );

            // Sorting mode toggle (used by GridView template for manual/field switch)
            $sortingModeToggleHtml = '';
            if ($hasSortbyField) {
                $sortingModeToggleHtml = $this->createSortingModeToggle(
                    $tableName,
                    $sortingMode,
                    $sortDirection,
                    $pageId,
                    $viewMode,
                    $request,
                    $sortingDropdownHtml,
                );
            } elseif ($sortingDropdownHtml !== '') {
                $sortingModeToggleHtml = '<div class="gridview-sorting-wrapper me-2">'
                    . '<div class="gridview-sorting-toggle btn-group" role="group">'
                    . '<div class="btn-group" role="group">'
                    . $sortingDropdownHtml
                    . '</div></div></div>';
            }

            // Sortable column headers (used by CompactView template)
            $sortableColumnHeaders = $this->getSortableColumnHeaders(
                $tableName,
                $displayColumns,
                $sortField,
                $sortDirection,
                $pageId,
                $viewMode,
                $request,
            );

            // Last record UID for drag-drop end dropzone (used by GridView template)
            $lastRecordUid = '';
            if ($enrichedRecords !== []) {
                $lastUidVal = $enrichedRecords[array_key_last($enrichedRecords)]['uid'] ?? 0;
                $lastRecordUid = is_scalar($lastUidVal) ? (string) $lastUidVal : '';
            }

            // Drag-and-drop reordering
            $canReorder = $sortingMode === 'manual' && $hasSortbyField;

            $tableData[] = [
                'tableName' => $tableName,
                'tableIdentifier' => $tableName,
                'tableLabel' => $this->getTableLabel($tableName),
                'tableIcon' => $this->getTableIcon($tableName),
                'tableConfig' => $tableConfig,
                'records' => $enrichedRecords,
                'recordCount' => $recordCount,
                'hasMore' => $hasMore,
                'lastRecordUid' => $lastRecordUid,
                'actionButtons' => $actionButtons,
                'sortingDropdownHtml' => $sortingDropdownHtml,
                'sortingModeToggleHtml' => $sortingModeToggleHtml,
                'sortableColumnHeaders' => $sortableColumnHeaders,
                'singleTableUrl' => $singleTableUrl,
                'clearTableUrl' => $clearTableUrl,
                'displayColumns' => $displayColumns,
                'isFiltered' => $isSingleTableMode && $table === $tableName,
                'canReorder' => $canReorder,
                'sortField' => $sortField,
                'sortDirection' => $sortDirection,
                'hasSortbyField' => $hasSortbyField,
                'sortingMode' => $sortingMode,
                'sortbyFieldName' => $sortbyFieldName,
                'paginator' => $paginationData['paginator'],
                'pagination' => $paginationData['pagination'],
                'paginationUrl' => $paginationData['currentUrl'],
            ];
        }

        // Load CSS and JS from ViewTypeRegistry
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        foreach ($registry->getCssFiles($viewMode, $pageId) as $cssFile) {
            $pageRenderer->addCssFile($cssFile);
        }
        foreach ($registry->getJsModules($viewMode, $pageId) as $jsModule) {
            $pageRenderer->loadJavaScriptModule($jsModule);
        }
        $pageRenderer->loadJavaScriptModule('@typo3/backend/column-selector-button.js');

        // Create the view from ViewTypeRegistry template paths
        $templatePaths = $registry->getTemplatePaths($viewMode, $pageId);
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: $templatePaths['templateRootPaths'],
            partialRootPaths: $templatePaths['partialRootPaths'],
            layoutRootPaths: $templatePaths['layoutRootPaths'],
            request: $request,
        );

        $view = $viewFactory->create($viewFactoryData);
        $view->assignMultiple([
            'pageId' => $pageId,
            'tableData' => $tableData,
            'currentTable' => $table,
            'searchTerm' => $searchTerm,
            'viewMode' => $viewMode,
            'viewConfig' => $viewConfig,
            'middlewareWarning' => $middlewareWarning,
            'forceListViewUrl' => $forceListViewUrl,
            'clipboardEnabled' => $this->clipboardEnabled,
        ]);

        return $view->render($templatePaths['template']);
    }

    /**
     * Get specific display columns by field names.
     *
     * @param string $tableName The table name
     * @param array<int, string> $fieldNames Array of field names to include
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    protected function getSpecificDisplayColumns(string $tableName, array $fieldNames): array
    {
        $columns = [];
        $tcaForTable = $this->getTcaForTable($tableName);
        $ctrl = $tcaForTable['ctrl'];
        $tcaColumns = $tcaForTable['columns'];
        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';

        foreach ($fieldNames as $field) {
            // Handle special field names
            if ($field === 'label') {
                $field = $labelField;
            } elseif ($field === 'datetime' || $field === 'date') {
                // Find first date field
                $dateFields = ['datetime', 'date', 'starttime'];
                foreach ($dateFields as $df) {
                    if (isset($tcaColumns[$df])) {
                        $field = $df;
                        break;
                    }
                }
                if ($field === 'datetime') {
                    $crdateFieldVal = $ctrl['crdate'] ?? '';
                    if (is_string($crdateFieldVal) && $crdateFieldVal !== '') {
                        $field = $crdateFieldVal;
                    }
                }
            } elseif ($field === 'teaser') {
                // Find first teaser field
                $teaserFields = ['teaser', 'abstract', 'description', 'bodytext', 'short'];
                foreach ($teaserFields as $tf) {
                    if (isset($tcaColumns[$tf])) {
                        $field = $tf;
                        break;
                    }
                }
            }

            // Skip if field doesn't exist
            if (!isset($tcaColumns[$field]) && !in_array($field, ['uid', 'pid', $ctrl['crdate'] ?? '', $ctrl['tstamp'] ?? ''], true)) {
                continue;
            }

            $columns[] = [
                'field' => $field,
                'label' => $this->getFieldLabel($field, $tcaColumns, $ctrl),
                'type' => $this->getFieldType($field, $tcaColumns, $ctrl),
                'isLabelField' => ($field === $labelField),
            ];
        }

        return $columns;
    }

    /**
     * Get display columns for teaser view - minimal set: title, date, teaser.
     *
     * @param string $tableName The table name
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    protected function getTeaserDisplayColumns(string $tableName): array
    {
        $columns = [];
        $tcaForTable = $this->getTcaForTable($tableName);
        $ctrl = $tcaForTable['ctrl'];
        $tcaColumns = $tcaForTable['columns'];

        // 1. Label field (title) - always first
        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';
        if (isset($tcaColumns[$labelField])) {
            $columns[] = [
                'field' => $labelField,
                'label' => $this->getFieldLabel($labelField, $tcaColumns, $ctrl),
                'type' => 'text',
                'isLabelField' => true,
            ];
        }

        // 2. Date field - prefer datetime, then crdate, then tstamp
        $dateField = null;
        $dateFields = ['datetime', 'date', 'starttime'];
        foreach ($dateFields as $field) {
            if (isset($tcaColumns[$field])) {
                $dateField = $field;
                break;
            }
        }
        if ($dateField === null) {
            $crdateVal = $ctrl['crdate'] ?? '';
            if (is_string($crdateVal) && $crdateVal !== '') {
                $dateField = $crdateVal;
            }
        }
        if ($dateField !== null) {
            $columns[] = [
                'field' => $dateField,
                'label' => $this->getFieldLabel($dateField, $tcaColumns, $ctrl),
                'type' => 'datetime',
                'isLabelField' => false,
            ];
        }

        // 3. Teaser/description field - prefer teaser, abstract, bodytext
        $teaserFields = ['teaser', 'abstract', 'description', 'bodytext', 'short'];
        foreach ($teaserFields as $field) {
            if (isset($tcaColumns[$field]) && $field !== $labelField) {
                $columns[] = [
                    'field' => $field,
                    'label' => $this->getFieldLabel($field, $tcaColumns, $ctrl),
                    'type' => 'text',
                    'isLabelField' => false,
                ];
                break;
            }
        }

        return $columns;
    }

    /**
     * Get the list of tables to render.
     */
    /**
     * @return array<int, string>
     */
    protected function getTablesToRender(int $pageId, string $specificTable, RecordGridDataProvider $dataProvider): array
    {
        if ($specificTable !== '') {
            return [$specificTable];
        }

        // Get all tables that have records on this page
        $tables = [];
        $allTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        foreach ($allTca as $tableName => $tca) {
            if (!is_string($tableName) || !is_array($tca)) {
                continue;
            }
            // Skip hidden tables
            $ctrlArr = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
            if (isset($ctrlArr['hideTable']) && (bool) $ctrlArr['hideTable']) {
                continue;
            }

            $count = $dataProvider->getRecordCount($tableName, $pageId);
            if ($count > 0) {
                $tables[] = $tableName;
            }
        }

        return $tables;
    }

    /**
     * Get tables that should be rendered, considering search.
     *
     * When searching, checks which tables have matching records.
     * When not searching, returns tables with records on the current page.
     *
     * @param int $pageId The current page ID
     * @param string $specificTable If set, only this table is returned
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth level
     * @param ServerRequestInterface $request The current request
     * @return array<int, string> List of table names
     */
    protected function getSearchableTables(
        int $pageId,
        string $specificTable,
        string $searchTerm,
        int $searchLevels,
        ServerRequestInterface $request,
    ): array {
        // If a specific table is selected, only return that
        if ($specificTable !== '') {
            return [$specificTable];
        }

        $tables = [];
        $backendUser = $this->getBackendUserAuthentication();

        // Get hidden tables from TSconfig
        $hideTables = GeneralUtility::trimExplode(',', (string) ($this->modTSconfig['hideTables'] ?? ''), true);

        $allTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        foreach ($allTca as $tableName => $tca) {
            if (!is_string($tableName) || !is_array($tca)) {
                continue;
            }
            // Skip hidden tables
            $ctrlArr = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
            if (isset($ctrlArr['hideTable']) && (bool) $ctrlArr['hideTable']) {
                continue;
            }

            // Skip tables hidden by TSconfig
            if (in_array($tableName, $hideTables, true)) {
                continue;
            }

            // Check user permissions
            if (!$backendUser->check('tables_select', $tableName)) {
                continue;
            }

            // When searching, check if this table has matching records
            if ($searchTerm !== '') {
                try {
                    // Create a properly initialized DatabaseRecordList for this table
                    $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, $searchTerm, $searchLevels, $request);

                    // Use the initialized dbList to check if table has matching records
                    // This uses the same search configuration as the core list view
                    $queryBuilder = $dbList->getQueryBuilder($tableName, ['uid'], false, 0, 1);
                    $hasRecords = $queryBuilder->executeQuery()->fetchOne() !== false;

                    if ($hasRecords) {
                        $tables[] = $tableName;
                    }
                } catch (Exception $e) {
                    // Table might not be accessible, skip it
                    continue;
                }
            } else {
                // No search - check if table has records on this page
                $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
                $count = $recordGridDataProvider->getRecordCount($tableName, $pageId);
                if ($count > 0) {
                    $tables[] = $tableName;
                }
            }
        }

        return $tables;
    }

    /**
     * Create a properly initialized DatabaseRecordList for a specific table.
     *
     * This ensures the DatabaseRecordList has the correct internal state for
     * search queries, including searchString, searchLevels, and page context.
     *
     * @param string $tableName The table to initialize for
     * @param int $pageId The current page ID
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth level
     * @param ServerRequestInterface $request The current request
     * @return DatabaseRecordList The initialized DatabaseRecordList
     */
    protected function createDatabaseRecordListForTable(
        string $tableName,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        ServerRequestInterface $request,
    ): DatabaseRecordList {
        $dbList = GeneralUtility::makeInstance(DatabaseRecordList::class);
        $dbList->setRequest($request);
        $dbList->modTSconfig = $this->modTSconfig;
        $dbList->start($pageId, $tableName, 0, $searchTerm, $searchLevels);
        return $dbList;
    }

    /**
     * Get records using DatabaseRecordList's query builder.
     *
     * This leverages TYPO3's native search functionality including searchLevels
     * and workspace handling. Uses the same API as the core list view.
     *
     * @param ServerRequestInterface $request The current request
     * @param string $tableName The table to query
     * @param array<string, mixed> $tableConfig The grid configuration for this table
     * @param int $pageId The current page ID
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth level
     * @param int $limit Maximum number of records to fetch (0 = no limit)
     * @param int $offset Number of records to skip (for pagination)
     * @return array<int, array<string, mixed>> Array of enriched record data
     */
    protected function getRecordsUsingDbList(
        ServerRequestInterface $request,
        string $tableName,
        array $tableConfig,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $records = [];

        try {
            // Create a properly initialized DatabaseRecordList for this table
            $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, $searchTerm, $searchLevels, $request);

            // Use DatabaseRecordList's query builder which handles search properly
            // This is the same API the core list view uses
            $queryBuilder = $dbList->getQueryBuilder($tableName, ['*'], true, $offset, $limit);
            $result = $queryBuilder->executeQuery();

            while ($row = $result->fetchAssociative()) {
                // Apply workspace overlay to get the correct version for the current workspace
                BackendUtility::workspaceOL($tableName, $row);

                // workspaceOL returns false/null if record is deleted in workspace or should not be shown
                if (!is_array($row)) {
                    continue;
                }

                $uid = (int) $row['uid'];
                $recordPid = (int) ($row['pid'] ?? $pageId);

                // Get title field
                $tcaForTableRecords = $this->getTcaForTable($tableName);
                $titleFieldVal = $tableConfig['titleField'] ?? $tcaForTableRecords['ctrl']['label'] ?? 'uid';
                $titleField = is_string($titleFieldVal) ? $titleFieldVal : 'uid';
                $titleRaw = $row[$titleField] ?? '[No title]';
                if (is_array($titleRaw)) {
                    $titleRaw = reset($titleRaw);
                }
                $title = is_scalar($titleRaw) ? (string) $titleRaw : '[No title]';

                // Get icon identifier
                $icon = $iconFactory->getIconForRecord($tableName, $row, \TYPO3\CMS\Core\Imaging\IconSize::SMALL);
                $iconIdentifier = $icon->getIdentifier();

                // Check hidden status
                $enableColsRecords = is_array($tcaForTableRecords['ctrl']['enablecolumns'] ?? null) ? $tcaForTableRecords['ctrl']['enablecolumns'] : [];
                $hiddenFieldVal = $enableColsRecords['disabled'] ?? null;
                $hiddenField = is_string($hiddenFieldVal) ? $hiddenFieldVal : null;
                $hidden = ($hiddenField !== null && $hiddenField !== '') ? (bool) ($row[$hiddenField] ?? false) : false;

                $records[] = [
                    'uid' => $uid,
                    'pid' => $recordPid,
                    'tableName' => $tableName,
                    'title' => $title,
                    'description' => null,
                    'thumbnail' => null,
                    'thumbnailUrl' => null,
                    'iconIdentifier' => $iconIdentifier,
                    'hidden' => $hidden,
                    'rawRecord' => $row,
                    'actions' => [],
                ];
            }
        } catch (Exception $e) {
            // Log error but don't fail - return empty results
            // This can happen if the table doesn't exist or user lacks permissions
        }

        return $records;
    }

    /**
     * Get total count of search results for a table.
     *
     * Uses the same DatabaseRecordList query builder as getRecordsUsingDbList()
     * to build a COUNT query with identical search/WHERE conditions.
     *
     * @param string $tableName The table to count records for
     * @param int $pageId The current page ID
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth level
     * @param ServerRequestInterface $request The current request
     * @return int Total number of matching records
     */
    protected function getSearchRecordCount(
        string $tableName,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        ServerRequestInterface $request,
    ): int {
        try {
            $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, $searchTerm, $searchLevels, $request);
            $qb = $dbList->getQueryBuilder($tableName, ['uid'], false, 0, 0);
            return (int) $qb->count('*')->executeQuery()->fetchOne();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Create table action buttons using TYPO3's ComponentFactory API.
     *
     * Returns an array with rendered HTML for each button type:
     * - newRecordButton: HTML for "New record" button
     * - downloadButton: HTML for "Download/Export" button
     * - columnSelectorButton: HTML for "Show columns" button (web component)
     * - collapseButton: HTML for "Collapse/Expand" button
     *
     * @param string $tableName The database table name
     * @param int $pageId The current page ID
     * @param string $viewMode The current view mode (grid/compact)
     * @param ServerRequestInterface $request The current request
     * @param int $recordCount Number of records for this table
     * @param bool $isSingleTableMode Whether we're in single table view mode
     * @return array<string, string> Array of rendered button HTML
     */
    protected function createTableActionButtons(
        string $tableName,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
        int $recordCount,
        bool $isSingleTableMode,
    ): array {
        $componentFactory = GeneralUtility::makeInstance(ComponentFactory::class);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $lang = $this->getLanguageService();
        $backendUser = $this->getBackendUserAuthentication();

        $buttons = [
            'newRecordButton' => '',
            'downloadButton' => '',
            'columnSelectorButton' => '',
            'collapseButton' => '',
        ];

        // New record button - check if user can modify this table
        if ($backendUser->check('tables_modify', $tableName)) {
            try {
                $newRecordUrl = (string) $uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => [$tableName => [$pageId => 'new']],
                    'returnUrl' => (string) $request->getUri(),
                ]);

                $newButton = $componentFactory->createLinkButton()
                    ->setHref($newRecordUrl)
                    ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf:newRecordGeneral'))
                    ->setIcon($iconFactory->getIcon('actions-plus', IconSize::SMALL))
                    ->setShowLabelText(true);

                $buttons['newRecordButton'] = $newButton->render();
            } catch (Exception $e) {
                // Route not found
            }
        }

        // Download/Export button
        if ($backendUser->isExportEnabled() && $recordCount > 0) {
            try {
                $downloadUrl = (string) $uriBuilder->buildUriFromRoute('tx_impexp_export', [
                    'tx_impexp' => ['list' => [$tableName . ':' . $pageId]],
                ]);

                $exportLabel = $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_alt_doc.xlf:export');
                $downloadButton = $componentFactory->createLinkButton()
                    ->setHref($downloadUrl)
                    ->setTitle($exportLabel !== '' ? $exportLabel : 'Download')
                    ->setIcon($iconFactory->getIcon('actions-download', IconSize::SMALL))
                    ->setShowLabelText(true);

                $buttons['downloadButton'] = $downloadButton->render();
            } catch (Exception $e) {
                // impexp not installed
            }
        }

        // Column selector button (using TYPO3 web component)
        try {
            $columnSelectorUrl = (string) $uriBuilder->buildUriFromRoute('ajax_show_columns_selector', [
                'id' => $pageId,
                'table' => $tableName,
            ]);
            $columnSelectorTarget = (string) $uriBuilder->buildUriFromRoute('records', [
                'id' => $pageId,
                'displayMode' => $viewMode,
            ]) . '#recordlist-' . $tableName;

            $tableLabel = $this->getTableLabel($tableName);
            $showColumnsSelectionLabel = $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:showColumnsSelection');
            $updateColumnViewLabel = $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:updateColumnView');
            $cancelLabel = $lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.cancel');
            $errorLabel = $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:updateColumnView.error');
            $showColumnsLabel = $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:showColumns');
            $buttons['columnSelectorButton'] = sprintf(
                '<typo3-backend-column-selector-button
                    data-url="%s"
                    data-target="%s"
                    data-title="%s"
                    data-button-ok="%s"
                    data-button-close="%s"
                    data-error-message="%s">
                    <button type="button" class="btn btn-default btn-sm">%s <span>%s</span></button>
                </typo3-backend-column-selector-button>',
                htmlspecialchars($columnSelectorUrl),
                htmlspecialchars($columnSelectorTarget),
                htmlspecialchars($showColumnsSelectionLabel),
                htmlspecialchars($updateColumnViewLabel !== '' ? $updateColumnViewLabel : 'Update'),
                htmlspecialchars($cancelLabel !== '' ? $cancelLabel : 'Cancel'),
                htmlspecialchars($errorLabel !== '' ? $errorLabel : 'Error'),
                $iconFactory->getIcon('actions-options', IconSize::SMALL)->render(),
                htmlspecialchars($showColumnsLabel !== '' ? $showColumnsLabel : 'Show columns'),
            );
        } catch (Exception $e) {
            // Route not found
        }

        // Collapse/Expand button (only in multi-table mode)
        if (!$isSingleTableMode) {
            $collapseButton = $componentFactory->createGenericButton()
                ->setTag('button')
                ->setAttributes([
                    'type' => 'button',
                    'class' => 'btn btn-default btn-sm t3js-toggle-recordlist',
                    'data-bs-toggle' => 'collapse',
                    'data-bs-target' => '#recordlist-' . $tableName,
                    'aria-expanded' => 'true',
                    'data-table' => $tableName,
                ])
                ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf:collapseExpandTable'))
                ->setIcon($iconFactory->getIcon('actions-view-list-collapse', IconSize::SMALL));

            $buttons['collapseButton'] = $collapseButton->render();
        }

        return $buttons;
    }

    /**
     * Create sorting dropdown using TYPO3's native ComponentFactory API.
     *
     * Creates a DropDownButton with sortable fields and direction options.
     */
    /**
     * @param array<int, array{field: string, label: string}> $sortableFields
     */
    protected function createSortingDropdown(
        string $tableName,
        array $sortableFields,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): string {
        if ($sortableFields === []) {
            return '';
        }

        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $lang = $this->getLanguageService();

        // Determine current icon based on direction
        $sortIcon = $currentSortDirection === 'desc' ? 'actions-sort-amount-down' : 'actions-sort-amount-up';

        // Get label for "Nach Spalte" - show current field name if selected
        $fieldLabelTranslated = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.field');
        $fieldLabel = $fieldLabelTranslated !== '' ? $fieldLabelTranslated : 'Nach Spalte';
        if ($currentSortField !== '') {
            // Find the label for the current sort field
            foreach ($sortableFields as $field) {
                if (($field['field'] ?? '') === $currentSortField) {
                    $fieldLabel = $field['label'] ?? $currentSortField;
                    break;
                }
            }
        }

        // Preserve query parameters from current request
        $queryParams = $request->getQueryParams();
        $preserveParams = ['table', 'searchTerm', 'search_levels', 'pointer'];
        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        foreach ($preserveParams as $param) {
            if (isset($queryParams[$param]) && $queryParams[$param] !== '') {
                $baseParams[$param] = $queryParams[$param];
            }
        }

        // Build custom dropdown HTML matching the toggle button styling
        $html = '<button type="button" class="btn btn-default btn-sm gridview-sorting-toggle__btn gridview-sorting-toggle__btn--active dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">';
        $html .= $iconFactory->getIcon($sortIcon, IconSize::SMALL)->render();
        $html .= ' <span>' . htmlspecialchars($fieldLabel) . '</span>';
        $html .= '</button>';
        $html .= '<ul class="dropdown-menu">';

        // Add sort field options
        foreach ($sortableFields as $field) {
            $fieldName = $field['field'] ?? '';
            $itemLabel = $field['label'] ?? $fieldName;

            if ($fieldName === '') {
                continue;
            }

            $isActive = ($fieldName === $currentSortField);

            try {
                $sortParams = $baseParams;
                $sortParams['sortingMode'][$tableName] = 'field';
                $sortParams['sort'][$tableName]['field'] = $fieldName;
                $sortParams['sort'][$tableName]['direction'] = $currentSortDirection;

                $url = (string) $uriBuilder->buildUriFromRoute('records', $sortParams);

                $html .= '<li><a class="dropdown-item' . ($isActive ? ' active' : '') . '" href="' . htmlspecialchars($url) . '">';
                $html .= htmlspecialchars($itemLabel);
                $html .= '</a></li>';
            } catch (Exception $e) {
                // Skip if URL building fails
            }
        }

        // Add divider
        $html .= '<li><hr class="dropdown-divider"></li>';

        // Add direction options
        $ascLabelTranslated = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.ascending');
        $ascLabel = $ascLabelTranslated !== '' ? $ascLabelTranslated : 'Aufsteigend';
        $descLabelTranslated = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.descending');
        $descLabel = $descLabelTranslated !== '' ? $descLabelTranslated : 'Absteigend';

        try {
            // Ascending option
            $ascParams = $baseParams;
            $ascParams['sortingMode'][$tableName] = 'field';
            if ($currentSortField !== '') {
                $ascParams['sort'][$tableName]['field'] = $currentSortField;
            }
            $ascParams['sort'][$tableName]['direction'] = 'asc';
            $ascUrl = (string) $uriBuilder->buildUriFromRoute('records', $ascParams);

            $html .= '<li><a class="dropdown-item' . ($currentSortDirection === 'asc' ? ' active' : '') . '" href="' . htmlspecialchars($ascUrl) . '">';
            $html .= $iconFactory->getIcon('actions-sort-amount-up', IconSize::SMALL)->render() . ' ' . htmlspecialchars($ascLabel);
            $html .= '</a></li>';

            // Descending option
            $descParams = $baseParams;
            $descParams['sortingMode'][$tableName] = 'field';
            if ($currentSortField !== '') {
                $descParams['sort'][$tableName]['field'] = $currentSortField;
            }
            $descParams['sort'][$tableName]['direction'] = 'desc';
            $descUrl = (string) $uriBuilder->buildUriFromRoute('records', $descParams);

            $html .= '<li><a class="dropdown-item' . ($currentSortDirection === 'desc' ? ' active' : '') . '" href="' . htmlspecialchars($descUrl) . '">';
            $html .= $iconFactory->getIcon('actions-sort-amount-down', IconSize::SMALL)->render() . ' ' . htmlspecialchars($descLabel);
            $html .= '</a></li>';
        } catch (Exception $e) {
            // Skip if URL building fails
        }

        $html .= '</ul>';
        return $html;
    }

    /**
     * Create a sorting mode toggle for switching between manual and field sorting.
     *
     * This creates a button group with two options:
     * - Manual Sorting: Enables drag-and-drop reordering, uses TCA sortby field
     * - Field Sorting: Uses the sorting dropdown to select sort field
     *
     * The toggle only appears for tables that have a sortby field defined in TCA.
     * When field mode is active, the sorting dropdown is shown next to the button.
     *
     * @param string $tableName The database table name
     * @param string $currentMode Current sorting mode ('manual' or 'field')
     * @param string $currentDirection Current sort direction for manual mode
     * @param int $pageId Current page ID
     * @param string $viewMode Current view mode (grid, etc.)
     * @param ServerRequestInterface $request Current request
     * @param string $sortingDropdownHtml The sorting dropdown HTML to show when field mode is active
     * @return string Rendered HTML for the sorting mode toggle
     */
    protected function createSortingModeToggle(
        string $tableName,
        string $currentMode,
        string $currentDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
        string $sortingDropdownHtml = '',
    ): string {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $lang = $this->getLanguageService();

        // Get labels
        $manualLabelT = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.manual');
        $manualLabel = $manualLabelT !== '' ? $manualLabelT : 'Manual Sorting';
        $fieldLabelT = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.field');
        $fieldLabel = $fieldLabelT !== '' ? $fieldLabelT : 'Field Sorting';
        $manualTitleT = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.manual.title');
        $manualTitle = $manualTitleT !== '' ? $manualTitleT : 'Enable drag-and-drop reordering';
        $fieldTitleT = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.field.title');
        $fieldTitle = $fieldTitleT !== '' ? $fieldTitleT : 'Sort by selected field';
        $ascLabelT2 = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.ascending');
        $ascLabel = $ascLabelT2 !== '' ? $ascLabelT2 : 'Ascending';
        $descLabelT2 = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.descending');
        $descLabel = $descLabelT2 !== '' ? $descLabelT2 : 'Descending';

        // Preserve query parameters from current request
        $queryParams = $request->getQueryParams();
        $preserveParams = ['table', 'searchTerm', 'search_levels', 'pointer'];
        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        foreach ($preserveParams as $param) {
            if (isset($queryParams[$param]) && $queryParams[$param] !== '') {
                $baseParams[$param] = $queryParams[$param];
            }
        }

        // Build URLs for mode switching
        try {
            // Manual mode URL
            $manualParams = $baseParams;
            $manualParams['sortingMode'][$tableName] = 'manual';
            // Don't set sort field for manual mode - only set direction
            $manualParams['sort'][$tableName]['direction'] = $currentDirection;
            $manualUrl = (string) $uriBuilder->buildUriFromRoute('records', $manualParams);

            // Field mode URL
            $fieldParams = $baseParams;
            $fieldParams['sortingMode'][$tableName] = 'field';
            $fieldUrl = (string) $uriBuilder->buildUriFromRoute('records', $fieldParams);

            // Ascending URL (for manual mode)
            $ascParams = $baseParams;
            $ascParams['sortingMode'][$tableName] = 'manual';
            $ascParams['sort'][$tableName]['direction'] = 'asc';
            $ascUrl = (string) $uriBuilder->buildUriFromRoute('records', $ascParams);

            // Descending URL (for manual mode)
            $descParams = $baseParams;
            $descParams['sortingMode'][$tableName] = 'manual';
            $descParams['sort'][$tableName]['direction'] = 'desc';
            $descUrl = (string) $uriBuilder->buildUriFromRoute('records', $descParams);
        } catch (Exception $e) {
            return '';
        }

        $isManualActive = ($currentMode === 'manual');
        $isFieldActive = ($currentMode === 'field');
        $isAscActive = $currentDirection === 'asc';
        $isDescActive = $currentDirection === 'desc';

        // Get the heading label
        $headingLabelT = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.label');
        $headingLabel = $headingLabelT !== '' ? $headingLabelT : 'Order';

        // Build HTML for the toggle with heading label
        $html = '<div class="gridview-sorting-wrapper me-2">';
        $html .= '<span class="gridview-sorting-label">' . htmlspecialchars($headingLabel) . '</span>';
        $html .= '<div class="gridview-sorting-controls d-flex align-items-center gap-2">';
        $html .= '<div class="gridview-sorting-toggle btn-group" role="group" aria-label="' . htmlspecialchars($headingLabel) . '">';

        // Manual sorting button with dropdown for asc/desc
        if ($isManualActive) {
            // When manual is active, show dropdown for direction
            $html .= '<div class="btn-group" role="group">';
            $html .= '<button type="button" class="btn btn-default btn-sm gridview-sorting-toggle__btn gridview-sorting-toggle__btn--active dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="' . htmlspecialchars($manualTitle) . '">';
            $html .= $iconFactory->getIcon('actions-sort-amount-' . ($isAscActive ? 'up' : 'down'), IconSize::SMALL)->render();
            $html .= ' <span>' . htmlspecialchars($manualLabel) . '</span>';
            $html .= '</button>';
            $html .= '<ul class="dropdown-menu">';
            $html .= '<li><a class="dropdown-item' . ($isAscActive ? ' active' : '') . '" href="' . htmlspecialchars($ascUrl) . '">' . $iconFactory->getIcon('actions-sort-amount-up', IconSize::SMALL)->render() . ' ' . htmlspecialchars($ascLabel) . '</a></li>';
            $html .= '<li><a class="dropdown-item' . ($isDescActive ? ' active' : '') . '" href="' . htmlspecialchars($descUrl) . '">' . $iconFactory->getIcon('actions-sort-amount-down', IconSize::SMALL)->render() . ' ' . htmlspecialchars($descLabel) . '</a></li>';
            $html .= '</ul>';
            $html .= '</div>';
        } else {
            // When not active, simple link button
            $html .= '<a href="' . htmlspecialchars($manualUrl) . '" class="btn btn-default btn-sm gridview-sorting-toggle__btn" title="' . htmlspecialchars($manualTitle) . '">';
            $html .= $iconFactory->getIcon('actions-move', IconSize::SMALL)->render();
            $html .= ' <span>' . htmlspecialchars($manualLabel) . '</span>';
            $html .= '</a>';
        }

        // Field sorting button - when active, show as dropdown with sorting options
        if ($isFieldActive && $sortingDropdownHtml !== '') {
            // When field mode is active, integrate the sorting dropdown into the button
            $html .= '<div class="btn-group" role="group">';
            $html .= $sortingDropdownHtml; // The dropdown already has proper button styling
            $html .= '</div>';
        } else {
            // When not active, simple link button to switch to field mode
            $html .= '<a href="' . htmlspecialchars($fieldUrl) . '" class="btn btn-default btn-sm gridview-sorting-toggle__btn" title="' . htmlspecialchars($fieldTitle) . '">';
            $html .= $iconFactory->getIcon('actions-filter', IconSize::SMALL)->render();
            $html .= ' <span>' . htmlspecialchars($fieldLabel) . '</span>';
            $html .= '</a>';
        }

        $html .= '</div>'; // Close toggle btn-group
        $html .= '</div>'; // Close controls
        $html .= '</div>'; // Close wrapper

        return $html;
    }

    /**
     * Render a sortable column header with dropdown like TYPO3's core list view.
     *
     * Creates a Bootstrap dropdown button with ascending/descending sort options,
     * using the same URL structure as the core list view for consistency.
     *
     * @param string $tableName The database table name
     * @param string $field The field name to sort by
     * @param string $label The column header label
     * @param string $currentSortField Currently active sort field
     * @param string $currentSortDirection Current sort direction (asc/desc)
     * @param int $pageId Current page ID
     * @param string $viewMode Current view mode (compact, grid, etc.)
     * @param ServerRequestInterface $request Current request
     * @return string Rendered HTML for the sortable column header
     */
    protected function renderSortableColumnHeader(
        string $tableName,
        string $field,
        string $label,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): string {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $lang = $this->getLanguageService();

        // Preserve query parameters
        $queryParams = $request->getQueryParams();
        $preserveParams = ['table', 'searchTerm', 'search_levels', 'pointer'];
        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        foreach ($preserveParams as $param) {
            if (isset($queryParams[$param]) && $queryParams[$param] !== '') {
                $baseParams[$param] = $queryParams[$param];
            }
        }

        $isActiveField = ($currentSortField === $field);
        $isAscActive = $isActiveField && $currentSortDirection !== 'desc';
        $isDescActive = $isActiveField && $currentSortDirection === 'desc';

        // Build sort URLs
        try {
            $ascParams = $baseParams;
            $ascParams['sort'][$tableName]['field'] = $field;
            $ascParams['sort'][$tableName]['direction'] = 'asc';
            $ascUrl = (string) $uriBuilder->buildUriFromRoute('records', $ascParams);

            $descParams = $baseParams;
            $descParams['sort'][$tableName]['field'] = $field;
            $descParams['sort'][$tableName]['direction'] = 'desc';
            $descUrl = (string) $uriBuilder->buildUriFromRoute('records', $descParams);
        } catch (Exception $e) {
            // If URL building fails, return plain label
            return htmlspecialchars($label);
        }

        // Get labels
        $ascLabelTranslated = $lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.sorting.asc');
        $ascLabel = $ascLabelTranslated !== '' ? $ascLabelTranslated : 'Ascending';
        $descLabelTranslated = $lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.sorting.desc');
        $descLabel = $descLabelTranslated !== '' ? $descLabelTranslated : 'Descending';

        // Build icon
        $icon = '';
        if ($isActiveField) {
            $iconIdentifier = $isDescActive ? 'actions-sort-amount-down' : 'actions-sort-amount-up';
            $icon = $iconFactory->getIcon($iconIdentifier, IconSize::SMALL)->render();
        } else {
            $icon = $iconFactory->getIcon('empty-empty', IconSize::SMALL)->render();
        }

        // Active dot icon
        $dotIcon = $iconFactory->getIcon('actions-dot', IconSize::SMALL)->render();

        // Build dropdown HTML matching TYPO3 core structure
        $html = '
            <div class="dropdown dropdown-static">
                <button
                    class="dropdown-toggle dropdown-toggle-link"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >
                    ' . htmlspecialchars($label) . '
                    <div class="' . ($isActiveField ? 'text-primary' : '') . '">' . $icon . '</div>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a class="dropdown-item" href="' . htmlspecialchars($ascUrl) . '" title="' . htmlspecialchars($ascLabel) . '">
                            <span class="dropdown-item-columns">
                                <span class="dropdown-item-column dropdown-item-column-icon text-primary">
                                    ' . ($isAscActive ? $dotIcon : '') . '
                                </span>
                                <span class="dropdown-item-column dropdown-item-column-title">
                                    ' . htmlspecialchars($ascLabel) . '
                                </span>
                            </span>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="' . htmlspecialchars($descUrl) . '" title="' . htmlspecialchars($descLabel) . '">
                            <span class="dropdown-item-columns">
                                <span class="dropdown-item-column dropdown-item-column-icon text-primary">
                                    ' . ($isDescActive ? $dotIcon : '') . '
                                </span>
                                <span class="dropdown-item-column dropdown-item-column-title">
                                    ' . htmlspecialchars($descLabel) . '
                                </span>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>
        ';

        return $html;
    }

    /**
     * Generate sortable column headers for compact view.
     *
     * @param string $tableName The database table name
     * @param array $displayColumns The columns to display
     * @param string $currentSortField Currently active sort field
     * @param string $currentSortDirection Current sort direction
     * @param int $pageId Current page ID
     * @param string $viewMode Current view mode
     * @param ServerRequestInterface $request Current request
     * @return array Array of column configs with rendered header HTML
     */
    /**
     * @param array<int, array{field: string, label: string, type: string, isLabelField: bool}> $displayColumns
     * @return array<int, array<string, mixed>>
     */
    protected function getSortableColumnHeaders(
        string $tableName,
        array $displayColumns,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): array {
        $headers = [];
        $tcaForTable = $this->getTcaForTable($tableName);
        $tcaColumns = $tcaForTable['columns'];
        $ctrl = $tcaForTable['ctrl'];
        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';

        // UID column (always first fixed column)
        $headers[] = [
            'field' => 'uid',
            'label' => 'UID',
            'headerHtml' => $this->renderSortableColumnHeader(
                $tableName,
                'uid',
                'UID',
                $currentSortField,
                $currentSortDirection,
                $pageId,
                $viewMode,
                $request,
            ),
            'isFixed' => true,
            'type' => 'uid',
        ];

        // Title/Label column (second fixed column)
        $labelLabel = $this->getFieldLabel($labelField, $tcaColumns, $ctrl);
        $headers[] = [
            'field' => $labelField,
            'label' => $labelLabel,
            'headerHtml' => $this->renderSortableColumnHeader(
                $tableName,
                $labelField,
                $labelLabel,
                $currentSortField,
                $currentSortDirection,
                $pageId,
                $viewMode,
                $request,
            ),
            'isFixed' => true,
            'type' => 'title',
        ];

        // Dynamic columns
        foreach ($displayColumns as $column) {
            if ($column['isLabelField'] ?? false) {
                continue; // Skip label field, already added
            }

            $field = $column['field'] ?? '';
            $label = $column['label'] ?? $field;

            if ($field === '') {
                continue;
            }

            $headers[] = [
                'field' => $field,
                'label' => $label,
                'headerHtml' => $this->renderSortableColumnHeader(
                    $tableName,
                    $field,
                    $label,
                    $currentSortField,
                    $currentSortDirection,
                    $pageId,
                    $viewMode,
                    $request,
                ),
                'isFixed' => false,
                'type' => $column['type'] ?? 'text',
            ];
        }

        return $headers;
    }

    /**
     * Get a human-readable label for a table.
     */
    protected function getTableLabel(string $tableName): string
    {
        $tcaForTable = $this->getTcaForTable($tableName);
        $labelTitleVal = $tcaForTable['ctrl']['title'] ?? $tableName;
        $label = is_string($labelTitleVal) ? $labelTitleVal : $tableName;

        if (str_starts_with($label, 'LLL:')) {
            $langService = $this->getLanguageService();
            $translated = $langService->sL($label);
            $label = $translated !== '' ? $translated : $tableName;
        }

        return $label;
    }

    /**
     * Get the icon identifier for a table.
     */
    protected function getTableIcon(string $tableName): string
    {
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $icon = $iconFactory->mapRecordTypeToIconIdentifier($tableName, []);
        return $icon !== '' ? $icon : 'mimetypes-x-content-text';
    }

    /**
     * Get the columns to display for a table.
     *
     * Uses user-selected columns from "Show columns" selector (list/displayFields),
     * falling back to TSconfig or TCA defaults if no columns are selected.
     *
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    protected function getDisplayColumns(string $tableName): array
    {
        $columns = [];
        $tcaForTable = $this->getTcaForTable($tableName);
        $ctrl = $tcaForTable['ctrl'];
        $tcaColumns = $tcaForTable['columns'];
        $backendUser = $this->getBackendUserAuthentication();

        // Get the label field (title) - always first
        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';

        // Priority 1: User's selected columns from "Show columns" selector
        $displayFields = $backendUser->getModuleData('list/displayFields');
        $displayFieldsArray = is_array($displayFields) ? $displayFields : [];
        $userSelectedFields = is_array($displayFieldsArray[$tableName] ?? null) ? $displayFieldsArray[$tableName] : [];

        if ($userSelectedFields !== []) {
            // User has selected specific columns
            $fieldList = $userSelectedFields;
        } else {
            // Priority 2: TSconfig showFields
            $modTableConfig = is_array($this->modTSconfig['table'] ?? null) ? $this->modTSconfig['table'] : [];
            $tableConfigArr = is_array($modTableConfig[$tableName] ?? null) ? $modTableConfig[$tableName] : [];
            $showFieldsVal = $tableConfigArr['showFields'] ?? '';
            $showFields = is_string($showFieldsVal) ? $showFieldsVal : '';

            if ($showFields !== '') {
                $fieldList = GeneralUtility::trimExplode(',', $showFields, true);
            } else {
                // Priority 3: TCA searchFields or label field as fallback
                $searchFieldsVal = $ctrl['searchFields'] ?? '';
                $searchFields = is_string($searchFieldsVal) ? $searchFieldsVal : '';
                if ($searchFields !== '') {
                    $fieldList = GeneralUtility::trimExplode(',', $searchFields, true);
                } else {
                    $fieldList = [$labelField];
                }
            }
        }

        // Always include label field first if not already included
        if (!in_array($labelField, $fieldList, true)) {
            array_unshift($fieldList, $labelField);
        }

        // Build column configuration
        foreach ($fieldList as $rawField) {
            $field = is_string($rawField) ? $rawField : '';
            if ($field === '') {
                continue;
            }
            // Skip internal fields
            $skipFields = [
                't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage',
                'l10n_parent', 'l10n_source', 'l10n_diffsource', 'l10n_state',
                'sys_language_uid',
            ];
            if (in_array($field, $skipFields, true)) {
                continue;
            }

            // Validate field exists in TCA or is a known system field
            $coreSystemFields = ['uid', 'pid'];
            $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
            $disabledField = $enableColumns['disabled'] ?? null;
            $sortbyField = $ctrl['sortby'] ?? null;
            $crdateField = $ctrl['crdate'] ?? null;
            $tstampField = $ctrl['tstamp'] ?? null;

            // List of valid fields for this table
            $validNonTcaFields = array_filter([
                ...$coreSystemFields,
                $disabledField,
                $sortbyField,
                $crdateField,
                $tstampField,
            ], static fn(mixed $v): bool => $v !== null && $v !== '');

            if (!isset($tcaColumns[$field]) && !in_array($field, $validNonTcaFields, true)) {
                continue;
            }

            // Get field label
            $label = $this->getFieldLabel($field, $tcaColumns, $ctrl);

            // Determine field type for formatting
            $type = $this->getFieldType($field, $tcaColumns, $ctrl);

            $columns[] = [
                'field' => $field,
                'label' => $label,
                'type' => $type,
                'isLabelField' => ($field === $labelField),
            ];
        }

        return $columns;
    }

    /**
     * Get the label for a field.
     *
     * Handles both traditional LLL: format and TYPO3 v12+ translation domain format
     * (e.g., 'frontend.db.tt_content:header').
     *
     * @param array<string, mixed> $tcaColumns
     * @param array<string, mixed> $ctrl
     */
    protected function getFieldLabel(string $field, array $tcaColumns, array $ctrl): string
    {
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        if (isset($fieldDef['label'])) {
            $labelRawVal = $fieldDef['label'];
            $label = is_string($labelRawVal) ? $labelRawVal : '';
            return $this->translateTcaLabel($label, $field);
        }

        // System field labels
        $systemLabels = [
            'uid' => 'UID',
            'pid' => 'Page',
        ];

        if (isset($systemLabels[$field])) {
            return $systemLabels[$field];
        }

        $langService = $this->getLanguageService();

        // Check if field matches ctrl fields
        if ($field === ($ctrl['crdate'] ?? null) || $field === 'crdate') {
            $translated = $langService->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.creationDate');
            return $translated !== '' ? $translated : 'Created';
        }
        if ($field === ($ctrl['tstamp'] ?? null) || $field === 'tstamp') {
            $translated = $langService->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.timestamp');
            return $translated !== '' ? $translated : 'Modified';
        }
        if ($field === ($ctrl['sortby'] ?? null)) {
            $translated = $langService->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.sorting');
            return $translated !== '' ? $translated : 'Sorting';
        }

        // Hidden/disabled field
        $enableCols = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        $disabledField = $enableCols['disabled'] ?? null;
        if ($field === $disabledField) {
            $translated = $langService->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden');
            return $translated !== '' ? $translated : 'Hidden';
        }

        return $field;
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
    protected function translateTcaLabel(string $label, string $fallback = ''): string
    {
        // Empty label - return fallback
        if ($label === '') {
            return $fallback !== '' ? $fallback : $label;
        }

        // Traditional LLL: format or TYPO3 v12+ translation domain format
        // The LanguageService::sL() method handles both:
        // - LLL:EXT:core/Resources/Private/Language/locallang.xlf:key
        // - domain.name:key (e.g., frontend.db.tt_content:header)
        if (str_starts_with($label, 'LLL:') || str_contains($label, ':')) {
            $langService = $this->getLanguageService();
            $translated = $langService->sL($label);
            return $translated !== '' ? $translated : ($fallback !== '' ? $fallback : $label);
        }

        // Plain string - return as-is
        return $label;
    }

    /**
     * Get the type for a field (for formatting).
     */
    /**
     * @param array<string, mixed> $tcaColumns
     * @param array<string, mixed> $ctrl
     */
    protected function getFieldType(string $field, array $tcaColumns, array $ctrl): string
    {
        // System date fields
        if (in_array($field, ['crdate', 'tstamp'], true)) {
            return 'datetime';
        }

        // Disabled/hidden field
        $enableCols = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        $disabledField = $enableCols['disabled'] ?? null;
        if ($field === $disabledField) {
            return 'boolean';
        }

        // TCA-configured type
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        $config = is_array($fieldDef['config'] ?? null) ? $fieldDef['config'] : [];
        $typeVal = $config['type'] ?? '';
        $type = is_string($typeVal) ? $typeVal : '';

        return match ($type) {
            'check' => 'boolean',
            'datetime' => 'datetime',
            'number' => 'number',
            'select', 'radio' => 'select',
            'inline', 'file' => 'relation',
            default => 'text',
        };
    }

    /**
     * Enrich records with formatted display values for all columns.
     *
     * This pre-formats values in PHP so Fluid doesn't need dynamic variable access.
     *
     * @param array $records The records to enrich
     * @param array $displayColumns The columns to display
     * @param string $tableName The table name
     * @return array Enriched records with 'displayValues' array
     */
    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array{field: string, label: string, type: string, isLabelField: bool}> $displayColumns
     * @return array<int, array<string, mixed>>
     */
    protected function enrichRecordsWithDisplayValues(array $records, array $displayColumns, string $tableName): array
    {
        $tcaForTable = $this->getTcaForTable($tableName);
        $tcaColumns = $tcaForTable['columns'];

        foreach ($records as &$record) {
            $displayValues = [];
            /** @var array<string, mixed> $rawRecord */
            $rawRecord = is_array($record['rawRecord'] ?? null) ? $record['rawRecord'] : [];

            foreach ($displayColumns as $column) {
                $field = $column['field'];
                $type = $column['type'];
                $rawValue = $rawRecord[$field] ?? null;

                // For boolean/check fields, invert display value when TCA has invertStateDisplay
                // (e.g., hidden field labeled "Enabled": hidden=0 should display as Yes/true)
                $displayRaw = $rawValue;
                if ($type === 'boolean' && $this->shouldInvertBooleanDisplay($field, $tcaColumns)) {
                    $displayRaw = ((bool) $rawValue) ? 0 : 1;
                }

                $displayValues[$field] = [
                    'field' => $field,
                    'label' => $column['label'],
                    'type' => $type,
                    'isLabelField' => $column['isLabelField'] ?? false,
                    'raw' => $displayRaw,
                    'formatted' => $this->formatFieldValue($displayRaw, $type, $field, $tcaColumns, $tableName),
                    'isEmpty' => $rawValue === null || $rawValue === '' || $rawValue === 0 || $rawValue === '0',
                ];
            }

            $record['displayValues'] = $displayValues;
        }

        return $records;
    }

    /**
     * Enrich records with language information (flag identifier, language title).
     *
     * Resolves the sys_language_uid from each record's raw data to the corresponding
     * site language flag identifier, so the card can display the language flag.
     *
     * @param array<int, array<string, mixed>> $records The enriched records
     * @param string $tableName The table name
     * @return array<int, array<string, mixed>> Records with language info added
     */
    protected function enrichRecordsWithLanguageInfo(array $records, string $tableName): array
    {
        $tcaForTable = $this->getTcaForTable($tableName);
        $languageField = $tcaForTable['ctrl']['languageField'] ?? null;

        // If no language field is defined for this table, skip
        if (!is_string($languageField) || $languageField === '') {
            return $records;
        }

        // Get available site languages
        $backendUser = $this->getBackendUserAuthentication();
        $siteLanguages = $this->pageContext->site->getAvailableLanguages($backendUser, false, $this->pageContext->pageId);

        foreach ($records as &$record) {
            /** @var array<string, mixed> $rawRecord */
            $rawRecord = is_array($record['rawRecord'] ?? null) ? $record['rawRecord'] : [];
            $langUidRaw = $rawRecord[$languageField] ?? 0;
            $langUid = is_numeric($langUidRaw) ? (int) $langUidRaw : 0;

            $record['sysLanguageUid'] = $langUid;
            $record['languageFlagIdentifier'] = '';
            $record['languageTitle'] = '';

            // Resolve flag identifier from site languages
            foreach ($siteLanguages as $siteLanguage) {
                if ($siteLanguage->getLanguageId() === $langUid) {
                    $record['languageFlagIdentifier'] = $siteLanguage->getFlagIdentifier();
                    $record['languageTitle'] = $siteLanguage->getTitle();
                    break;
                }
            }
        }

        return $records;
    }

    /**
     * Format a field value for display.
     *
     * @param mixed $value The raw value
     * @param string $type The field type
     * @param string $field The field name
     * @param array $tcaColumns TCA columns configuration
     * @param string $tableName The table name
     * @return string Formatted value for display
     */
    /**
     * @param array<string, mixed> $tcaColumns
     */
    protected function formatFieldValue(mixed $value, string $type, string $field, array $tcaColumns, string $tableName): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        switch ($type) {
            case 'boolean':
                return (bool) $value ? 'yes' : 'no';

            case 'datetime':
                if (is_numeric($value) && $value > 0) {
                    return date('d.m.Y H:i', (int) $value);
                }
                return is_scalar($value) ? (string) $value : '';

            case 'number':
                return is_scalar($value) ? (string) $value : '';

            case 'select':
                // Try to resolve select value to label
                $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
                $config = is_array($fieldDef['config'] ?? null) ? $fieldDef['config'] : [];
                $items = is_array($config['items'] ?? null) ? $config['items'] : [];
                $valueStr = is_scalar($value) ? (string) $value : '';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    // TYPO3 v12+ item format: ['label' => ..., 'value' => ...]
                    $itemValue = $item['value'] ?? $item[1] ?? null;
                    $itemLabelVal = $item['label'] ?? $item[0] ?? '';
                    $itemLabel = is_string($itemLabelVal) ? $itemLabelVal : (is_scalar($itemLabelVal) ? (string) $itemLabelVal : '');
                    $itemValueStr = is_scalar($itemValue) ? (string) $itemValue : '';
                    if ($itemValueStr === $valueStr) {
                        if (str_starts_with($itemLabel, 'LLL:')) {
                            $langService = $this->getLanguageService();
                            $translated = $langService->sL($itemLabel);
                            return $translated !== '' ? $translated : $valueStr;
                        }
                        return $itemLabel;
                    }
                }
                return $valueStr;

            case 'relation':
                // For relations, just show count or basic info
                if (is_numeric($value)) {
                    return $value > 0 ? $value . ' item(s)' : '';
                }
                return is_scalar($value) ? (string) $value : '';

            default:
                // Text - strip HTML and limit length
                $textInput = is_scalar($value) ? (string) $value : '';
                $text = strip_tags(html_entity_decode($textInput));
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;
                $text = trim($text);
                return $text;
        }
    }

    /**
     * Check if a boolean/check field should invert its display value.
     *
     * Respects TCA `invertStateDisplay` at both the config level and per-item level.
     * This is commonly used for the `hidden` field, where the label says "Enabled"
     * but the DB value 1 means hidden (disabled), so the display must be inverted.
     *
     * @param string $field The field name
     * @param array<string, mixed> $tcaColumns TCA columns configuration
     * @return bool True if the display value should be inverted
     */
    protected function shouldInvertBooleanDisplay(string $field, array $tcaColumns): bool
    {
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        $config = is_array($fieldDef['config'] ?? null) ? $fieldDef['config'] : [];

        // Check top-level invertStateDisplay (config.invertStateDisplay)
        if (isset($config['invertStateDisplay']) && (bool) $config['invertStateDisplay']) {
            return true;
        }

        // Check per-item invertStateDisplay (config.items[n].invertStateDisplay)
        $items = is_array($config['items'] ?? null) ? $config['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['invertStateDisplay']) && (bool) $item['invertStateDisplay']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the number of items per page for a view mode.
     *
     * Resolution order:
     * 1. Per-type TSconfig: mod.web_list.viewMode.types.<type>.itemsPerPage
     * 2. Global TSconfig: mod.web_list.viewMode.itemsPerPage
     * 3. Built-in default: 100 (300 for compact mode)
     *
     * @param string $viewMode The view mode identifier
     * @param int $pageId The page ID for TSconfig resolution
     * @return int Number of items per page (0 = no pagination)
     */
    protected function getItemsPerPage(string $viewMode, int $pageId): int
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);

        // 1. Per-type TSconfig
        $perType = $tsConfig['mod.']['web_list.']['viewMode.']['types.'][$viewMode . '.']['itemsPerPage'] ?? null;
        if ($perType !== null && is_numeric($perType)) {
            return max(0, (int) $perType);
        }

        // 2. Global TSconfig
        $global = $tsConfig['mod.']['web_list.']['viewMode.']['itemsPerPage'] ?? null;
        if ($global !== null && is_numeric($global)) {
            return max(0, (int) $global);
        }

        // 3. Built-in defaults
        return match ($viewMode) {
            'compact' => 300,
            default => 100,
        };
    }

    /**
     * Get the maximum number of records shown per table in multi-table mode.
     *
     * In multi-table mode (no specific table selected), each table shows
     * at most this many records with an "Expand table" button for more.
     * This matches TYPO3 Core's itemsLimitPerTable behavior.
     *
     * @param int $pageId The page ID for TSconfig resolution
     * @return int Number of records to show per table (default: 20)
     */
    protected function getItemsLimitPerTable(int $pageId): int
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);

        // Check extension-specific setting first
        $extLimit = $tsConfig['mod.']['web_list.']['viewMode.']['itemsLimitPerTable'] ?? null;
        if ($extLimit !== null && is_numeric($extLimit)) {
            return max(1, (int) $extLimit);
        }

        // Fall back to TYPO3 Core's itemsLimitPerTable
        $coreLimit = $tsConfig['mod.']['web_list.']['itemsLimitPerTable'] ?? null;
        if ($coreLimit !== null && is_numeric($coreLimit)) {
            return max(1, (int) $coreLimit);
        }

        return 20;
    }

    /**
     * Build pagination objects for a table using TYPO3's Core Pagination API.
     *
     * Uses DatabasePaginator (extending AbstractPaginator) with SlidingWindowPagination
     * for consistent pagination handling across all view modes. This follows the same
     * pattern used in TYPO3 Core (e.g. LiveSearch) rather than custom array logic.
     *
     * The returned array contains the paginator and pagination objects for use in Fluid,
     * plus a pre-built currentUrl for page navigation links (matching the Core ListNavigation pattern).
     *
     * @param array<int, array<string, mixed>> $records The already-fetched records for the current page
     * @param int $totalRecords Total number of records across all pages
     * @param int $currentPage Current page number (1-based, like TYPO3 Core)
     * @param int $itemsPerPage Number of items per page
     * @param string $tableName The table name (for URL building)
     * @param int $pageId The TYPO3 page ID
     * @param string $viewMode The current view mode
     * @param ServerRequestInterface $request The current request
     * @return array{paginator: DatabasePaginator, pagination: SlidingWindowPagination, currentUrl: string}
     */
    protected function buildPagination(
        array $records,
        int $totalRecords,
        int $currentPage,
        int $itemsPerPage,
        string $tableName,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): array {
        $paginator = new DatabasePaginator($records, $totalRecords, $currentPage, $itemsPerPage);
        $pagination = new SlidingWindowPagination($paginator, 15);

        // Build the currentUrl for page navigation (same pattern as Core ListNavigation).
        // The Fluid template appends &pointer[<table>]=<pageNumber> to this URL.
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $queryParams = $request->getQueryParams();

        // Always include table in pagination URLs so clicking any pagination
        // link switches to single-table mode (matches TYPO3 Core behavior).
        $urlParams = ['id' => $pageId, 'displayMode' => $viewMode, 'table' => $tableName];
        $preserveParams = ['searchTerm', 'search_levels'];
        foreach ($preserveParams as $param) {
            if (isset($queryParams[$param]) && $queryParams[$param] !== '') {
                $urlParams[$param] = $queryParams[$param];
            }
        }
        // Preserve sort parameters
        if (isset($queryParams['sort'])) {
            $urlParams['sort'] = $queryParams['sort'];
        }
        if (isset($queryParams['sortingMode'])) {
            $urlParams['sortingMode'] = $queryParams['sortingMode'];
        }

        $currentUrl = '';
        try {
            $currentUrl = (string) $uriBuilder->buildUriFromRoute('records', $urlParams);
        } catch (Exception $e) {
            // Ignore
        }

        return [
            'paginator' => $paginator,
            'pagination' => $pagination,
            'currentUrl' => $currentUrl,
        ];
    }

    /**
     * Get the current pagination pointer for a specific table from the request.
     *
     * Uses 1-based page numbers like TYPO3 Core's DatabaseRecordList.
     * The pointer is passed as pointer[<tableName>]=<pageNumber>.
     *
     * @param ServerRequestInterface $request The current request
     * @param string $tableName The table name
     * @return int The current page number (1-based)
     */
    protected function getCurrentPointer(ServerRequestInterface $request, string $tableName): int
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $parsedBodyArray = is_array($parsedBody) ? $parsedBody : [];

        $pointer = $queryParams['pointer'] ?? $parsedBodyArray['pointer'] ?? [];
        if (is_array($pointer) && isset($pointer[$tableName])) {
            $value = $pointer[$tableName];
            return is_numeric($value) ? max(1, (int) $value) : 1;
        }

        return 1;
    }

    /**
     * Get TCA configuration for a table with proper type assertions.
     *
     * @return array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>}
     */
    private function getTcaForTable(string $tableName): array
    {
        /** @var array<string, mixed> $allTca */
        $allTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $tca = $allTca[$tableName] ?? [];
        if (!is_array($tca)) {
            return ['ctrl' => [], 'columns' => []];
        }
        /** @var array<string, mixed> $ctrl */
        $ctrl = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        /** @var array<string, array<string, mixed>> $columns */
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        return ['ctrl' => $ctrl, 'columns' => $columns];
    }
}
