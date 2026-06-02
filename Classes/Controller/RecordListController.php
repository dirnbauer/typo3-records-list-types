<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller;

use Doctrine\DBAL\ParameterType;
use Exception;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Backend\Controller\RecordListController as CoreRecordListController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\RecordSearchBoxComponent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\RecordsListTypes\Pagination\DatabasePaginator;
use Webconsulting\RecordsListTypes\Service\GridConfigurationService;
use Webconsulting\RecordsListTypes\Service\MiddlewareDiagnosticService;
use Webconsulting\RecordsListTypes\Service\RecordDisplayColumnResolver;
use Webconsulting\RecordsListTypes\Service\RecordFilterQueryService;
use Webconsulting\RecordsListTypes\Service\RecordFilterStateService;
use Webconsulting\RecordsListTypes\Service\RecordFilterViewDataFactory;
use Webconsulting\RecordsListTypes\Service\RecordGridDataProvider;
use Webconsulting\RecordsListTypes\Service\RecordListRequestParameterService;
use Webconsulting\RecordsListTypes\Service\RecordSortingService;
use Webconsulting\RecordsListTypes\Service\RecordTranslationGroupingService;
use Webconsulting\RecordsListTypes\Service\RecordViewEnrichmentContext;
use Webconsulting\RecordsListTypes\Service\RecordViewEnrichmentService;
use Webconsulting\RecordsListTypes\Service\TcaTableConfigurationService;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;
use Webconsulting\RecordsListTypes\Service\ViewTypeRegistry;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

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

    private ?RecordSortingService $recordSortingService = null;

    private ?RecordListRequestParameterService $requestParameterService = null;

    /** Whether the clipboard is enabled for this request. */
    private bool $clipboardEnabled = false;

    /**
     * Active request captured during mainAction() so that
     * parent-overridden hooks (renderPageTranslations) can access it.
     */
    private ?ServerRequestInterface $currentRequest = null;

    /** Active non-list view mode used when building return URLs for actions. */
    private string $currentViewMode = 'list';

    /**
     * Get the ViewTypeRegistry from the DI container.
     * XClasses can't use constructor injection for additional dependencies,
     * so we fetch it from the container on demand.
     */
    private function getViewTypeRegistry(): ViewTypeRegistry
    {
        if (!$this->viewTypeRegistry instanceof ViewTypeRegistry) {
            $registry = GeneralUtility::getContainer()->get(ViewTypeRegistry::class);
            if (!$registry instanceof ViewTypeRegistry) {
                throw new RuntimeException('ViewTypeRegistry not available from container', 1735600200);
            }
            $this->viewTypeRegistry = $registry;
        }
        return $this->viewTypeRegistry;
    }

    private function getRecordSortingService(): RecordSortingService
    {
        if (!$this->recordSortingService instanceof RecordSortingService) {
            $this->recordSortingService = GeneralUtility::makeInstance(RecordSortingService::class);
        }
        return $this->recordSortingService;
    }

    private function getRequestParameterService(): RecordListRequestParameterService
    {
        if (!$this->requestParameterService instanceof RecordListRequestParameterService) {
            $this->requestParameterService = GeneralUtility::makeInstance(RecordListRequestParameterService::class);
        }
        return $this->requestParameterService;
    }

    private function getTcaConfigurationService(): TcaTableConfigurationService
    {
        return GeneralUtility::makeInstance(TcaTableConfigurationService::class);
    }

    private function getDisplayColumnResolver(): RecordDisplayColumnResolver
    {
        return GeneralUtility::makeInstance(RecordDisplayColumnResolver::class);
    }

    private function getViewEnrichmentService(): RecordViewEnrichmentService
    {
        return GeneralUtility::makeInstance(RecordViewEnrichmentService::class);
    }

    private function getTranslationGroupingService(): RecordTranslationGroupingService
    {
        return GeneralUtility::makeInstance(RecordTranslationGroupingService::class);
    }

    private function createViewEnrichmentContext(): RecordViewEnrichmentContext
    {
        return new RecordViewEnrichmentContext(
            $this->pageContext,
            $this->currentViewMode,
            $this->currentRequest,
        );
    }

    /**
     * @return array<array<mixed>>
     */
    private function getNestedModTsConfig(): array
    {
        $nested = [];
        foreach ($this->modTSconfig as $key => $value) {
            if (is_array($value)) {
                $nested[$key] = $value;
            }
        }

        return $nested;
    }

    /**
     * @return array<array<string>>
     */
    private function getTableTsConfigOverTca(): array
    {
        $tableConfig = $this->modTSconfig['table'] ?? [];
        if (!is_array($tableConfig)) {
            return [];
        }

        $normalized = [];
        foreach ($tableConfig as $table => $config) {
            if (!is_string($table) || !is_array($config)) {
                continue;
            }
            $normalized[$table] = [];
            foreach ($config as $key => $value) {
                if (is_scalar($value)) {
                    $normalized[$table][(string) $key] = (string) $value;
                }
            }
        }

        return $normalized;
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
    #[Override]
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->currentRequest = $request;
        // Get view mode resolver
        $viewModeResolver = GeneralUtility::makeInstance(ViewModeResolver::class);

        $requestParams = ArrayUtility::mergedRequestParameters($request);
        $pageId = ArrayUtility::intValue($requestParams['id'] ?? null);
        $requestedTable = ArrayUtility::stringValue($requestParams['table'] ?? null);

        // Get the active view mode
        $viewMode = $viewModeResolver->getActiveViewMode($request, $pageId, $requestedTable);
        $this->currentViewMode = $viewMode;

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
        if (!$pageContextAttr instanceof PageContext
            || !$moduleDataAttr instanceof ModuleData) {
            return parent::mainAction($request);
        }
        $this->pageContext = $pageContextAttr;
        $this->moduleData = $moduleDataAttr;

        $languageService = $this->getLanguageService();
        $backendUser = $this->getBackendUserAuthentication();

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/dispatch-modal-button.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/contextual-record-edit-trigger.js');

        BackendUtility::lockRecords();
        $pointer = max(0, ArrayUtility::intValue($requestParams['pointer'] ?? null));
        $this->table = ArrayUtility::stringValue($requestParams['table'] ?? null);
        $this->searchTerm = trim(ArrayUtility::stringValue($requestParams['searchTerm'] ?? null));
        $this->returnUrl = GeneralUtility::sanitizeLocalUrl(
            ArrayUtility::stringValue($requestParams['returnUrl'] ?? null),
        );
        $cmd = ArrayUtility::stringValue($requestParams['cmd'] ?? null);

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
        $searchLevels = ArrayUtility::intValue($requestParams['search_levels'] ?? null, $searchLevelDefault);

        // Create DatabaseRecordList (needed for URL building and other parent methods)
        $dbList = GeneralUtility::makeInstance(DatabaseRecordList::class);
        $dbList->setRequest($request);
        $dbList->setModuleData($this->moduleData);
        $dbList->calcPerms = $this->pageContext->pagePermissions;
        $dbList->returnUrl = $this->returnUrl;
        $dbList->showClipboardActions = true;
        $dbList->disableSingleTableView = (bool) ($this->modTSconfig['disableSingleTableView'] ?? false);
        $dbList->listOnlyInSingleTableMode = (bool) ($this->modTSconfig['listOnlyInSingleTableView'] ?? false);
        $dbList->hideTables = ArrayUtility::stringValue($this->modTSconfig['hideTables'] ?? null);
        $dbList->hideTranslations = ArrayUtility::stringValue($this->modTSconfig['hideTranslations'] ?? null);
        $dbList->tableTSconfigOverTCA = $this->getTableTsConfigOverTca();
        $dbList->allowedNewTables = ArrayUtility::commaSeparatedList($this->modTSconfig['allowedNewTables'] ?? null);
        $dbList->deniedNewTables = ArrayUtility::commaSeparatedList($this->modTSconfig['deniedNewTables'] ?? null);
        /** @var array<string> $pageRecord */
        $pageRecord = $this->pageContext->pageRecord ?? [];
        $dbList->pageRow = $pageRecord;
        $dbList->modTSconfig = $this->getNestedModTsConfig();
        $dbList->setLanguagesAllowedForUser($siteLanguages);
        $clickTitleMode = trim(ArrayUtility::stringValue($this->modTSconfig['clickTitleMode'] ?? null));
        $dbList->clickTitleMode = $clickTitleMode === '' ? 'edit' : $clickTitleMode;
        $tableDisplayOrder = $this->modTSconfig['tableDisplayOrder'] ?? null;
        if (is_array($tableDisplayOrder)) {
            $dbList->setTableDisplayOrder($tableDisplayOrder);
        }

        // Initialize clipboard
        $clipboard = $this->initializeClipboard($request, (bool) $this->moduleData->get('clipBoard'));
        $dbList->clipObj = $clipboard;

        // Store clipboard state for renderViewContent()
        $this->clipboardEnabled = (bool) $this->moduleData->get('clipBoard');

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
        $viewMode = $viewModeResolver->getActiveViewMode($request, $pageId, $this->table);
        $this->currentViewMode = $viewMode;

        // Initialize dbList for URL building, clipboard functionality, and search queries
        $dbList->start($this->pageContext->pageId, $this->table, $pointer, $this->searchTerm, $searchLevels);

        // Render the appropriate view (all view types use the same render path)
        $customContent = $this->renderViewContent($request, $dbList, $pageId, $this->table, $this->searchTerm, $searchLevels, $viewMode);

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
        if ($this->allowSearch && $this->moduleData instanceof ModuleData && (bool) $this->moduleData->get('searchBox')) {
            $searchBoxHtml = $this->renderSearchBox($request, $dbList, $this->searchTerm, $searchLevels);
        }

        // Clipboard
        $clipboardHtml = '';
        if ($this->moduleData instanceof ModuleData && (bool) $this->moduleData->get('clipBoard') && ($customContent !== '' || $clipboard->hasElements())) {
            $clipboardHtml = '<hr class="spacer"><typo3-backend-clipboard-panel return-url="' . htmlspecialchars((string) $dbList->listURL()) . '"></typo3-backend-clipboard-panel>';
        }

        // Set page title
        $view->setTitle(
            (string) ($languageService->translate('title', 'backend.modules.list') ?? ''),
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
    #[Override]
    protected function renderSearchBox(
        ServerRequestInterface $request,
        DatabaseRecordList $dbList,
        string $searchWord,
        int $searchLevels,
    ): string {
        // Get the current view mode
        $viewModeResolver = GeneralUtility::makeInstance(ViewModeResolver::class);
        $viewMode = $viewModeResolver->getActiveViewMode($request, $this->pageContext->pageId, $this->table);

        // Build the search URL using the 'records' route (not web_list)
        // This is critical - dbList->listURL() returns a web_list URL which bypasses our controller
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        $searchParams = [
            'id' => $this->pageContext->pageId,
            'displayMode' => $viewMode,
        ];

        $requestParams = ArrayUtility::mergedRequestParameters($request);

        // Preserve table filter if set
        if ($this->table !== '') {
            $searchParams['table'] = $this->table;
        }
        foreach (['filters', 'recordFilters', 'sort', 'sortingMode'] as $param) {
            if (isset($requestParams[$param]) && $requestParams[$param] !== '') {
                $searchParams[$param] = $requestParams[$param];
            }
        }

        try {
            $baseUrl = (string) $uriBuilder->buildUriFromRoute('records', $searchParams);
        } catch (Exception) {
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

        return GeneralUtility::makeInstance(RecordSearchBoxComponent::class)
            ->setAllowedSearchLevels($searchLevelItems)
            ->setSearchWord($searchWord)
            ->setSearchLevel($searchLevels)
            ->render($request, $baseUrl);
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
    private function renderViewContent(
        ServerRequestInterface $request,
        DatabaseRecordList $dbList,
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
        $recordFilterQueryService = GeneralUtility::makeInstance(RecordFilterQueryService::class);
        $recordFilterStateService = GeneralUtility::makeInstance(RecordFilterStateService::class);
        $recordFilterViewDataFactory = GeneralUtility::makeInstance(RecordFilterViewDataFactory::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        // Parse request parameters once at the action boundary.
        $requestParams = ArrayUtility::mergedRequestParameters($request);
        $sortParams = (array) ($requestParams['sort'] ?? []);
        $sortingModeParams = (array) ($requestParams['sortingMode'] ?? []);

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
            $filterViewData = $recordFilterViewDataFactory->createForTable($tableName, $pageId, $viewMode, $request);

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
            $hasActiveFilters = $recordFilterStateService->hasActiveValuesForTable($request, $tableName);
            $totalRecordCount = $this->getRecordCountUsingDbList($tableName, $pageId, '', 0, $request, $recordFilterQueryService);

            // Pagination and record fetching strategy depends on mode + search state
            if ($searchTerm !== '') {
                // ---- SEARCH MODE ----
                // Get total count of matching records for this table
                $searchTotalCount = $this->getSearchRecordCount($tableName, $pageId, $searchTerm, $searchLevels, $request, $recordFilterQueryService);

                if ($isSingleTableMode) {
                    // Single-table search: full pagination support
                    $itemsPerPage = $this->getItemsPerPage($viewMode, $pageId);
                    $currentPointer = $this->getRequestParameterService()->getCurrentPointer($request, $tableName);
                    $offset = ($currentPointer - 1) * $itemsPerPage;
                    $records = $this->getRecordsUsingDbList(
                        $request,
                        $tableName,
                        $pageId,
                        $searchTerm,
                        $searchLevels,
                        $itemsPerPage,
                        $offset,
                        $sortField,
                        $sortDirection,
                        $recordGridDataProvider,
                        $recordFilterQueryService,
                    );
                    $recordCount = $searchTotalCount;
                    $hasMore = false; // pagination handles navigation
                } else {
                    // Multi-table search: show limited results with "Expand table"
                    $itemsPerPage = $this->getItemsLimitPerTable($pageId);
                    $currentPointer = 1;
                    $offset = 0;
                    $records = $this->getRecordsUsingDbList(
                        $request,
                        $tableName,
                        $pageId,
                        $searchTerm,
                        $searchLevels,
                        $itemsPerPage,
                        0,
                        $sortField,
                        $sortDirection,
                        $recordGridDataProvider,
                        $recordFilterQueryService,
                    );
                    $recordCount = $searchTotalCount;
                    $hasMore = $searchTotalCount > count($records);
                }
            } elseif ($isSingleTableMode) {
                // ---- SINGLE TABLE, NO SEARCH ----
                $itemsPerPage = $this->getItemsPerPage($viewMode, $pageId);
                $currentPointer = $this->getRequestParameterService()->getCurrentPointer($request, $tableName);
                $offset = ($currentPointer - 1) * $itemsPerPage;
                $records = $this->getRecordsUsingDbList(
                    $request,
                    $tableName,
                    $pageId,
                    $searchTerm,
                    $searchLevels,
                    $itemsPerPage,
                    $offset,
                    $sortField,
                    $sortDirection,
                    $recordGridDataProvider,
                    $recordFilterQueryService,
                );
                $recordCount = $totalRecordCount;
                $hasMore = false; // pagination handles navigation
            } else {
                // ---- MULTI TABLE, NO SEARCH ----
                $itemsPerPage = $this->getItemsLimitPerTable($pageId);
                $currentPointer = 1;
                $offset = 0;
                $records = $this->getRecordsUsingDbList(
                    $request,
                    $tableName,
                    $pageId,
                    '',
                    0,
                    $itemsPerPage,
                    $offset,
                    $sortField,
                    $sortDirection,
                    $recordGridDataProvider,
                    $recordFilterQueryService,
                );
                $recordCount = $totalRecordCount;
                $hasMore = $recordCount > count($records);
            }

            if ($records === [] && !$isSingleTableMode) {
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
                $dbList,
                $tableName,
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
                $singleTableUrlParams = array_replace($singleTableUrlParams, $this->getRequestParameterService()->getPreservedListParameters($request));
                $singleTableUrl = (string) $uriBuilder->buildUriFromRoute('records', $singleTableUrlParams);
                $clearTableUrlParams = [
                    'id' => $pageId,
                    'displayMode' => $viewMode,
                ];
                $clearTableUrlParams = array_replace($clearTableUrlParams, $this->getRequestParameterService()->getPreservedListParameters($request));
                unset($clearTableUrlParams['table']);
                $clearTableUrl = (string) $uriBuilder->buildUriFromRoute('records', $clearTableUrlParams);
            } catch (Exception) {
            }

            // Display columns (from ViewTypeRegistry config)
            $columnsArray = is_array($columnsConfig['columns'] ?? null) ? $columnsConfig['columns'] : [];
            $columnResolver = $this->getDisplayColumnResolver();
            if ((bool) ($columnsConfig['fromTCA'] ?? false)) {
                $displayColumns = $columnResolver->getDisplayColumns($tableName, $this->modTSconfig);
            } elseif ($columnsArray !== []) {
                $displayColumns = $columnResolver->getSpecificDisplayColumns($tableName, $columnsArray);
            } else {
                $displayColumns = $columnResolver->getTeaserDisplayColumns($tableName);
            }

            $enrichmentContext = $this->createViewEnrichmentContext();
            $enrichedRecords = $this->getViewEnrichmentService()->enrichForAlternativeViews(
                $records,
                $displayColumns,
                $tableName,
                $enrichmentContext,
            );

            // Separate connected translations from default-language / free-mode records
            $enrichedRecords = $this->getTranslationGroupingService()->groupTranslationsOnRecords(
                $enrichedRecords,
                $tableName,
                $pageId,
                $recordGridDataProvider,
                $enrichmentContext,
            );

            // Assign a per-GROUP zebra class here so every row a template
            // renders (parent + all its translation slots) ends up with the
            // same `groupClass`. Doing it in PHP removes Fluid's inline
            // expression / boolean edge cases that were silently dropping
            // the class client-side.
            $groupIndex = 0;
            foreach ($enrichedRecords as &$recordRef) {
                $groupIndex++;
                $groupClass = ($groupIndex % 2 === 0)
                    ? 'compactview-row--group-even'
                    : 'compactview-row--group-odd';
                $recordRef['groupClass'] = $groupClass;
                if (is_array($recordRef['translations'] ?? null)) {
                    /** @var array<int, array<string, mixed>> $translations */
                    $translations = $recordRef['translations'];
                    foreach ($translations as &$translationRef) {
                        $translationRef['groupClass'] = $groupClass;
                    }
                    unset($translationRef);
                    $recordRef['translations'] = $translations;
                }
            }
            unset($recordRef);

            // Sorting dropdown / toggle data
            $sortableFields = $recordGridDataProvider->getSortableFields($tableName);
            $sortingDropdown = $this->buildSortingDropdown(
                $tableName,
                $sortableFields,
                $sortField,
                $sortDirection,
                $pageId,
                $viewMode,
                $request,
            );

            // Sorting mode toggle (used by GridView template for manual/field switch)
            $sortingModeToggle = null;
            if ($hasSortbyField) {
                $sortingModeToggle = $this->buildSortingModeToggle(
                    $tableName,
                    $sortingMode,
                    $sortDirection,
                    $pageId,
                    $viewMode,
                    $request,
                );
            }

            // Sortable column headers (used by CompactView template). In
            // single-table mode we also enable native "Edit this column"
            // multi-edit entries inside each header dropdown.
            $sortableColumnHeaders = $this->getSortableColumnHeaders(
                $tableName,
                $displayColumns,
                $sortField,
                $sortDirection,
                $pageId,
                $viewMode,
                $request,
                $isSingleTableMode,
            );
            $bulkEditHeader = $this->buildBulkEditHeader(
                $tableName,
                $displayColumns,
                $pageId,
                $viewMode,
                $request,
                $isSingleTableMode,
            );

            // Last record UID for drag-drop end dropzone (used by GridView template)
            $lastRecordUid = '';
            if ($enrichedRecords !== []) {
                $lastUidVal = $enrichedRecords[array_key_last($enrichedRecords)]['uid'] ?? 0;
                $lastRecordUid = is_scalar($lastUidVal) ? (string) $lastUidVal : '';
            }

            // Drag-and-drop reordering
            $canReorder = $sortingMode === 'manual' && $hasSortbyField;

            // Multi Record Selection action buttons (Edit, Delete, Transfer/Remove clipboard)
            $displayColumnFields = array_map(static fn(array $col): string => $col['field'], $displayColumns);
            $displayColumnFields = array_values(array_filter($displayColumnFields, static fn(string $f): bool => $f !== ''));
            $recordUids = array_map(
                static fn(array $record): int => is_numeric($record['uid'] ?? null) ? (int) $record['uid'] : 0,
                $enrichedRecords,
            );
            $recordUids = array_values(array_filter($recordUids, static fn(int $uid): bool => $uid > 0));
            $multiRecordSelectionActionsHtml = $this->renderMultiRecordSelectionActions(
                $tableName,
                $pageId,
                $viewMode,
                $request,
                $recordUids,
                $displayColumnFields,
            );

            $tableData[] = [
                'tableName' => $tableName,
                'tableIdentifier' => $tableName,
                'tableHeading' => $this->buildTableHeading($tableName, $recordCount, $isSingleTableMode, $singleTableUrl, $clearTableUrl, $dbList->disableSingleTableView),
                'tableLabel' => $this->getTableLabel($tableName),
                'tableIcon' => $this->getTableIcon($tableName),
                'tableConfig' => $tableConfig,
                'filters' => $filterViewData,
                'records' => $enrichedRecords,
                'hasThumbnails' => $recordGridDataProvider->recordsContainThumbnails($enrichedRecords),
                'recordCount' => $recordCount,
                'hasMore' => $hasMore,
                'hasActiveFilters' => $hasActiveFilters,
                'multiSelectEnabled' => true,
                'lastRecordUid' => $lastRecordUid,
                'actionButtons' => $actionButtons,
                'sortingDropdown' => $sortingDropdown,
                'sortingModeToggle' => $sortingModeToggle,
                'sortableColumnHeaders' => $sortableColumnHeaders,
                'bulkEditHeader' => $bulkEditHeader,
                'singleTableUrl' => $singleTableUrl,
                'clearTableUrl' => $clearTableUrl,
                'formActionUrl' => $singleTableUrl,
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
                'multiRecordSelectionActionsHtml' => $multiRecordSelectionActionsHtml,
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

        // Multi Record Selection JS modules (TYPO3 core API for bulk actions)
        $pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection.js');
        $pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection-delete-action.js');
        $pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection-edit-action.js');
        // recordlist.js handles copyMarked/removeMarked via form submission
        $pageRenderer->loadJavaScriptModule('@typo3/backend/recordlist.js');

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
     * @return array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>}
     */
    private function getTcaForTable(string $tableName): array
    {
        return $this->getTcaConfigurationService()->getTcaForTable($tableName);
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
    private function getItemsPerPage(string $viewMode, int $pageId): int
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);

        // 1. Per-type TSconfig
        $perType = ArrayUtility::valuePath(
            $tsConfig,
            ['mod.', 'web_list.', 'viewMode.', 'types.', $viewMode . '.', 'itemsPerPage'],
        );
        if ($perType !== null && is_numeric($perType)) {
            return max(0, (int) $perType);
        }

        // 2. Global TSconfig
        $global = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'itemsPerPage']);
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
    private function getItemsLimitPerTable(int $pageId): int
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);

        // Check extension-specific setting first
        $extLimit = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'itemsLimitPerTable']);
        if ($extLimit !== null && is_numeric($extLimit)) {
            return max(1, (int) $extLimit);
        }

        // Fall back to TYPO3 Core's itemsLimitPerTable
        $coreLimit = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'itemsLimitPerTable']);
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
    private function buildPagination(
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
        // Always include table in pagination URLs so clicking any pagination
        // link switches to single-table mode (matches TYPO3 Core behavior).
        $urlParams = array_replace(
            ['id' => $pageId, 'displayMode' => $viewMode],
            $this->getRequestParameterService()->getPreservedListParameters($request),
        );
        $urlParams['table'] = $tableName;
        unset($urlParams['pointer']);

        $currentUrl = '';
        try {
            $currentUrl = (string) $uriBuilder->buildUriFromRoute('records', $urlParams);
        } catch (Exception) {
            // Ignore
        }

        return [
            'paginator' => $paginator,
            'pagination' => $pagination,
            'currentUrl' => $currentUrl,
        ];
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

    /**
     * Translate a label key with a fallback value.
     * Avoids short ternary operator (?:) that PHPStan disallows.
     */
    private function translate(LanguageService $languageService, string $key, string $fallback): string
    {
        $translated = $languageService->sL($key);
        return $translated !== '' ? $translated : $fallback;
    }

    /**
     * Render the page-translations sub-list using the active view mode
     * whenever the user selected grid/compact/teaser/custom. List mode
     * continues to use the parent's classic renderer so nothing changes
     * for editors who prefer the standard experience.
     *
     * @param array<int|string, mixed> $siteLanguages Site languages (same shape as parent)
     */
    #[Override]
    protected function renderPageTranslations(DatabaseRecordList $dbList, array $siteLanguages): string
    {
        $request = $this->currentRequest;
        if (!$request instanceof ServerRequestInterface) {
            return parent::renderPageTranslations($dbList, $siteLanguages);
        }

        $resolver = GeneralUtility::makeInstance(ViewModeResolver::class);
        $pageId = $this->pageContext->pageId;
        $viewMode = $resolver->getActiveViewMode($request, $pageId, $this->table);

        if ($viewMode === 'list' || !$resolver->isModeAllowed($viewMode, $pageId)) {
            return parent::renderPageTranslations($dbList, $siteLanguages);
        }

        try {
            $custom = $this->renderPageTranslationsInViewMode($request, $pageId, $viewMode);
            if ($custom !== '') {
                return $custom;
            }
        } catch (Exception) {
            // fall through to parent renderer
        }

        return parent::renderPageTranslations($dbList, $siteLanguages);
    }

    /**
     * Render the page-translations list using the active alternative view
     * mode. Reuses the same `tableData` shape as the main list rendering
     * so every template (built-in and custom) renders it correctly.
     */
    private function renderPageTranslationsInViewMode(
        ServerRequestInterface $request,
        int $pageId,
        string $viewMode,
    ): string {
        $tableName = 'pages';
        $registry = $this->getViewTypeRegistry();
        $viewConfig = $registry->getViewType($viewMode, $pageId);
        if ($viewConfig === null) {
            return '';
        }

        $gridConfigurationService = GeneralUtility::makeInstance(GridConfigurationService::class);
        $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
        $tableConfig = $gridConfigurationService->getTableConfig($tableName, $pageId);

        $records = $this->fetchPageTranslationRecords($pageId, $recordGridDataProvider);
        if ($records === []) {
            return '';
        }

        $columnsConfig = $registry->getDisplayColumnsConfig($viewMode, $pageId);
        $columnsArray = is_array($columnsConfig['columns'] ?? null) ? $columnsConfig['columns'] : [];
        $columnResolver = $this->getDisplayColumnResolver();
        if ((bool) ($columnsConfig['fromTCA'] ?? false)) {
            $displayColumns = $columnResolver->getDisplayColumns($tableName, $this->modTSconfig);
        } elseif ($columnsArray !== []) {
            $displayColumns = $columnResolver->getSpecificDisplayColumns($tableName, $columnsArray);
        } else {
            $displayColumns = $columnResolver->getTeaserDisplayColumns($tableName);
        }

        $enrichedRecords = $this->getViewEnrichmentService()->enrichForAlternativeViews(
            $records,
            $displayColumns,
            $tableName,
            $this->createViewEnrichmentContext(),
        );

        // Translated pages are themselves translation records; they don't
        // carry further translation slots, so mark every row as a regular
        // translation row in a single-table section.
        foreach ($enrichedRecords as &$record) {
            $record['translations'] = [];
            $record['translatedCount'] = 0;
            $record['untranslatedCount'] = 0;
            $record['isFreeTranslation'] = false;
        }
        unset($record);

        $recordCount = count($enrichedRecords);
        $headingLabel = $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:pageTranslation');
        if ($headingLabel === '') {
            $headingLabel = 'Page Translations';
        }

        $tableData = [[
            'tableName' => $tableName,
            'tableIdentifier' => 'pages_translated',
            'tableHeading' => [
                'label' => $headingLabel,
                'recordCount' => $recordCount,
                'linkUrl' => '',
                'iconIdentifier' => '',
            ],
            'tableLabel' => $headingLabel,
            'tableIcon' => $this->getTableIcon($tableName),
            'tableConfig' => $tableConfig,
            'filters' => [
                'visible' => false,
                'items' => [],
            ],
            'records' => $enrichedRecords,
            'hasThumbnails' => $recordGridDataProvider->recordsContainThumbnails($enrichedRecords),
            'recordCount' => $recordCount,
            'hasMore' => false,
            'hasActiveFilters' => false,
            'multiSelectEnabled' => false,
            'lastRecordUid' => '',
            'actionButtons' => [],
            'sortingDropdown' => null,
            'sortingModeToggle' => null,
            'sortableColumnHeaders' => [],
            'bulkEditHeader' => null,
            'singleTableUrl' => '',
            'clearTableUrl' => '',
            'formActionUrl' => '',
            'displayColumns' => $displayColumns,
            'isFiltered' => false,
            'canReorder' => false,
            'sortField' => '',
            'sortDirection' => 'asc',
            'hasSortbyField' => false,
            'sortingMode' => 'field',
            'sortbyFieldName' => '',
            'paginator' => null,
            'pagination' => null,
            'paginationUrl' => '',
            'multiRecordSelectionActionsHtml' => '',
        ]];

        $templatePaths = $registry->getTemplatePaths($viewMode, $pageId);
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: $templatePaths['templateRootPaths'],
            partialRootPaths: $templatePaths['partialRootPaths'],
            layoutRootPaths: $templatePaths['layoutRootPaths'],
            request: $request,
        ));
        $view->assignMultiple([
            'pageId' => $pageId,
            'tableData' => $tableData,
            'currentTable' => $tableName,
            'searchTerm' => '',
            'viewMode' => $viewMode,
            'viewConfig' => $viewConfig,
            'middlewareWarning' => null,
            'forceListViewUrl' => null,
            'clipboardEnabled' => false,
            'isPageTranslationsList' => true,
        ]);

        $html = $view->render($templatePaths['template']);

        // Make DOM ids and selection identifiers unique so the main `pages`
        // list (when present) and the translated-pages list can coexist on
        // the same screen without duplicate ids.
        $html = str_replace(
            ['id="t3-table-pages"', 'id="recordlist-pages"', 't3-table-pages'],
            ['id="t3-table-pages-translated"', 'id="recordlist-pages-translated"', 't3-table-pages-translated'],
            $html,
        );

        return '<div class="records-list-types-page-translations">' . $html . '</div>';
    }

    /**
     * Fetch translated page records on the current page and enrich them
     * via the data provider so they share the same shape as main-list
     * records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPageTranslationRecords(int $pageId, RecordGridDataProvider $dataProvider): array
    {
        $backendUser = $this->getBackendUserAuthentication();
        $workspaceId = $this->getCurrentWorkspaceId();
        $selectedLanguageIds = $this->pageContext->selectedLanguageIds;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        $rows = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'l10n_parent',
                    $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->gt(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER),
                ),
            )
            ->orderBy('sys_language_uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $records = [];
        // In workspaces the query can return both the live row and its
        // versioned/moved counterpart for the same effective record. Key
        // the intermediate result by live identity so the overlay collapses
        // to a single row per record, mirroring the native list behavior.
        $recordsByIdentity = [];
        $useWorkspaceReduction = $workspaceId > 0;

        foreach ($rows as $row) {
            BackendUtility::workspaceOL('pages', $row, -99, true);
            if (!is_array($row)) {
                continue;
            }
            $languageIdRaw = $row['sys_language_uid'] ?? 0;
            $languageId = is_numeric($languageIdRaw) ? (int) $languageIdRaw : 0;
            if (!$backendUser->checkLanguageAccess($languageId)) {
                continue;
            }
            // Honour the docheader language selector.
            if ($selectedLanguageIds !== [] && !in_array($languageId, $selectedLanguageIds, true)) {
                continue;
            }

            $typedRow = ArrayUtility::stringKeyArray($row);
            $recordData = $dataProvider->buildRecordDataFromRow('pages', $typedRow, $pageId);

            if ($useWorkspaceReduction) {
                $uidRaw = $typedRow['uid'] ?? 0;
                $uid = is_numeric($uidRaw) ? (int) $uidRaw : 0;
                $identity = $this->getRecordSortingService()->getWorkspaceRecordIdentity($typedRow, $uid);
                $recordsByIdentity[$identity] = $recordData;
            } else {
                $records[] = $recordData;
            }
        }

        return $useWorkspaceReduction ? array_values($recordsByIdentity) : $records;
    }
}
