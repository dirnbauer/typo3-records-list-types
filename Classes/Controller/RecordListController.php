<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\RecordsListTypes\Service\GridConfigurationService;
use Webconsulting\RecordsListTypes\Service\MiddlewareDiagnosticService;
use Webconsulting\RecordsListTypes\Service\RecordGridDataProvider;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;
use Webconsulting\RecordsListTypes\Service\ViewTypeRegistry;

/**
 * Extended RecordListController with Grid View support.
 *
 * This controller extends the core RecordListController to add
 * card-based Grid View rendering when displayMode=grid is set.
 *
 * IMPORTANT: We replicate the parent's mainAction() initialization flow
 * to ensure all DocHeader buttons, clipboard, page context etc. work correctly.
 */
final class RecordListController extends CoreRecordListController
{
    private ?ViewTypeRegistry $viewTypeRegistry = null;

    /**
     * Get the ViewTypeRegistry from the DI container.
     * XClasses can't use constructor injection for additional dependencies,
     * so we fetch it from the container on demand.
     */
    protected function getViewTypeRegistry(): ViewTypeRegistry
    {
        if ($this->viewTypeRegistry === null) {
            $this->viewTypeRegistry = GeneralUtility::getContainer()->get(ViewTypeRegistry::class);
        }
        return $this->viewTypeRegistry;
    }

    /**
     * Main action - renders the appropriate view based on displayMode.
     *
     * Supported modes:
     * - list: Standard table view (parent controller)
     * - grid: Card-based grid view
     * - compact: Compact single-line view
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
        $pageId = (int) ($queryParams['id'] ?? $parsedBody['id'] ?? 0);

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

        // Initialize from parent's flow
        $this->pageContext = $request->getAttribute('pageContext');
        $this->moduleData = $request->getAttribute('moduleData');

        $languageService = $this->getLanguageService();
        $backendUser = $this->getBackendUserAuthentication();

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_mod_web_list.xlf');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/dispatch-modal-button.js');

        BackendUtility::lockRecords();
        $pointer = max(0, (int) ($parsedBody['pointer'] ?? $queryParams['pointer'] ?? 0));
        $this->table = (string) ($parsedBody['table'] ?? $queryParams['table'] ?? '');
        $this->searchTerm = trim((string) ($parsedBody['searchTerm'] ?? $queryParams['searchTerm'] ?? ''));
        $this->returnUrl = GeneralUtility::sanitizeLocalUrl((string) ($parsedBody['returnUrl'] ?? $queryParams['returnUrl'] ?? ''));
        $cmd = (string) ($parsedBody['cmd'] ?? $queryParams['cmd'] ?? '');

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
        $this->allowSearch = !($this->modTSconfig['disableSearchBox'] ?? false);
        if (!empty($this->searchTerm)) {
            $this->allowSearch = true;
            $this->moduleData->set('searchBox', true);
        }
        $searchLevels = (int) ($parsedBody['search_levels'] ?? $queryParams['search_levels'] ?? $this->modTSconfig['searchLevel']['default'] ?? 0);

        // Create DatabaseRecordList (needed for URL building and other parent methods)
        $dbList = GeneralUtility::makeInstance(DatabaseRecordList::class);
        $dbList->setRequest($request);
        $dbList->setModuleData($this->moduleData);
        $dbList->calcPerms = $this->pageContext->pagePermissions;
        $dbList->returnUrl = $this->returnUrl;
        $dbList->showClipboardActions = true;
        $dbList->disableSingleTableView = $this->modTSconfig['disableSingleTableView'] ?? false;
        $dbList->listOnlyInSingleTableMode = $this->modTSconfig['listOnlyInSingleTableView'] ?? false;
        $dbList->hideTables = $this->modTSconfig['hideTables'] ?? '';
        $dbList->hideTranslations = (string) ($this->modTSconfig['hideTranslations'] ?? '');
        $dbList->tableTSconfigOverTCA = $this->modTSconfig['table'] ?? [];
        $dbList->allowedNewTables = GeneralUtility::trimExplode(',', $this->modTSconfig['allowedNewTables'] ?? '', true);
        $dbList->deniedNewTables = GeneralUtility::trimExplode(',', $this->modTSconfig['deniedNewTables'] ?? '', true);
        $dbList->pageRow = $this->pageContext->pageRecord ?? [];
        $dbList->modTSconfig = $this->modTSconfig;
        $dbList->setLanguagesAllowedForUser($siteLanguages);
        $clickTitleMode = trim($this->modTSconfig['clickTitleMode'] ?? '');
        $dbList->clickTitleMode = $clickTitleMode === '' ? 'edit' : $clickTitleMode;
        if (isset($this->modTSconfig['tableDisplayOrder'])) {
            $dbList->setTableDisplayOrder($this->modTSconfig['tableDisplayOrder']);
        }

        // Initialize clipboard
        $clipboard = $this->initializeClipboard($request, (bool) $this->moduleData->get('clipBoard'));
        $dbList->clipObj = $clipboard;

        // Dispatch additional content event
        $additionalRecordListEvent = $this->eventDispatcher->dispatch(new RenderAdditionalContentToRecordListEvent($request));

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

        // Render the appropriate view based on view type configuration
        // Built-in types have dedicated render methods, custom types use generic rendering
        $customContent = match ($viewMode) {
            'grid' => $this->renderGridViewContent($request, $pageId, $this->table, $this->searchTerm, $searchLevels),
            'compact' => $this->renderCompactViewContent($request, $pageId, $this->table, $this->searchTerm, $searchLevels),
            'teaser' => $this->renderTeaserViewContent($request, $pageId, $this->table, $this->searchTerm, $searchLevels),
            default => $this->renderGenericViewContent($request, $pageId, $this->table, $this->searchTerm, $searchLevels, $viewMode),
        };

        // Page title
        if (!$this->pageContext->pageId) {
            $title = $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'];
        } else {
            $title = $this->pageContext->getPageTitle();
        }

        // Page translations
        $pageTranslationsHtml = '';
        if ($this->pageContext->pageId && !$this->searchTerm && !$cmd && !$this->table && $this->showPageTranslations()) {
            $pageTranslationsHtml = $this->renderPageTranslations($dbList, $siteLanguages);
        }

        // Search box - use full searchLevels (grid/compact now support search properly)
        $searchBoxHtml = '';
        if ($this->allowSearch && $this->moduleData->get('searchBox')) {
            $searchBoxHtml = $this->renderSearchBox($request, $dbList, $this->searchTerm, $searchLevels);
        }

        // Clipboard
        $clipboardHtml = '';
        if ($this->moduleData->get('clipBoard') && ($customContent || $clipboard->hasElements())) {
            $clipboardHtml = '<hr class="spacer"><typo3-backend-clipboard-panel return-url="' . htmlspecialchars((string) $dbList->listURL()) . '"></typo3-backend-clipboard-panel>';
        }

        // Set page title
        $view->setTitle($languageService->translate('title', 'backend.modules.list'), $title);

        // Add page breadcrumb
        if ($this->pageContext->pageRecord) {
            $view->getDocHeaderComponent()->setPageBreadcrumb($this->pageContext->pageRecord);
        }

        // =========================================================================
        // DocHeader buttons - using parent's method for proper button bar setup
        // =========================================================================
        $this->getDocHeaderButtons($view, $clipboard, $request, $this->table, $dbList->listURL(), []);

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
            $baseUrl = $dbList->listURL('', '-1', 'pointer,searchTerm,displayMode');
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $baseUrl .= $separator . 'displayMode=' . urlencode($viewMode);
        }

        $searchBox = GeneralUtility::makeInstance(RecordSearchBoxComponent::class)
            ->setAllowedSearchLevels((array) ($this->modTSconfig['searchLevel']['items'] ?? []))
            ->setSearchWord($searchWord)
            ->setSearchLevel($searchLevels)
            ->render($request, $baseUrl);

        return $searchBox;
    }

    /**
     * Render the Grid View content (cards only, no frame).
     */
    protected function renderGridViewContent(
        ServerRequestInterface $request,
        int $pageId,
        string $table,
        string $searchTerm,
        int $searchLevels,
    ): string {
        // Get services
        $gridConfigurationService = GeneralUtility::makeInstance(GridConfigurationService::class);
        $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
        $middlewareDiagnosticService = GeneralUtility::makeInstance(MiddlewareDiagnosticService::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $viewModeResolver = GeneralUtility::makeInstance(ViewModeResolver::class);

        // Get current view mode
        $viewMode = $viewModeResolver->getActiveViewMode($request, $pageId);

        // Get sorting parameters from request (per-table sorting)
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody() ?? [];
        // Sort parameters are stored as sort[tableName][field] and sort[tableName][direction]
        $sortParams = (array) ($queryParams['sort'] ?? $parsedBody['sort'] ?? []);
        // Sorting mode parameters: sortingMode[tableName] = 'manual' or 'field'
        $sortingModeParams = (array) ($queryParams['sortingMode'] ?? $parsedBody['sortingMode'] ?? []);

        // Get global grid configuration
        $gridConfig = $gridConfigurationService->getGlobalConfig($pageId);

        // Check for middleware issues
        $middlewareWarning = null;
        $forceListViewUrl = null;
        $diagnosis = $middlewareDiagnosticService->diagnose($request);
        if ($diagnosis['hasRisk']) {
            $middlewareWarning = $middlewareDiagnosticService->getWarningMessage($request);
            $forceListViewUrl = $diagnosis['forceListViewUrl'];
        }

        // Get tables to display
        // Uses TYPO3's native search API with proper workspace support
        $tablesToRender = $this->getSearchableTables($pageId, $table, $searchTerm, $searchLevels, $request);

        // Collect all records grouped by table
        $tableData = [];
        foreach ($tablesToRender as $tableName) {
            $tableConfig = $gridConfigurationService->getTableConfig($tableName, $pageId);

            // Check if table has sortby field for manual sorting
            $tcaCtrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? [];
            $sortbyFieldName = $tcaCtrl['sortby'] ?? '';
            $hasSortbyField = !empty($sortbyFieldName);

            // Get per-table sorting mode (manual or field)
            $sortingMode = (string) ($sortingModeParams[$tableName] ?? '');
            // Default to 'manual' if table has sortby field and no custom sort is set
            if ($sortingMode === '' && $hasSortbyField) {
                $sortingMode = 'manual';
            } elseif ($sortingMode === '') {
                $sortingMode = 'field';
            }

            // Get per-table sorting parameters
            $tableSortParams = $sortParams[$tableName] ?? [];
            $sortField = (string) ($tableSortParams['field'] ?? '');
            $sortDirection = (string) ($tableSortParams['direction'] ?? 'asc');
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            // When in manual sorting mode, use the sortby field
            if ($sortingMode === 'manual' && $hasSortbyField) {
                $sortField = $sortbyFieldName;
            }

            // Use DatabaseRecordList's query builder for search (handles searchLevels properly)
            // This uses the same API as the core list view for proper workspace and search support
            if ($searchTerm !== '') {
                $records = $this->getRecordsUsingDbList($request, $tableName, $tableConfig, $pageId, $searchTerm, $searchLevels);
            } else {
                $records = $recordGridDataProvider->getRecordsForTable($tableName, $pageId, 100, 0, $searchTerm, $sortField, $sortDirection);
            }

            if (!empty($records)) {
                $recordCount = count($records);
                $isSingleTableMode = ($table !== '');

                // Create action buttons using TYPO3 ComponentFactory API
                $actionButtons = $this->createTableActionButtons(
                    $tableName,
                    $pageId,
                    $viewMode,
                    $request,
                    $recordCount,
                    $isSingleTableMode,
                );

                // Build single table URL (click to show only this table)
                $singleTableUrl = '';
                $clearTableUrl = '';
                try {
                    // URL to show only this table
                    $singleTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'table' => $tableName,
                        'displayMode' => $viewMode,
                    ]);
                    // URL to clear table filter (back to all tables)
                    $clearTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'displayMode' => $viewMode,
                    ]);
                } catch (Exception $e) {
                    // Ignore if route not found
                }

                // Get columns to display (same as list view)
                $displayColumns = $this->getDisplayColumns($tableName);

                // Enrich each record with display values for all columns
                $enrichedRecords = $this->enrichRecordsWithDisplayValues($records, $displayColumns, $tableName);

                // Get sortable fields for this table
                $sortableFields = $recordGridDataProvider->getSortableFields($tableName);

                // Create sorting dropdown using TYPO3's native ComponentFactory API
                $sortingDropdownHtml = $this->createSortingDropdown(
                    $tableName,
                    $sortableFields,
                    $sortField,
                    $sortDirection,
                    $pageId,
                    $viewMode,
                    $request,
                );

                // Table identifier for collapse state
                $tableIdentifier = $tableName;

                // Drag-and-drop reordering is allowed when in manual sorting mode
                $canReorder = $sortingMode === 'manual' && $hasSortbyField;

                // Create sorting mode toggle if table supports manual sorting
                // Include sorting dropdown integrated into the toggle
                $sortingModeToggleHtml = '';
                if ($hasSortbyField) {
                    // Show full toggle with Manual/Field modes
                    $sortingModeToggleHtml = $this->createSortingModeToggle(
                        $tableName,
                        $sortingMode,
                        $sortDirection,
                        $pageId,
                        $viewMode,
                        $request,
                        $sortingDropdownHtml, // Pass the dropdown to integrate with "Nach Spalte"
                    );
                } elseif (!empty($sortingDropdownHtml)) {
                    // For tables without sortby field, show only the field sorting dropdown
                    $sortingModeToggleHtml = '<div class="gridview-sorting-wrapper me-2">'
                        . '<div class="gridview-sorting-toggle btn-group" role="group">'
                        . '<div class="btn-group" role="group">'
                        . $sortingDropdownHtml
                        . '</div></div></div>';
                }

                $tableData[] = [
                    'tableName' => $tableName,
                    'tableIdentifier' => $tableIdentifier,
                    'tableLabel' => $this->getTableLabel($tableName),
                    'tableIcon' => $this->getTableIcon($tableName),
                    'tableConfig' => $tableConfig,
                    'records' => $enrichedRecords,
                    'recordCount' => $recordCount,
                    // Last record UID for end dropzone (drag after last item)
                    'lastRecordUid' => !empty($enrichedRecords) ? (string) $enrichedRecords[array_key_last($enrichedRecords)]['uid'] : '',
                    // Action buttons rendered via TYPO3 API
                    'actionButtons' => $actionButtons,
                    // Sorting dropdown rendered via TYPO3 API
                    'sortingDropdownHtml' => $sortingDropdownHtml,
                    // Sorting mode toggle (Manual / Field)
                    'sortingModeToggleHtml' => $sortingModeToggleHtml,
                    'singleTableUrl' => $singleTableUrl,
                    'clearTableUrl' => $clearTableUrl,
                    'displayColumns' => $displayColumns,
                    'isFiltered' => $isSingleTableMode && $table === $tableName,
                    'canReorder' => $canReorder,
                    'sortField' => $sortField,
                    'sortDirection' => $sortDirection,
                    // Sorting mode state
                    'hasSortbyField' => $hasSortbyField,
                    'sortingMode' => $sortingMode,
                    'sortbyFieldName' => $sortbyFieldName,
                ];
            }
        }

        // Add CSS and JS assets
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:records_list_types/Resources/Public/Css/grid-view.css');
        $pageRenderer->loadJavaScriptModule('@webconsulting/records-list-types/GridViewActions.js');
        $pageRenderer->loadJavaScriptModule('@typo3/backend/column-selector-button.js');

        // Create the view using ViewFactory for Grid View content
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: ['EXT:records_list_types/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:records_list_types/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:records_list_types/Resources/Private/Layouts/'],
            request: $request,
        );

        $gridView = $viewFactory->create($viewFactoryData);
        $gridView->assignMultiple([
            'pageId' => $pageId,
            'gridConfig' => $gridConfig,
            'tableData' => $tableData,
            'middlewareWarning' => $middlewareWarning,
            'forceListViewUrl' => $forceListViewUrl,
            'currentTable' => $table,
            'searchTerm' => $searchTerm,
            'viewMode' => $viewMode,
        ]);

        return $gridView->render('GridView');
    }

    /**
     * Render the Compact View content (single-line per record).
     */
    protected function renderCompactViewContent(
        ServerRequestInterface $request,
        int $pageId,
        string $table,
        string $searchTerm,
        int $searchLevels,
    ): string {
        // Get services
        $gridConfigurationService = GeneralUtility::makeInstance(GridConfigurationService::class);
        $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        // Get sorting parameters from request (per-table sorting)
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody() ?? [];
        $sortParams = (array) ($queryParams['sort'] ?? $parsedBody['sort'] ?? []);

        // Get tables to display
        // Uses TYPO3's native search API with proper workspace support
        $tablesToRender = $this->getSearchableTables($pageId, $table, $searchTerm, $searchLevels, $request);

        // Collect all records grouped by table
        $tableData = [];
        foreach ($tablesToRender as $tableName) {
            $tableConfig = $gridConfigurationService->getTableConfig($tableName, $pageId);

            // Get per-table sorting parameters
            $tableSortParams = $sortParams[$tableName] ?? [];
            $sortField = (string) ($tableSortParams['field'] ?? '');
            $sortDirection = (string) ($tableSortParams['direction'] ?? 'asc');
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            // Use DatabaseRecordList's query builder for search (handles searchLevels properly)
            // This uses the same API as the core list view for proper workspace and search support
            if ($searchTerm !== '') {
                $records = $this->getRecordsUsingDbList($request, $tableName, $tableConfig, $pageId, $searchTerm, $searchLevels);
            } else {
                $records = $recordGridDataProvider->getRecordsForTable($tableName, $pageId, 100, 0, $searchTerm, $sortField, $sortDirection);
            }

            if (!empty($records)) {
                $recordCount = count($records);
                $isSingleTableMode = ($table !== '');

                // Create action buttons using TYPO3 ComponentFactory API
                $actionButtons = $this->createTableActionButtons(
                    $tableName,
                    $pageId,
                    'compact',
                    $request,
                    $recordCount,
                    $isSingleTableMode,
                );

                // Build single table URL (click to show only this table)
                $singleTableUrl = '';
                $clearTableUrl = '';
                try {
                    $singleTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'table' => $tableName,
                        'displayMode' => 'compact',
                    ]);
                    $clearTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'displayMode' => 'compact',
                    ]);
                } catch (Exception $e) {
                }

                $displayColumns = $this->getDisplayColumns($tableName);

                // Enrich each record with display values for all columns
                $enrichedRecords = $this->enrichRecordsWithDisplayValues($records, $displayColumns, $tableName);

                // Generate sortable column headers like TYPO3's core list view
                // Each header has a dropdown for asc/desc sorting
                $sortableColumnHeaders = $this->getSortableColumnHeaders(
                    $tableName,
                    $displayColumns,
                    $sortField,
                    $sortDirection,
                    $pageId,
                    'compact',
                    $request,
                );

                // Table identifier for collapse state
                $tableIdentifier = $tableName;

                // Check if drag-and-drop reordering is allowed
                $tcaCtrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? [];
                $hasSortbyField = !empty($tcaCtrl['sortby']);
                $canReorder = $hasSortbyField && $sortField === '';

                $tableData[] = [
                    'tableName' => $tableName,
                    'tableIdentifier' => $tableIdentifier,
                    'tableLabel' => $this->getTableLabel($tableName),
                    'tableIcon' => $this->getTableIcon($tableName),
                    'tableConfig' => $tableConfig,
                    'records' => $enrichedRecords,
                    'recordCount' => $recordCount,
                    // Action buttons rendered via TYPO3 API
                    'actionButtons' => $actionButtons,
                    // Sortable column headers with dropdowns (like core list view)
                    'sortableColumnHeaders' => $sortableColumnHeaders,
                    'singleTableUrl' => $singleTableUrl,
                    'clearTableUrl' => $clearTableUrl,
                    'displayColumns' => $displayColumns,
                    'isFiltered' => $isSingleTableMode && $table === $tableName,
                    'canReorder' => $canReorder,
                    'sortField' => $sortField,
                    'sortDirection' => $sortDirection,
                ];
            }
        }

        // Add CSS and JS
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:records_list_types/Resources/Public/Css/compact-view.css');
        $pageRenderer->loadJavaScriptModule('@webconsulting/records-list-types/GridViewActions.js');
        $pageRenderer->loadJavaScriptModule('@typo3/backend/column-selector-button.js');

        // Create the view
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: ['EXT:records_list_types/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:records_list_types/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:records_list_types/Resources/Private/Layouts/'],
            request: $request,
        );

        $compactView = $viewFactory->create($viewFactoryData);
        $compactView->assignMultiple([
            'pageId' => $pageId,
            'tableData' => $tableData,
            'currentTable' => $table,
            'searchTerm' => $searchTerm,
            'viewMode' => 'compact',
        ]);

        return $compactView->render('CompactView');
    }

    /**
     * Render the Teaser View content (minimal cards with title, date, teaser).
     *
     * This is a simplified view ideal for news, blog posts, and similar content.
     * Shows fewer fields than the full grid view for a cleaner overview.
     */
    protected function renderTeaserViewContent(
        ServerRequestInterface $request,
        int $pageId,
        string $table,
        string $searchTerm,
        int $searchLevels,
    ): string {
        // Get services
        $gridConfigurationService = GeneralUtility::makeInstance(GridConfigurationService::class);
        $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        // Get sorting parameters from request
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody() ?? [];
        $sortParams = (array) ($queryParams['sort'] ?? $parsedBody['sort'] ?? []);

        // Get tables to display
        $tablesToRender = $this->getSearchableTables($pageId, $table, $searchTerm, $searchLevels, $request);

        // Collect all records grouped by table
        $tableData = [];
        foreach ($tablesToRender as $tableName) {
            $tableConfig = $gridConfigurationService->getTableConfig($tableName, $pageId);

            // Get per-table sorting parameters
            $tableSortParams = $sortParams[$tableName] ?? [];
            $sortField = (string) ($tableSortParams['field'] ?? '');
            $sortDirection = (string) ($tableSortParams['direction'] ?? 'asc');
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            // Use DatabaseRecordList's query builder for search
            if ($searchTerm !== '') {
                $records = $this->getRecordsUsingDbList($request, $tableName, $tableConfig, $pageId, $searchTerm, $searchLevels);
            } else {
                $records = $recordGridDataProvider->getRecordsForTable($tableName, $pageId, 100, 0, $searchTerm, $sortField, $sortDirection);
            }

            if (!empty($records)) {
                $recordCount = count($records);
                $isSingleTableMode = ($table !== '');

                // Create action buttons
                $actionButtons = $this->createTableActionButtons(
                    $tableName,
                    $pageId,
                    'teaser',
                    $request,
                    $recordCount,
                    $isSingleTableMode,
                );

                // Build table URLs
                $singleTableUrl = '';
                $clearTableUrl = '';
                try {
                    $singleTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'table' => $tableName,
                        'displayMode' => 'teaser',
                    ]);
                    $clearTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'displayMode' => 'teaser',
                    ]);
                } catch (Exception $e) {
                }

                // Get display columns - teaser view shows fewer fields
                $displayColumns = $this->getTeaserDisplayColumns($tableName);

                // Enrich each record with display values
                $enrichedRecords = $this->enrichRecordsWithDisplayValues($records, $displayColumns, $tableName);

                // Get sortable fields
                $sortableFields = $recordGridDataProvider->getSortableFields($tableName);

                // Create sorting dropdown using TYPO3's native ComponentFactory API
                $sortingDropdownHtml = $this->createSortingDropdown(
                    $tableName,
                    $sortableFields,
                    $sortField,
                    $sortDirection,
                    $pageId,
                    'teaser',
                    $request,
                );

                // Table identifier
                $tableIdentifier = $tableName;

                // Check if reordering is allowed
                $tcaCtrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? [];
                $hasSortbyField = !empty($tcaCtrl['sortby']);
                $canReorder = $hasSortbyField && $sortField === '';

                $tableData[] = [
                    'tableName' => $tableName,
                    'tableIdentifier' => $tableIdentifier,
                    'tableLabel' => $this->getTableLabel($tableName),
                    'tableIcon' => $this->getTableIcon($tableName),
                    'tableConfig' => $tableConfig,
                    'records' => $enrichedRecords,
                    'recordCount' => $recordCount,
                    'actionButtons' => $actionButtons,
                    'sortingDropdownHtml' => $sortingDropdownHtml,
                    'singleTableUrl' => $singleTableUrl,
                    'clearTableUrl' => $clearTableUrl,
                    'displayColumns' => $displayColumns,
                    'isFiltered' => $isSingleTableMode && $table === $tableName,
                    'canReorder' => $canReorder,
                    'sortField' => $sortField,
                    'sortDirection' => $sortDirection,
                ];
            }
        }

        // Add CSS and JS
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:records_list_types/Resources/Public/Css/teaser-view.css');
        $pageRenderer->loadJavaScriptModule('@webconsulting/records-list-types/GridViewActions.js');

        // Create the view
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: ['EXT:records_list_types/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:records_list_types/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:records_list_types/Resources/Private/Layouts/'],
            request: $request,
        );

        $teaserView = $viewFactory->create($viewFactoryData);
        $teaserView->assignMultiple([
            'pageId' => $pageId,
            'tableData' => $tableData,
            'currentTable' => $table,
            'searchTerm' => $searchTerm,
            'viewMode' => 'teaser',
        ]);

        return $teaserView->render('TeaserView');
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
        $tca = $GLOBALS['TCA'][$tableName] ?? [];
        $ctrl = $tca['ctrl'] ?? [];
        $tcaColumns = $tca['columns'] ?? [];

        // 1. Label field (title) - always first
        $labelField = $ctrl['label'] ?? 'uid';
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
        // Fall back to crdate if available
        if ($dateField === null && !empty($ctrl['crdate'])) {
            $dateField = $ctrl['crdate'];
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
                break; // Only include first matching teaser field
            }
        }

        return $columns;
    }

    /**
     * Render a generic/custom view type using ViewTypeRegistry configuration.
     *
     * This method handles custom view types registered via TSconfig.
     * It uses the view type configuration to determine templates, CSS, and columns.
     *
     * @param ServerRequestInterface $request The current request
     * @param int $pageId The current page ID
     * @param string $table The specific table filter (empty for all)
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth
     * @param string $viewMode The view type identifier
     * @return string Rendered HTML content
     */
    protected function renderGenericViewContent(
        ServerRequestInterface $request,
        int $pageId,
        string $table,
        string $searchTerm,
        int $searchLevels,
        string $viewMode,
    ): string {
        // Get view type configuration
        $viewConfig = $this->getViewTypeRegistry()->getViewType($viewMode, $pageId);

        // Fallback to grid if type not found
        if ($viewConfig === null) {
            return $this->renderGridViewContent($request, $pageId, $table, $searchTerm, $searchLevels);
        }

        // Get services
        $gridConfigurationService = GeneralUtility::makeInstance(GridConfigurationService::class);
        $recordGridDataProvider = GeneralUtility::makeInstance(RecordGridDataProvider::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        // Get sorting parameters
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody() ?? [];
        $sortParams = (array) ($queryParams['sort'] ?? $parsedBody['sort'] ?? []);

        // Get tables to display
        $tablesToRender = $this->getSearchableTables($pageId, $table, $searchTerm, $searchLevels, $request);

        // Get column configuration from view type
        $columnsConfig = $this->getViewTypeRegistry()->getDisplayColumnsConfig($viewMode, $pageId);

        // Collect all records grouped by table
        $tableData = [];
        foreach ($tablesToRender as $tableName) {
            $tableConfig = $gridConfigurationService->getTableConfig($tableName, $pageId);

            // Get per-table sorting parameters
            $tableSortParams = $sortParams[$tableName] ?? [];
            $sortField = (string) ($tableSortParams['field'] ?? '');
            $sortDirection = (string) ($tableSortParams['direction'] ?? 'asc');
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            // Get records using the standard method
            if ($searchTerm !== '') {
                $records = $this->getRecordsUsingDbList($request, $tableName, $tableConfig, $pageId, $searchTerm, $searchLevels);
            } else {
                $records = $recordGridDataProvider->getRecordsForTable($tableName, $pageId, 100, 0, $searchTerm, $sortField, $sortDirection);
            }

            if (!empty($records)) {
                $recordCount = count($records);
                $isSingleTableMode = ($table !== '');

                // Create action buttons
                $actionButtons = $this->createTableActionButtons(
                    $tableName,
                    $pageId,
                    $viewMode,
                    $request,
                    $recordCount,
                    $isSingleTableMode,
                );

                // Build table URLs
                $singleTableUrl = '';
                $clearTableUrl = '';
                try {
                    $singleTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'table' => $tableName,
                        'displayMode' => $viewMode,
                    ]);
                    $clearTableUrl = (string) $uriBuilder->buildUriFromRoute('records', [
                        'id' => $pageId,
                        'displayMode' => $viewMode,
                    ]);
                } catch (Exception $e) {
                }

                // Get display columns based on view type configuration
                if ($columnsConfig['fromTCA']) {
                    $displayColumns = $this->getDisplayColumns($tableName);
                } elseif (!empty($columnsConfig['columns'])) {
                    $displayColumns = $this->getSpecificDisplayColumns($tableName, $columnsConfig['columns']);
                } else {
                    $displayColumns = $this->getTeaserDisplayColumns($tableName);
                }

                // Enrich records with display values
                $enrichedRecords = $this->enrichRecordsWithDisplayValues($records, $displayColumns, $tableName);

                // Get sortable fields
                $sortableFields = $recordGridDataProvider->getSortableFields($tableName);

                // Create sorting dropdown using TYPO3's native ComponentFactory API
                $sortingDropdownHtml = $this->createSortingDropdown(
                    $tableName,
                    $sortableFields,
                    $sortField,
                    $sortDirection,
                    $pageId,
                    $viewMode,
                    $request,
                );

                // Check if reordering is allowed
                $tcaCtrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? [];
                $hasSortbyField = !empty($tcaCtrl['sortby']);
                $canReorder = $hasSortbyField && $sortField === '';

                $tableData[] = [
                    'tableName' => $tableName,
                    'tableIdentifier' => $tableName,
                    'tableLabel' => $this->getTableLabel($tableName),
                    'tableIcon' => $this->getTableIcon($tableName),
                    'tableConfig' => $tableConfig,
                    'records' => $enrichedRecords,
                    'recordCount' => $recordCount,
                    'actionButtons' => $actionButtons,
                    'sortingDropdownHtml' => $sortingDropdownHtml,
                    'singleTableUrl' => $singleTableUrl,
                    'clearTableUrl' => $clearTableUrl,
                    'displayColumns' => $displayColumns,
                    'isFiltered' => $isSingleTableMode && $table === $tableName,
                    'canReorder' => $canReorder,
                    'sortField' => $sortField,
                    'sortDirection' => $sortDirection,
                ];
            }
        }

        // Add CSS and JS from view type configuration
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);

        foreach ($this->getViewTypeRegistry()->getCssFiles($viewMode, $pageId) as $cssFile) {
            $pageRenderer->addCssFile($cssFile);
        }

        foreach ($this->getViewTypeRegistry()->getJsModules($viewMode, $pageId) as $jsModule) {
            $pageRenderer->loadJavaScriptModule($jsModule);
        }

        // Get template configuration
        $templatePaths = $this->getViewTypeRegistry()->getTemplatePaths($viewMode, $pageId);

        // Create the view
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
        ]);

        return $view->render($templatePaths['template']);
    }

    /**
     * Get specific display columns by field names.
     *
     * @param string $tableName The table name
     * @param array $fieldNames Array of field names to include
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    protected function getSpecificDisplayColumns(string $tableName, array $fieldNames): array
    {
        $columns = [];
        $tca = $GLOBALS['TCA'][$tableName] ?? [];
        $ctrl = $tca['ctrl'] ?? [];
        $tcaColumns = $tca['columns'] ?? [];
        $labelField = $ctrl['label'] ?? 'uid';

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
                if ($field === 'datetime' && !empty($ctrl['crdate'])) {
                    $field = $ctrl['crdate'];
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
     * Get the list of tables to render.
     */
    protected function getTablesToRender(int $pageId, string $specificTable, RecordGridDataProvider $dataProvider): array
    {
        if ($specificTable !== '') {
            return [$specificTable];
        }

        // Get all tables that have records on this page
        $tables = [];
        foreach ($GLOBALS['TCA'] as $tableName => $tca) {
            // Skip hidden tables
            if (isset($tca['ctrl']['hideTable']) && $tca['ctrl']['hideTable']) {
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
        $hideTables = GeneralUtility::trimExplode(',', $this->modTSconfig['hideTables'] ?? '', true);

        foreach ($GLOBALS['TCA'] as $tableName => $tca) {
            // Skip hidden tables
            if (isset($tca['ctrl']['hideTable']) && $tca['ctrl']['hideTable']) {
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
     * @param array $tableConfig The grid configuration for this table
     * @param int $pageId The current page ID
     * @param string $searchTerm The search term
     * @param int $searchLevels The search depth level
     * @return array<int, array<string, mixed>> Array of enriched record data
     */
    protected function getRecordsUsingDbList(
        ServerRequestInterface $request,
        string $tableName,
        array $tableConfig,
        int $pageId,
        string $searchTerm,
        int $searchLevels,
    ): array {
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $records = [];

        try {
            // Create a properly initialized DatabaseRecordList for this table
            $dbList = $this->createDatabaseRecordListForTable($tableName, $pageId, $searchTerm, $searchLevels, $request);

            // Use DatabaseRecordList's query builder which handles search properly
            // This is the same API the core list view uses
            $queryBuilder = $dbList->getQueryBuilder($tableName, ['*'], true, 0, 100);
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
                $titleField = $tableConfig['titleField'] ?? ($GLOBALS['TCA'][$tableName]['ctrl']['label'] ?? 'uid');
                $title = $row[$titleField] ?? '[No title]';
                if (is_array($title)) {
                    $title = reset($title);
                }

                // Get icon identifier
                $icon = $iconFactory->getIconForRecord($tableName, $row, \TYPO3\CMS\Core\Imaging\IconSize::SMALL);
                $iconIdentifier = $icon->getIdentifier();

                // Check hidden status
                $hiddenField = $GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled'] ?? null;
                $hidden = $hiddenField ? (bool) ($row[$hiddenField] ?? false) : false;

                $records[] = [
                    'uid' => $uid,
                    'pid' => $recordPid,
                    'tableName' => $tableName,
                    'title' => (string) $title,
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

                $downloadButton = $componentFactory->createLinkButton()
                    ->setHref($downloadUrl)
                    ->setTitle($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_alt_doc.xlf:export') ?: 'Download')
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
                htmlspecialchars($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:showColumnsSelection')),
                htmlspecialchars($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:updateColumnView') ?: 'Update'),
                htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.cancel') ?: 'Cancel'),
                htmlspecialchars($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:updateColumnView.error') ?: 'Error'),
                $iconFactory->getIcon('actions-options', IconSize::SMALL)->render(),
                htmlspecialchars($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_column_selector.xlf:showColumns') ?: 'Show columns'),
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
    protected function createSortingDropdown(
        string $tableName,
        array $sortableFields,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): string {
        if (empty($sortableFields)) {
            return '';
        }

        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $lang = $this->getLanguageService();

        // Determine current icon based on direction
        $sortIcon = $currentSortDirection === 'desc' ? 'actions-sort-amount-down' : 'actions-sort-amount-up';

        // Get label for "Nach Spalte" - show current field name if selected
        $fieldLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.field') ?: 'Nach Spalte';
        if ($currentSortField) {
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

            if (empty($fieldName)) {
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
        $ascLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.ascending') ?: 'Aufsteigend';
        $descLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.descending') ?: 'Absteigend';

        try {
            // Ascending option
            $ascParams = $baseParams;
            $ascParams['sortingMode'][$tableName] = 'field';
            if ($currentSortField) {
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
            if ($currentSortField) {
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
        $manualLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.manual') ?: 'Manual Sorting';
        $fieldLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.field') ?: 'Field Sorting';
        $manualTitle = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.manual.title') ?: 'Enable drag-and-drop reordering';
        $fieldTitle = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.field.title') ?: 'Sort by selected field';
        $ascLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.ascending') ?: 'Ascending';
        $descLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sort.descending') ?: 'Descending';

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
            // Clear any custom sort field when switching to manual
            unset($manualParams['sort'][$tableName]['field']);
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
        $headingLabel = $lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:sortingMode.label') ?: 'Order';

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
        if ($isFieldActive && !empty($sortingDropdownHtml)) {
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
        $ascLabel = $lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.sorting.asc') ?: 'Ascending';
        $descLabel = $lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.sorting.desc') ?: 'Descending';

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
        $tca = $GLOBALS['TCA'][$tableName] ?? [];
        $tcaColumns = $tca['columns'] ?? [];
        $ctrl = $tca['ctrl'] ?? [];
        $labelField = $ctrl['label'] ?? 'uid';

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

            if (empty($field)) {
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
        $label = $GLOBALS['TCA'][$tableName]['ctrl']['title'] ?? $tableName;

        if (str_starts_with($label, 'LLL:')) {
            $label = $GLOBALS['LANG']->sL($label) ?: $tableName;
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
        return $icon ?: 'mimetypes-x-content-text';
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
        $tca = $GLOBALS['TCA'][$tableName] ?? [];
        $ctrl = $tca['ctrl'] ?? [];
        $tcaColumns = $tca['columns'] ?? [];
        $backendUser = $this->getBackendUserAuthentication();

        // Get the label field (title) - always first
        $labelField = $ctrl['label'] ?? 'uid';

        // Priority 1: User's selected columns from "Show columns" selector
        $displayFields = $backendUser->getModuleData('list/displayFields') ?? [];
        $userSelectedFields = $displayFields[$tableName] ?? [];

        if (!empty($userSelectedFields)) {
            // User has selected specific columns
            $fieldList = $userSelectedFields;
        } else {
            // Priority 2: TSconfig showFields
            $tableConfig = $this->modTSconfig['table'][$tableName] ?? [];
            $showFields = $tableConfig['showFields'] ?? '';

            if (!empty($showFields)) {
                $fieldList = GeneralUtility::trimExplode(',', $showFields, true);
            } else {
                // Priority 3: TCA searchFields or label field as fallback
                $searchFields = $ctrl['searchFields'] ?? '';
                if (!empty($searchFields)) {
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
        foreach ($fieldList as $field) {
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
            $disabledField = $ctrl['enablecolumns']['disabled'] ?? null;
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
            ]);

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
     */
    protected function getFieldLabel(string $field, array $tcaColumns, array $ctrl): string
    {
        if (isset($tcaColumns[$field]['label'])) {
            $label = $tcaColumns[$field]['label'];
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

        // Check if field matches ctrl fields
        if ($field === ($ctrl['crdate'] ?? null) || $field === 'crdate') {
            return $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.creationDate') ?: 'Created';
        }
        if ($field === ($ctrl['tstamp'] ?? null) || $field === 'tstamp') {
            return $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.timestamp') ?: 'Modified';
        }
        if ($field === ($ctrl['sortby'] ?? null)) {
            return $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.sorting') ?: 'Sorting';
        }

        // Hidden/disabled field
        $disabledField = $ctrl['enablecolumns']['disabled'] ?? null;
        if ($field === $disabledField) {
            return $GLOBALS['LANG']->sL('LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden') ?: 'Hidden';
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
            return $fallback ?: $label;
        }

        // Traditional LLL: format or TYPO3 v12+ translation domain format
        // The LanguageService::sL() method handles both:
        // - LLL:EXT:core/Resources/Private/Language/locallang.xlf:key
        // - domain.name:key (e.g., frontend.db.tt_content:header)
        if (str_starts_with($label, 'LLL:') || str_contains($label, ':')) {
            $translated = $GLOBALS['LANG']->sL($label);
            return $translated !== '' ? $translated : ($fallback ?: $label);
        }

        // Plain string - return as-is
        return $label;
    }

    /**
     * Get the type for a field (for formatting).
     */
    protected function getFieldType(string $field, array $tcaColumns, array $ctrl): string
    {
        // System date fields
        if (in_array($field, ['crdate', 'tstamp'], true)) {
            return 'datetime';
        }

        // Disabled/hidden field
        $disabledField = $ctrl['enablecolumns']['disabled'] ?? null;
        if ($field === $disabledField) {
            return 'boolean';
        }

        // TCA-configured type
        $config = $tcaColumns[$field]['config'] ?? [];
        $type = $config['type'] ?? '';

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
    protected function enrichRecordsWithDisplayValues(array $records, array $displayColumns, string $tableName): array
    {
        $tca = $GLOBALS['TCA'][$tableName] ?? [];
        $tcaColumns = $tca['columns'] ?? [];

        foreach ($records as &$record) {
            $displayValues = [];
            $rawRecord = $record['rawRecord'] ?? [];

            foreach ($displayColumns as $column) {
                $field = $column['field'];
                $type = $column['type'];
                $rawValue = $rawRecord[$field] ?? null;

                $displayValues[$field] = [
                    'field' => $field,
                    'label' => $column['label'],
                    'type' => $type,
                    'isLabelField' => $column['isLabelField'] ?? false,
                    'raw' => $rawValue,
                    'formatted' => $this->formatFieldValue($rawValue, $type, $field, $tcaColumns, $tableName),
                    'isEmpty' => $rawValue === null || $rawValue === '' || $rawValue === 0 || $rawValue === '0',
                ];
            }

            $record['displayValues'] = $displayValues;
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
    protected function formatFieldValue(mixed $value, string $type, string $field, array $tcaColumns, string $tableName): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        switch ($type) {
            case 'boolean':
                return $value ? 'yes' : 'no';

            case 'datetime':
                if (is_numeric($value) && $value > 0) {
                    return date('d.m.Y H:i', (int) $value);
                }
                return (string) $value;

            case 'number':
                return (string) $value;

            case 'select':
                // Try to resolve select value to label
                $config = $tcaColumns[$field]['config'] ?? [];
                $items = $config['items'] ?? [];
                foreach ($items as $item) {
                    // TYPO3 v12+ item format: ['label' => ..., 'value' => ...]
                    $itemValue = $item['value'] ?? $item[1] ?? null;
                    $itemLabel = $item['label'] ?? $item[0] ?? '';
                    if ((string) $itemValue === (string) $value) {
                        if (str_starts_with($itemLabel, 'LLL:')) {
                            return $GLOBALS['LANG']->sL($itemLabel) ?: (string) $value;
                        }
                        return $itemLabel;
                    }
                }
                return (string) $value;

            case 'relation':
                // For relations, just show count or basic info
                if (is_numeric($value)) {
                    return $value > 0 ? $value . ' item(s)' : '';
                }
                return (string) $value;

            default:
                // Text - strip HTML and limit length
                $text = strip_tags(html_entity_decode((string) $value));
                $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace
                $text = trim($text);
                return $text;
        }
    }
}
