<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller;

use Doctrine\DBAL\ParameterType;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Clipboard\Clipboard;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Context\PageContextFactory;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Backend\Controller\RecordListController as CoreRecordListController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\LanguageSelectorBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\RecordIdentityRenderer;
use TYPO3\CMS\Backend\View\RecordSearchBoxComponent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\RecordsListTypes\Pagination\DatabasePaginator;
use Webconsulting\RecordsListTypes\RecordList\AlternativeDatabaseRecordList;
use Webconsulting\RecordsListTypes\Service\GridConfigurationService;
use Webconsulting\RecordsListTypes\Service\ListSortingViewFactory;
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
     * The XCLASS is resolved through the DI container (it is registered as a
     * public service), so constructor injection works for additional
     * dependencies as long as the parent dependencies are passed through.
     */
    public function __construct(
        ComponentFactory $componentFactory,
        IconFactory $iconFactory,
        PageRenderer $pageRenderer,
        EventDispatcherInterface $eventDispatcher,
        UriBuilder $uriBuilder,
        ModuleTemplateFactory $moduleTemplateFactory,
        TcaSchemaFactory $tcaSchemaFactory,
        FlashMessageService $flashMessageService,
        PageContextFactory $pageContextFactory,
        LanguageSelectorBuilder $languageSelectorBuilder,
        RecordIdentityRenderer $recordIdentityRenderer,
        private readonly ViewModeResolver $viewModeResolver,
        private readonly ViewTypeRegistry $viewTypeRegistry,
        private readonly GridConfigurationService $gridConfigurationService,
        private readonly RecordGridDataProvider $recordGridDataProvider,
        private readonly RecordFilterQueryService $recordFilterQueryService,
        private readonly RecordFilterStateService $recordFilterStateService,
        private readonly RecordFilterViewDataFactory $recordFilterViewDataFactory,
        private readonly RecordSortingService $recordSortingService,
        private readonly RecordListRequestParameterService $requestParameterService,
        private readonly ListSortingViewFactory $listSortingViewFactory,
        private readonly TcaTableConfigurationService $tcaConfigurationService,
        private readonly RecordDisplayColumnResolver $displayColumnResolver,
        private readonly RecordViewEnrichmentService $viewEnrichmentService,
        private readonly RecordTranslationGroupingService $translationGroupingService,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {
        parent::__construct(
            $componentFactory,
            $iconFactory,
            $pageRenderer,
            $eventDispatcher,
            $uriBuilder,
            $moduleTemplateFactory,
            $tcaSchemaFactory,
            $flashMessageService,
            $pageContextFactory,
            $languageSelectorBuilder,
            $recordIdentityRenderer,
        );
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
                    $normalized[$table][(string)$key] = (string)$value;
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
    #[\Override]
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->currentRequest = $request;
        // Get view mode resolver

        $requestParams = ArrayUtility::mergedRequestParameters($request);
        $pageId = ArrayUtility::intValue($requestParams['id'] ?? null);
        $requestedTable = ArrayUtility::stringValue($requestParams['table'] ?? null);

        // Get the active view mode
        $viewMode = $this->viewModeResolver->getActiveViewMode($request, $pageId, $requestedTable);
        $this->currentViewMode = $viewMode;

        // Only handle non-list views
        if ($viewMode === 'list' || !$this->viewModeResolver->isModeAllowed($viewMode, $pageId)) {
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
        // Labels used by GridViewActions.js; every prefix must exist in locallang.xlf
        foreach (['drag.', 'action.', 'notification.', 'a11y.', 'pagination.', 'state.'] as $labelPrefix) {
            $this->pageRenderer->addInlineLanguageLabelFile('EXT:records_list_types/Resources/Private/Language/locallang.xlf', $labelPrefix);
        }
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_core.xlf', 'labels.no_title');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/dispatch-modal-button.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/contextual-record-edit-trigger.js');

        BackendUtility::lockRecords();
        $pointer = max(0, ArrayUtility::intValue($requestParams['pointer'] ?? null));
        $this->table = ArrayUtility::stringValue($requestParams['table'] ?? null);
        $this->searchTerm = trim(ArrayUtility::stringValue($requestParams['searchTerm'] ?? null));
        $this->returnUrl = GeneralUtility::sanitizeLocalUrl(
            ArrayUtility::stringValue($requestParams['returnUrl'] ?? null),
            $request,
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
        $this->allowSearch = !(bool)($this->modTSconfig['disableSearchBox'] ?? false);
        if ($this->searchTerm !== '') {
            $this->allowSearch = true;
            $this->moduleData->set('searchBox', true);
        }
        $searchLevelConfig = $this->modTSconfig['searchLevel'] ?? null;
        $searchLevelDefault = 0;
        if (is_array($searchLevelConfig)) {
            $rawDefault = $searchLevelConfig['default'] ?? 0;
            $searchLevelDefault = is_numeric($rawDefault) ? (int)$rawDefault : 0;
        }
        $searchLevels = ArrayUtility::intValue($requestParams['search_levels'] ?? null, $searchLevelDefault);

        $dbList = $this->createDatabaseRecordList($request);

        // Initialize clipboard
        $clipboard = $this->initializeClipboard($request, (bool)$this->moduleData->get('clipBoard'));
        $dbList->clipObj = $clipboard;

        // Store clipboard state for renderViewContent()
        $this->clipboardEnabled = (bool)$this->moduleData->get('clipBoard');

        // Dispatch additional content event
        $additionalRecordListEvent = new RenderAdditionalContentToRecordListEvent($request);
        $this->eventDispatcher->dispatch($additionalRecordListEvent);

        // Create module template (this sets up the backend frame)
        $view = $this->moduleTemplateFactory->create($request);

        $customContent = '';
        if ($this->pageContext->isAccessible()
            || ($this->pageContext->pageId === 0 && $searchLevels !== 0 && $this->searchTerm !== '')) {
            if ($cmd === 'delete' && $request->getMethod() === 'POST') {
                $this->deleteRecords($request, $clipboard);
            }
            $dbList->start($this->pageContext->pageId, $this->table, $pointer, $this->searchTerm, $searchLevels);
            $customContent = $this->renderViewContent($request, $dbList, $pageId, $this->table, $this->searchTerm, $searchLevels, $viewMode);
        }

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
        if ($this->allowSearch && $this->moduleData instanceof ModuleData && (bool)$this->moduleData->get('searchBox')) {
            $searchBoxHtml = $this->renderSearchBox($request, $dbList, $this->searchTerm, $searchLevels);
        }

        // Clipboard
        $clipboardHtml = '';
        if ($this->moduleData instanceof ModuleData && (bool)$this->moduleData->get('clipBoard') && ($customContent !== '' || $clipboard->hasElements())) {
            $clipboardHtml = '<hr class="spacer"><typo3-backend-clipboard-panel return-url="' . htmlspecialchars((string)$dbList->listURL()) . '"></typo3-backend-clipboard-panel>';
        }

        // Set page title
        $view->setTitle(
            (string)($languageService->translate('title', 'backend.modules.list') ?? ''),
            $title,
        );
        if ($customContent === '') {
            $this->addNoRecordsFlashMessage($view, $this->table);
        }

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
            'recordIdentity' => $this->pageContext->pageRecord !== null
                ? $this->recordIdentityRenderer->render('pages', $this->pageContext->pageRecord)
                : '',
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
     * Core's DocHeader, plus a bookmark that opens the same view again.
     */
    #[\Override]
    protected function getDocHeaderButtons(ModuleTemplate $view, Clipboard $clipboard, ServerRequestInterface $request, DatabaseRecordList $dbList): void
    {
        parent::getDocHeaderButtons($view, $clipboard, $request, $dbList);
        if (!$dbList instanceof AlternativeDatabaseRecordList) {
            return;
        }

        $arguments = ['id' => $this->pageContext->pageId, 'displayMode' => $this->currentViewMode];
        $queryParams = $request->getQueryParams();
        foreach (['table', 'searchTerm', 'search_levels'] as $name) {
            $value = $queryParams[$name] ?? null;
            if (is_scalar($value) && (string)$value !== '') {
                $arguments[$name] = (string)$value;
            }
        }
        $view->getDocHeaderComponent()->setShortcutContext('records', $this->getShortcutTitle($arguments), $arguments);
    }

    /**
     * Override renderSearchBox to use the 'records' route with displayMode parameter.
     * This ensures the view mode is preserved when submitting a search and that
     * the search actually goes to the grid view controller, not the core list view.
     */
    #[\Override]
    protected function renderSearchBox(
        ServerRequestInterface $request,
        DatabaseRecordList $dbList,
        string $searchWord,
        int $searchLevels,
    ): string {
        // Get the current view mode
        $viewMode = $this->viewModeResolver->getActiveViewMode($request, $this->pageContext->pageId, $this->table);

        // Build the search URL using the 'records' route (not web_list)
        // This is critical - dbList->listURL() returns a web_list URL which bypasses our controller

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
            $baseUrl = (string)$this->uriBuilder->buildUriFromRoute('records', $searchParams);
        } catch (\Exception) {
            // Fallback to dbList URL if route building fails
            $baseUrl = (string)$dbList->listURL('', '-1', 'pointer,searchTerm,displayMode');
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
     * toggle, column headers, language flags) for every
     * view -- templates simply ignore what they don't need.
     */
    private function renderViewContent(
        ServerRequestInterface $request,
        AlternativeDatabaseRecordList $dbList,
        int $pageId,
        string $table,
        string $searchTerm,
        int $searchLevels,
        string $viewMode,
    ): string {
        // Get view type configuration (null = unknown type, fall back to grid)
        $viewConfig = $this->viewTypeRegistry->getViewType($viewMode, $pageId);
        if ($viewConfig === null) {
            $viewMode = 'grid';
            $viewConfig = $this->viewTypeRegistry->getViewType($viewMode, $pageId);
        }

        // Services

        // Parse request parameters once at the action boundary.
        $requestParams = ArrayUtility::mergedRequestParameters($request);
        $sortParams = (array)($requestParams['sort'] ?? []);
        $sortingModeParams = (array)($requestParams['sortingMode'] ?? []);

        // Display columns configuration from ViewTypeRegistry
        $columnsConfig = $this->viewTypeRegistry->getDisplayColumnsConfig($viewMode, $pageId);

        // Get tables to display
        $tablesToRender = $this->getSearchableTables($pageId, $table, $searchTerm, $searchLevels, $request);

        // Collect all records grouped by table
        $tableData = [];
        foreach ($tablesToRender as $tableName) {
            $tableConfig = $this->gridConfigurationService->getTableConfig($tableName, $pageId);
            $filterViewData = $this->recordFilterViewDataFactory->createForTable($tableName, $pageId, $viewMode, $request);

            // TCA info for sorting capabilities
            $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
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
            $isSearching = ($searchTerm !== '');
            $hasActiveFilters = $this->recordFilterStateService->hasActiveValuesForTable($request, $tableName);
            // Count with exactly the constraints of the record query below, so
            // the pagination never promises more records than the list holds.
            $querySearchLevels = ($isSearching || $isSingleTableMode) ? $searchLevels : 0;
            $recordCount = $this->getRecordCountUsingDbList($tableName, $pageId, $searchTerm, $querySearchLevels, $request);

            // Single-table mode paginates fully; multi-table mode shows a
            // limited per-table preview with an "Expand table" link instead.
            if ($isSingleTableMode) {
                $itemsPerPage = $this->getItemsPerPage($viewMode, $pageId);
                $currentPointer = $this->requestParameterService->getCurrentPointer($request, $tableName);
            } else {
                $itemsPerPage = $this->getItemsLimitPerTable($pageId);
                $currentPointer = 1;
            }
            $offset = ($currentPointer - 1) * $itemsPerPage;
            $records = $this->getRecordsUsingDbList(
                $request,
                $tableName,
                $pageId,
                $searchTerm,
                $querySearchLevels,
                $itemsPerPage,
                $offset,
                $sortField,
                $sortDirection,
            );
            $hasMore = !$isSingleTableMode && $recordCount > count($records);

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
            $actionButtons = $dbList->getTableActions(
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
                $singleTableUrlParams = array_replace($singleTableUrlParams, $this->requestParameterService->getPreservedListParameters($request));
                $singleTableUrl = (string)$this->uriBuilder->buildUriFromRoute('records', $singleTableUrlParams);
                $clearTableUrlParams = [
                    'id' => $pageId,
                    'displayMode' => $viewMode,
                ];
                $clearTableUrlParams = array_replace($clearTableUrlParams, $this->requestParameterService->getPreservedListParameters($request));
                unset($clearTableUrlParams['table']);
                $clearTableUrl = (string)$this->uriBuilder->buildUriFromRoute('records', $clearTableUrlParams);
            } catch (\Exception) {
            }

            // Display columns (from ViewTypeRegistry config)
            $columnsArray = is_array($columnsConfig['columns'] ?? null) ? $columnsConfig['columns'] : [];
            $columnResolver = $this->displayColumnResolver;
            if ((bool)($columnsConfig['fromTCA'] ?? false)) {
                $displayColumns = $columnResolver->getDisplayColumns($tableName, ArrayUtility::stringKeyArray($this->modTSconfig));
            } elseif ($columnsArray !== []) {
                $displayColumns = $columnResolver->getSpecificDisplayColumns($tableName, $columnsArray);
            } else {
                $displayColumns = $columnResolver->getTeaserDisplayColumns($tableName);
            }

            $enrichmentContext = $this->createViewEnrichmentContext();
            $enrichedRecords = $this->viewEnrichmentService->enrichForAlternativeViews(
                $records,
                $displayColumns,
                $tableName,
                $enrichmentContext,
            );

            // Separate connected translations from default-language / free-mode records
            $enrichedRecords = $this->translationGroupingService->groupTranslationsOnRecords(
                $enrichedRecords,
                $tableName,
                $pageId,
                $this->recordGridDataProvider,
                $enrichmentContext,
            );

            $enrichedRecords = $this->viewEnrichmentService->enrichTranslationsWithDisplayValues(
                $enrichedRecords,
                $displayColumns,
                $tableName,
                $enrichmentContext,
            );

            // The icon with its context menu and the control panel come from
            // Core, so every view offers exactly the actions of the list view.
            $controlsRecordList = $this->createControlsRecordList(
                $request,
                $dbList->clipObj,
                $tableName,
                $pageId,
                $searchTerm,
                $querySearchLevels,
                $sortingMode === 'manual' && $sortDirection === 'asc' ? '' : $sortField,
            );
            $controlsRecordList->prepareManualSorting($tableName, $this->getDefaultLanguageRows($enrichedRecords));
            $enrichedRecords = $this->viewEnrichmentService->enrichRecordsWithCoreMarkup(
                $enrichedRecords,
                $tableName,
                $controlsRecordList,
            );

            // Sorting dropdown / toggle data
            $sortableFields = $this->recordGridDataProvider->getSortableFields($tableName);
            $sortingDropdown = $this->listSortingViewFactory->buildSortingDropdown(
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
                $sortingModeToggle = $this->listSortingViewFactory->buildSortingModeToggle(
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
            $sortableColumnHeaders = $this->listSortingViewFactory->getSortableColumnHeaders(
                $tableName,
                $displayColumns,
                $sortField,
                $sortDirection,
                $pageId,
                $viewMode,
                $request,
                $isSingleTableMode,
            );
            $bulkEditHeader = $this->listSortingViewFactory->buildBulkEditHeader(
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
                $lastRecordUid = is_scalar($lastUidVal) ? (string)$lastUidVal : '';
            }

            // Drag-and-drop reordering
            $canReorder = $sortingMode === 'manual' && $hasSortbyField;

            // Multi Record Selection action buttons (Edit, Delete, Transfer/Remove clipboard)
            $displayColumnFields = array_map(static fn(array $col): string => $col['field'], $displayColumns);
            $displayColumnFields = array_values(array_filter($displayColumnFields, static fn(string $f): bool => $f !== ''));
            $recordUids = array_map(
                static fn(array $record): int => is_numeric($record['uid'] ?? null) ? (int)$record['uid'] : 0,
                $enrichedRecords,
            );
            $recordUids = array_values(array_filter($recordUids, static fn(int $uid): bool => $uid > 0));
            $multiRecordSelectionActionsHtml = $this->renderMultiRecordSelectionActions(
                $tableName,
                $pageId,
                $viewMode,
                $request,
                $dbList->clipObj,
                $recordUids,
                $displayColumnFields,
            );

            $tableData[] = [
                'tableName' => $tableName,
                'tableIdentifier' => $tableName,
                'isCollapsed' => !$isSingleTableMode && $this->isTableCollapsed($tableName),
                'isLanguageAware' => $this->recordGridDataProvider->isLanguageAwareTable($tableName),
                'messages' => $this->buildTableMessages($tableName),
                'tableHeading' => $this->buildTableHeading($tableName, $recordCount, $isSingleTableMode, $singleTableUrl, $clearTableUrl, $dbList->disableSingleTableView),
                'tableLabel' => $this->getTableLabel($tableName),
                'tableIcon' => $this->getTableIcon($tableName),
                'tableConfig' => $tableConfig,
                'filters' => $filterViewData,
                'records' => $enrichedRecords,
                'hasThumbnails' => $this->recordGridDataProvider->recordsContainThumbnails($enrichedRecords),
                'recordCount' => $recordCount,
                'hasMore' => $hasMore,
                'hasActiveFilters' => $hasActiveFilters,
                'emptyState' => $this->buildEmptyState($hasActiveFilters, $searchTerm, $filterViewData),
                'multiSelectEnabled' => true,
                'lastRecordUid' => $lastRecordUid,
                'actionButtons' => $actionButtons,
                'sortingDropdown' => $sortingDropdown,
                'sortingModeToggle' => $sortingModeToggle,
                'sortableColumnHeaders' => $sortableColumnHeaders,
                'bulkEditHeader' => $bulkEditHeader,
                'singleTableUrl' => $singleTableUrl,
                'clearTableUrl' => $clearTableUrl,
                'formActionUrl' => $isSingleTableMode ? $singleTableUrl : $clearTableUrl,
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
        foreach ($this->viewTypeRegistry->getCssFiles($viewMode, $pageId) as $cssFile) {
            $this->pageRenderer->addCssFile($cssFile);
        }
        foreach ($this->viewTypeRegistry->getJsModules($viewMode, $pageId) as $jsModule) {
            $this->pageRenderer->loadJavaScriptModule($jsModule);
        }
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/column-selector-button.js');

        // Multi Record Selection JS modules (TYPO3 core API for bulk actions)
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection-delete-action.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection-edit-action.js');
        // recordlist.js handles copyMarked/removeMarked via form submission
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/recordlist.js');

        // Create the view from ViewTypeRegistry template paths
        $templatePaths = $this->viewTypeRegistry->getTemplatePaths($viewMode, $pageId);
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: $templatePaths['templateRootPaths'],
            partialRootPaths: $templatePaths['partialRootPaths'],
            layoutRootPaths: $templatePaths['layoutRootPaths'],
            request: $request,
        );

        $view = $this->viewFactory->create($viewFactoryData);
        $view->assignMultiple([
            'pageId' => $pageId,
            'tableData' => $tableData,
            'currentTable' => $table,
            'searchTerm' => $searchTerm,
            'viewMode' => $viewMode,
            'viewConfig' => $viewConfig,
            'clipboardEnabled' => $this->clipboardEnabled,
        ]);

        return $view->render($templatePaths['template']);
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
    private function getSearchableTables(
        int $pageId,
        string $specificTable,
        string $searchTerm,
        int $searchLevels,
        ServerRequestInterface $request,
    ): array {
        if ($specificTable !== '' && !$this->tcaSchemaFactory->has($specificTable)) {
            return [];
        }
        $dbList = $this->createDatabaseRecordListForTable($specificTable, $pageId, $searchTerm, $searchLevels, $request);
        $tables = $dbList->getTablesToRender();
        if ($specificTable !== '') {
            return $tables;
        }

        return array_values(array_filter(
            $tables,
            fn(string $table): bool => $this->getRecordCountUsingDbList($table, $pageId, $searchTerm, $searchLevels, $request) > 0,
        ));
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
     * @return AlternativeDatabaseRecordList The initialized record list
     */
    private function createDatabaseRecordListForTable(
        string $tableName,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        ServerRequestInterface $request,
    ): AlternativeDatabaseRecordList {
        $dbList = $this->createDatabaseRecordList($request);
        $dbList->start($pageId, $tableName, 0, $searchTerm, $searchLevels);
        return $dbList;
    }

    private function createDatabaseRecordList(ServerRequestInterface $request): AlternativeDatabaseRecordList
    {
        $backendUser = $this->getBackendUserAuthentication();
        $dbList = GeneralUtility::makeInstance(AlternativeDatabaseRecordList::class);
        $dbList->setRequest($request);
        if ($this->moduleData instanceof ModuleData) {
            $dbList->setModuleData($this->moduleData);
        }
        $dbList->calcPerms = $this->pageContext->pagePermissions;
        $dbList->returnUrl = $this->returnUrl;
        $dbList->showClipboardActions = true;
        $dbList->disableSingleTableView = (bool)($this->modTSconfig['disableSingleTableView'] ?? false);
        $dbList->listOnlyInSingleTableMode = (bool)($this->modTSconfig['listOnlyInSingleTableView'] ?? false);
        $dbList->hideTables = ArrayUtility::stringValue($this->modTSconfig['hideTables'] ?? null);
        $dbList->hideTranslations = ArrayUtility::stringValue($this->modTSconfig['hideTranslations'] ?? null);
        $dbList->tableTSconfigOverTCA = $this->getTableTsConfigOverTca();
        $dbList->allowedNewTables = ArrayUtility::commaSeparatedList($this->modTSconfig['allowedNewTables'] ?? null);
        $dbList->deniedNewTables = ArrayUtility::commaSeparatedList($this->modTSconfig['deniedNewTables'] ?? null);
        /** @var array<string> $pageRecord */
        $pageRecord = $this->pageContext->pageRecord ?? [];
        $dbList->pageRow = $pageRecord;
        $dbList->modTSconfig = $this->modTSconfig;
        $siteLanguages = $this->pageContext->site->getAvailableLanguages($backendUser, false, $this->pageContext->pageId);
        $dbList->setLanguagesAllowedForUser($siteLanguages);
        $clickTitleMode = trim(ArrayUtility::stringValue($this->modTSconfig['clickTitleMode'] ?? null));
        $dbList->clickTitleMode = $clickTitleMode === '' ? 'edit' : $clickTitleMode;
        $tableDisplayOrder = $this->modTSconfig['tableDisplayOrder'] ?? null;
        if (is_array($tableDisplayOrder)) {
            $dbList->setTableDisplayOrder($tableDisplayOrder);
        }
        $dbList->setOverrideUrlParameters($this->getListStateParameters($request), $request);
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
     * @param int $pageId The current page ID
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth level
     * @param int $limit Maximum number of records to fetch (0 = no limit)
     * @param int $offset Number of records to skip (for pagination)
     * @return array<int, array<string, mixed>> Array of enriched record data
     */
    private function getRecordsUsingDbList(
        ServerRequestInterface $request,
        string $tableName,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        int $limit = 100,
        int $offset = 0,
        string $sortField = '',
        string $sortDirection = 'asc',
    ): array {
        $records = [];
        $recordsByIdentity = [];
        $workspaceId = $this->getCurrentWorkspaceId();
        $useWorkspaceReduction = $workspaceId > 0;
        $deferWorkspaceEvaluation = $this->recordFilterQueryService->shouldDeferWorkspaceEvaluation($tableName, $pageId, $request, $searchTerm);
        $querySearchTerm = $deferWorkspaceEvaluation ? '' : $searchTerm;

        try {
            // Create a properly initialized DatabaseRecordList for this table
            $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, $querySearchTerm, $searchLevels, $request);
            $dbList->sortField = $sortField;
            $dbList->sortRev = strtolower($sortDirection) === 'desc';

            // Use DatabaseRecordList's query builder which handles search properly
            // This is the same API the core list view uses
            $queryBuilder = $dbList->getQueryBuilder(
                $tableName,
                ['*'],
                true,
                $deferWorkspaceEvaluation ? 0 : $offset,
                $deferWorkspaceEvaluation ? 0 : $limit,
            );
            $this->recordFilterQueryService->applyActiveFilters($queryBuilder, $tableName, $pageId, $request, $deferWorkspaceEvaluation);
            $result = $queryBuilder->executeQuery();

            while ($row = $result->fetchAssociative()) {
                // Apply workspace overlay to get the correct version for the
                // current workspace. The -99 placeholder tells workspaceOL()
                // to read the active workspace id itself.
                BackendUtility::workspaceOL($tableName, $row, -99, true);

                // workspaceOL returns false/null if record is deleted in workspace or should not be shown
                if (!is_array($row)) {
                    continue;
                }

                $typedRow = ArrayUtility::stringKeyArray($row);
                if ($deferWorkspaceEvaluation && !$this->recordFilterQueryService->matchesDeferredWorkspaceEvaluation($tableName, $pageId, $request, $typedRow, $searchTerm)) {
                    continue;
                }

                $uid = ArrayUtility::intValue($typedRow['uid'] ?? null);
                $recordData = $this->recordGridDataProvider->buildRecordDataFromRow($tableName, $typedRow, $pageId);

                if ($useWorkspaceReduction) {
                    // In workspaces the DB query can still yield both a live row and a
                    // versioned/moved row that overlay to the same effective record.
                    // Reduce them by their live identity so custom views mirror the
                    // native list's single effective row per record.
                    $identity = $this->recordSortingService->getWorkspaceRecordIdentity($typedRow, $uid);
                    $recordsByIdentity[$identity] = $recordData;
                } else {
                    $records[] = $recordData;
                }
            }
        } catch (\Exception) {
            // Log error but don't fail - return empty results
            // This can happen if the table doesn't exist or user lacks permissions
        }

        if ($useWorkspaceReduction) {
            $records = array_values($recordsByIdentity);
            if ($sortField !== '') {
                $this->recordSortingService->sortRecordsByRawField($records, $sortField, $sortDirection);
            }
            if ($deferWorkspaceEvaluation && $limit > 0) {
                return array_slice($records, $offset, $limit);
            }
            return $records;
        }

        return $records;
    }

    /**
     * Resolve the current workspace id via the Context aspect — the canonical
     * TYPO3 v14 API. Falls back to 0 (LIVE) when the aspect is missing.
     */
    private function getCurrentWorkspaceId(): int
    {
        $workspaceId = $this->context
            ->getPropertyFromAspect('workspace', 'id', 0);
        return is_numeric($workspaceId) ? (int)$workspaceId : 0;
    }

    /**
     * Get total count of records for a table, honoring search term and
     * active record filters by using the same DatabaseRecordList query
     * builder as getRecordsUsingDbList().
     */
    private function getRecordCountUsingDbList(
        string $tableName,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        ServerRequestInterface $request,
    ): int {
        try {
            if ($this->recordFilterQueryService->shouldDeferWorkspaceEvaluation($tableName, $pageId, $request, $searchTerm)) {
                return count($this->getRecordsUsingDbList(
                    $request,
                    $tableName,
                    $pageId,
                    $searchTerm,
                    $searchLevels,
                    0,
                ));
            }

            $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, $searchTerm, $searchLevels, $request);
            $qb = $dbList->getQueryBuilder($tableName, ['uid'], false, 0, 0);
            $this->recordFilterQueryService->applyActiveFilters($qb, $tableName, $pageId, $request);
            $count = $qb->count('*')->executeQuery()->fetchOne();
            return is_numeric($count) ? (int)$count : 0;
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * The action bar Core shows while records are selected (edit, edit
     * columns, delete, clipboard). A view that shows other columns than the
     * list view additionally gets "Edit the shown columns".
     *
     * @param list<int> $currentRecordUids UIDs of currently rendered records
     * @param list<string> $displayColumnFields Field names of the currently displayed columns
     */
    private function renderMultiRecordSelectionActions(
        string $tableName,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
        Clipboard $clipboard,
        array $currentRecordUids = [],
        array $displayColumnFields = [],
    ): string {
        $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, '', 0, $request);
        $dbList->clipObj = $clipboard;
        $buttons = $dbList->renderMultiRecordSelectionActions($tableName, $currentRecordUids);

        $listViewColumns = array_values(array_filter(
            $dbList->getColumnsToRender($tableName, false),
            is_string(...),
        ));
        if ($displayColumnFields === []
            || array_diff($displayColumnFields, $listViewColumns) === []
            || !str_contains($buttons, 'data-multi-record-selection-action="edit"')
        ) {
            return $buttons;
        }

        try {
            $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('records', array_replace(
                ['id' => $pageId, 'displayMode' => $viewMode, 'table' => $tableName],
                $this->requestParameterService->getPreservedListParameters($request),
            ));
        } catch (\Exception) {
            $returnUrl = (string)$request->getUri();
        }

        $label = htmlspecialchars($this->getLanguageService()->sL('records_list_types.messages:action.editColumns'));
        $editColumnsConfig = GeneralUtility::jsonEncodeForHtmlAttribute([
            'idField' => 'uid',
            'tableName' => $tableName,
            'returnUrl' => $returnUrl,
            'columnsOnly' => $displayColumnFields,
        ]);

        return $buttons . PHP_EOL
            . '<button type="button" class="btn btn-sm btn-default" title="' . $label . '"'
            . ' data-multi-record-selection-action="edit"'
            . ' data-multi-record-selection-action-config="' . $editColumnsConfig . '">'
            . $this->iconFactory->getIcon('actions-document-open', IconSize::SMALL)->render()
            . ' ' . $label
            . '</button>';
    }

    /**
     * A record list for one table that renders the per-record markup of the
     * list view. An empty sort field means records are listed in ascending
     * manual order, the only case in which Core offers "Move up/down".
     */
    private function createControlsRecordList(
        ServerRequestInterface $request,
        Clipboard $clipboard,
        string $tableName,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
        string $sortField,
    ): AlternativeDatabaseRecordList {
        $recordList = $this->createDatabaseRecordListForTable($tableName, $pageId, $searchTerm, $searchLevels, $request);
        $recordList->clipObj = $clipboard;
        $recordList->sortField = $sortField;

        return $recordList;
    }

    /**
     * Raw rows of the default-language records in the listed order; free-mode
     * translations are listed after them and never take part in manual sorting.
     *
     * @param array<int, array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function getDefaultLanguageRows(array $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            if (($record['isFreeTranslation'] ?? false) === true || !is_array($record['rawRecord'] ?? null)) {
                continue;
            }
            $rows[] = ArrayUtility::stringKeyArray($record['rawRecord']);
        }

        return $rows;
    }

    /**
     * Collapsed tables are stored by recordlist.js in the module data, the
     * same place the list view reads them from.
     */
    private function isTableCollapsed(string $tableIdentifier): bool
    {
        $collapsedTables = $this->moduleData?->get('collapsedTables');

        return is_array($collapsedTables) && (bool)($collapsedTables[$tableIdentifier] ?? false);
    }

    /**
     * The notices the list view shows above a table that cannot be versioned
     * in the current workspace.
     *
     * @return list<array{message: string, severity: string, icon: string}>
     */
    private function buildTableMessages(string $tableName): array
    {
        $backendUser = $this->getBackendUserAuthentication();
        if ($backendUser->workspace === 0
            || !ExtensionManagementUtility::isLoaded('workspaces')
            || !$this->tcaSchemaFactory->has($tableName)
            || $this->tcaSchemaFactory->get($tableName)->hasCapability(TcaSchemaCapability::Workspace)
        ) {
            return [];
        }

        [$key, $severity] = $backendUser->workspaceAllowsLiveEditingInTable($tableName)
            ? ['core.core:labels.editingLiveRecordsWarning', ContextualFeedbackSeverity::WARNING]
            : ['core.core:labels.notEditableInWorkspace', ContextualFeedbackSeverity::INFO];

        return [[
            'message' => $this->getLanguageService()->sL($key),
            'severity' => $severity->getCssClass(),
            'icon' => $severity->getIconIdentifier(),
        ]];
    }

    /**
     * Parameters every link built by Core's record list keeps, so edit, delete,
     * move and clipboard actions return to the same view, filters and sorting.
     *
     * @return array<string, mixed>
     */
    private function getListStateParameters(ServerRequestInterface $request): array
    {
        $parameters = ['displayMode' => $this->currentViewMode];
        foreach ($this->requestParameterService->getPreservedListParameters($request) as $name => $value) {
            if (in_array($name, ['filters', 'recordFilters', 'sort', 'sortingMode'], true)
                || ($name === 'pointer' && is_array($value))
            ) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Build structured table-heading data for Fluid rendering.
     *
     * @return array{label: string, recordCount: int, linkUrl: string, iconIdentifier: string}
     */
    private function buildTableHeading(
        string $tableName,
        int $recordCount,
        bool $isSingleTableMode,
        string $singleTableUrl,
        string $clearTableUrl,
        bool $disableSingleTableView,
    ): array {
        $lang = $this->getLanguageService();
        $tableTitle = $tableName;
        if ($this->tcaSchemaFactory->has($tableName)) {
            $resolvedTitle = $this->tcaSchemaFactory->get($tableName)->getTitle($lang->sL(...));
            $tableTitle = $resolvedTitle !== '' ? $resolvedTitle : $tableName;
        }

        $heading = [
            'label' => $tableTitle,
            'recordCount' => $recordCount,
            'linkUrl' => '',
            'linkTitle' => '',
            'iconIdentifier' => '',
        ];
        if ($disableSingleTableView || !$this->tcaSchemaFactory->has($tableName)) {
            return $heading;
        }

        $heading['linkUrl'] = $isSingleTableMode ? $clearTableUrl : $singleTableUrl;
        $heading['linkTitle'] = $lang->sL(
            'core.mod_web_list:' . ($isSingleTableMode ? 'contractView' : 'expandView'),
        );
        $heading['iconIdentifier'] = $isSingleTableMode ? 'actions-view-table-collapse' : 'actions-view-table-expand';

        return $heading;
    }

    /**
     * Action buttons for the page-translations sub-list.
     *
     * Mirrors the classic List View header: column selector and
     * collapse/expand only (no new-record or download actions).
     *
     * @return array<string, string>
     */
    private function createPageTranslationActionButtons(
        AlternativeDatabaseRecordList $dbList,
        string $tableName,
        int $recordCount,
    ): array {
        $buttons = $dbList->getTableActions($tableName, $recordCount, false);

        return [
            'newRecordButton' => '',
            'downloadButton' => '',
            'columnSelectorButton' => $buttons['columnSelectorButton'],
            'collapseButton' => $buttons['collapseButton'],
        ];
    }

    /**
     * Get a human-readable label for a table.
     */
    private function getTableLabel(string $tableName): string
    {
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
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
    private function getTableIcon(string $tableName): string
    {
        $icon = $this->mapRecordTypeToIconIdentifier($tableName, []);
        return $icon !== '' ? $icon : 'mimetypes-x-content-text';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRecordTypeToIconIdentifier(string $tableName, array $row): string
    {
        if (!$this->tcaSchemaFactory->has($tableName)) {
            return '';
        }

        return $this->iconFactory->mapRecordTypeToIconIdentifier($tableName, $row, $this->tcaSchemaFactory->get($tableName));
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
            return max(0, (int)$perType);
        }

        // 2. Global TSconfig
        $global = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'itemsPerPage']);
        if ($global !== null && is_numeric($global)) {
            return max(0, (int)$global);
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
            return max(1, (int)$extLimit);
        }

        // Fall back to TYPO3 Core's itemsLimitPerTable
        $coreLimit = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'itemsLimitPerTable']);
        if ($coreLimit !== null && is_numeric($coreLimit)) {
            return max(1, (int)$coreLimit);
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
        // Always include table in pagination URLs so clicking any pagination
        // link switches to single-table mode (matches TYPO3 Core behavior).
        $urlParams = array_replace(
            ['id' => $pageId, 'displayMode' => $viewMode],
            $this->requestParameterService->getPreservedListParameters($request),
        );
        $urlParams['table'] = $tableName;
        unset($urlParams['pointer']);

        $currentUrl = '';
        try {
            $currentUrl = (string)$this->uriBuilder->buildUriFromRoute('records', $urlParams);
        } catch (\Exception) {
            // Ignore
        }

        return [
            'paginator' => $paginator,
            'pagination' => $pagination,
            'currentUrl' => $currentUrl,
        ];
    }

    /**
     * Translate a label key with a fallback value.
     * Avoids short ternary operator (?:) that PHPStan disallows.
     */
    /**
     * View model for the "nothing to show" panel: the reason the list is empty
     * plus, when the editor narrowed it down themselves, a way back out.
     *
     * @param array<string, mixed> $filterViewData
     * @return array{message: string, resetUrl: string, resetLabel: string}
     */
    private function buildEmptyState(bool $hasActiveFilters, string $searchTerm, array $filterViewData): array
    {
        $lang = $this->getLanguageService();
        $key = match (true) {
            $hasActiveFilters && $searchTerm !== '' => 'noRecords.filteredAndSearch',
            $hasActiveFilters => 'noRecords.filtered',
            $searchTerm !== '' => 'noRecords.search',
            default => 'noRecords',
        };
        $narrowedDown = $hasActiveFilters || $searchTerm !== '';
        $clearUrl = ArrayUtility::stringValue($filterViewData['clearUrl'] ?? null);

        return [
            'message' => $lang->sL('records_list_types.messages:' . $key),
            'resetUrl' => $narrowedDown ? $clearUrl : '',
            'resetLabel' => $narrowedDown ? $lang->sL('records_list_types.messages:noRecords.resetAll') : '',
        ];
    }

    /**
     * Render the page-translations sub-list using the active view mode
     * whenever the user selected grid/compact/teaser/custom. List mode
     * continues to use the parent's classic renderer so nothing changes
     * for editors who prefer the standard experience.
     *
     * @param array<int|string, mixed> $siteLanguages Site languages (same shape as parent)
     */
    #[\Override]
    protected function renderPageTranslations(DatabaseRecordList $dbList, array $siteLanguages): string
    {
        $request = $this->currentRequest;
        if (!$request instanceof ServerRequestInterface) {
            return parent::renderPageTranslations($dbList, $siteLanguages);
        }

        $pageId = $this->pageContext->pageId;
        $viewMode = $this->viewModeResolver->getActiveViewMode($request, $pageId, $this->table);

        if (!$dbList instanceof AlternativeDatabaseRecordList || $viewMode === 'list' || !$this->viewModeResolver->isModeAllowed($viewMode, $pageId)) {
            return parent::renderPageTranslations($dbList, $siteLanguages);
        }

        try {
            $custom = $this->renderPageTranslationsInViewMode($request, $dbList, $pageId, $viewMode);
            if ($custom !== '') {
                return $custom;
            }
        } catch (\Exception) {
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
        AlternativeDatabaseRecordList $dbList,
        int $pageId,
        string $viewMode,
    ): string {
        $tableName = 'pages';
        $viewConfig = $this->viewTypeRegistry->getViewType($viewMode, $pageId);
        if ($viewConfig === null) {
            return '';
        }

        $tableConfig = $this->gridConfigurationService->getTableConfig($tableName, $pageId);

        $records = $this->fetchPageTranslationRecords($pageId);
        if ($records === []) {
            return '';
        }

        $columnsConfig = $this->viewTypeRegistry->getDisplayColumnsConfig($viewMode, $pageId);
        $columnsArray = is_array($columnsConfig['columns'] ?? null) ? $columnsConfig['columns'] : [];
        $columnResolver = $this->displayColumnResolver;
        if ((bool)($columnsConfig['fromTCA'] ?? false)) {
            $displayColumns = $columnResolver->getDisplayColumns($tableName, ArrayUtility::stringKeyArray($this->modTSconfig));
        } elseif ($columnsArray !== []) {
            $displayColumns = $columnResolver->getSpecificDisplayColumns($tableName, $columnsArray);
        } else {
            $displayColumns = $columnResolver->getTeaserDisplayColumns($tableName);
        }

        $enrichedRecords = $this->viewEnrichmentService->enrichForAlternativeViews(
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

        // The list view renders this section with showOnlyTranslatedRecords,
        // which also drops the clipboard actions of page translations.
        $controlsRecordList = $this->createControlsRecordList($request, $dbList->clipObj, $tableName, $pageId, '', 0, '');
        $controlsRecordList->showOnlyTranslatedRecords(true);
        $enrichedRecords = $this->viewEnrichmentService->enrichRecordsWithCoreMarkup(
            $enrichedRecords,
            $tableName,
            $controlsRecordList,
        );

        $recordCount = count($enrichedRecords);
        $headingLabel = $this->tcaConfigurationService->translateTcaLabel('core.core:pageTranslation', 'Page Translations');

        $actionButtons = $this->createPageTranslationActionButtons($dbList, $tableName, $recordCount);
        $sortableColumnHeaders = $this->listSortingViewFactory->getSortableColumnHeaders(
            $tableName,
            $displayColumns,
            '',
            'asc',
            $pageId,
            $viewMode,
            $request,
        );

        $this->pageRenderer
            ->loadJavaScriptModule('@typo3/backend/column-selector-button.js');

        $tableData = [[
            'tableName' => $tableName,
            'tableIdentifier' => 'pages_translated',
            'isCollapsed' => $this->isTableCollapsed('pages_translated'),
            'isLanguageAware' => true,
            'messages' => $this->buildTableMessages($tableName),
            'tableHeading' => [
                'label' => $headingLabel,
                'recordCount' => $recordCount,
                'linkUrl' => '',
                'linkTitle' => '',
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
            'hasThumbnails' => $this->recordGridDataProvider->recordsContainThumbnails($enrichedRecords),
            'recordCount' => $recordCount,
            'hasMore' => false,
            'hasActiveFilters' => false,
            'emptyState' => $this->buildEmptyState(false, '', []),
            'multiSelectEnabled' => false,
            'lastRecordUid' => '',
            'actionButtons' => $actionButtons,
            'sortingDropdown' => null,
            'sortingModeToggle' => null,
            'sortableColumnHeaders' => $sortableColumnHeaders,
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

        $templatePaths = $this->viewTypeRegistry->getTemplatePaths($viewMode, $pageId);
        $view = $this->viewFactory->create(new ViewFactoryData(
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
            'clipboardEnabled' => false,
            'isPageTranslationsList' => true,
        ]);

        return $view->render($templatePaths['template']);
    }

    /**
     * Fetch translated page records on the current page and enrich them
     * via the data provider so they share the same shape as main-list
     * records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPageTranslationRecords(int $pageId): array
    {
        $backendUser = $this->getBackendUserAuthentication();
        $workspaceId = $this->getCurrentWorkspaceId();
        $selectedLanguageIds = $this->pageContext->selectedLanguageIds;
        $queryBuilder = $this->connectionPool
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
            $languageId = is_numeric($languageIdRaw) ? (int)$languageIdRaw : 0;
            if (!$backendUser->checkLanguageAccess($languageId)) {
                continue;
            }
            // Honour the docheader language selector.
            if ($selectedLanguageIds !== [] && !in_array($languageId, $selectedLanguageIds, true)) {
                continue;
            }

            $typedRow = ArrayUtility::stringKeyArray($row);
            $recordData = $this->recordGridDataProvider->buildRecordDataFromRow('pages', $typedRow, $pageId);

            if ($useWorkspaceReduction) {
                $uidRaw = $typedRow['uid'] ?? 0;
                $uid = is_numeric($uidRaw) ? (int)$uidRaw : 0;
                $identity = $this->recordSortingService->getWorkspaceRecordIdentity($typedRow, $uid);
                $recordsByIdentity[$identity] = $recordData;
            } else {
                $records[] = $recordData;
            }
        }

        return $useWorkspaceReduction ? array_values($recordsByIdentity) : $records;
    }
}
