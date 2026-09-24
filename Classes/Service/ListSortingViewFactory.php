<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

/**
 * Builds the view models for everything in a record list heading that changes
 * the order of, or bulk-edits, the listed records: the sorting-mode toggle, the
 * field-sorting dropdown, the sortable column headers and the bulk-edit header.
 *
 * All of it is pure URL and label assembly around
 * {@see RecordListRequestParameterService}, which is why it lives beside the
 * controller instead of inside it.
 */
final readonly class ListSortingViewFactory implements SingletonInterface
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private RecordListRequestParameterService $requestParameterService,
        private TcaSchemaFactory $tcaSchemaFactory,
        private TcaTableConfigurationService $tcaConfigurationService,
    ) {}

    /**
     * Build structured data for the field-sorting dropdown.
     *
     * @param array<int, array{field: string, label: string}> $sortableFields
     * @return array<string, mixed>|null
     */
    public function buildSortingDropdown(
        string $tableName,
        array $sortableFields,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): ?array {
        if ($sortableFields === []) {
            return null;
        }

        $lang = $this->getLanguageService();

        $fieldModeLabel = $lang->sL('records_list_types.messages:sortingMode.field');
        $ascLabel = $lang->sL('records_list_types.messages:sort.ascending');
        $descLabel = $lang->sL('records_list_types.messages:sort.descending');

        $currentFieldLabel = $fieldModeLabel;
        foreach ($sortableFields as $field) {
            if (($field['field'] ?? '') === $currentSortField) {
                $currentFieldLabel = $field['label'] ?? $currentSortField;
                break;
            }
        }

        $requestParameters = $this->requestParameterService;
        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        $baseParams = array_replace($baseParams, $requestParameters->getPreservedListParameters($request));

        try {
            $ascParams = $requestParameters->withSortingMode(
                $requestParameters->withSortParams($baseParams, $tableName, $currentSortField !== '' ? $currentSortField : null, 'asc'),
                $tableName,
                'field',
            );

            $descParams = $requestParameters->withSortingMode(
                $requestParameters->withSortParams($baseParams, $tableName, $currentSortField !== '' ? $currentSortField : null, 'desc'),
                $tableName,
                'field',
            );

            $items = [];
            foreach ($sortableFields as $field) {
                $fieldName = $field['field'] ?? '';
                if ($fieldName === '') {
                    continue;
                }
                $sortParams = $requestParameters->withSortingMode(
                    $requestParameters->withSortParams($baseParams, $tableName, $fieldName, $currentSortDirection),
                    $tableName,
                    'field',
                );
                $items[] = [
                    'field' => $fieldName,
                    'label' => $field['label'] ?? $fieldName,
                    'url' => (string)$this->uriBuilder->buildUriFromRoute('records', $sortParams),
                    'isActive' => $fieldName === $currentSortField,
                ];
            }

            return [
                'fieldModeLabel' => $fieldModeLabel,
                'currentFieldLabel' => $currentFieldLabel,
                'sortIconIdentifier' => $currentSortDirection === 'desc' ? 'actions-sort-amount-down' : 'actions-sort-amount-up',
                'ascLabel' => $ascLabel,
                'descLabel' => $descLabel,
                'ascUrl' => (string)$this->uriBuilder->buildUriFromRoute('records', $ascParams),
                'descUrl' => (string)$this->uriBuilder->buildUriFromRoute('records', $descParams),
                'isAscActive' => $currentSortDirection === 'asc',
                'isDescActive' => $currentSortDirection === 'desc',
                'items' => $items,
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Build structured data for the manual/field sorting toggle.
     *
     * @return array<string, mixed>|null
     */
    public function buildSortingModeToggle(
        string $tableName,
        string $currentMode,
        string $currentDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): ?array {
        $lang = $this->getLanguageService();

        $manualLabel = $lang->sL('records_list_types.messages:sortingMode.manual');
        $fieldLabel = $lang->sL('records_list_types.messages:sortingMode.field');
        $manualTitle = $lang->sL('records_list_types.messages:sortingMode.manual.title');
        $fieldTitle = $lang->sL('records_list_types.messages:sortingMode.field.title');
        $ascLabel = $lang->sL('records_list_types.messages:sort.ascending');
        $descLabel = $lang->sL('records_list_types.messages:sort.descending');
        $headingLabel = $lang->sL('records_list_types.messages:sortingMode.label');

        $requestParameters = $this->requestParameterService;
        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        $baseParams = array_replace($baseParams, $requestParameters->getPreservedListParameters($request));

        try {
            $manualParams = $requestParameters->withSortingMode(
                $requestParameters->withSortParams($baseParams, $tableName, null, $currentDirection),
                $tableName,
                'manual',
            );

            $fieldParams = $requestParameters->withSortingMode($baseParams, $tableName, 'field');

            $ascParams = $requestParameters->withSortingMode(
                $requestParameters->withSortParams($baseParams, $tableName, null, 'asc'),
                $tableName,
                'manual',
            );

            $descParams = $requestParameters->withSortingMode(
                $requestParameters->withSortParams($baseParams, $tableName, null, 'desc'),
                $tableName,
                'manual',
            );

            return [
                'headingLabel' => $headingLabel,
                'manual' => [
                    'label' => $manualLabel,
                    'title' => $manualTitle,
                    'active' => $currentMode === 'manual',
                    'url' => (string)$this->uriBuilder->buildUriFromRoute('records', $manualParams),
                    'stateLabel' => $currentDirection === 'desc' ? $descLabel : $ascLabel,
                    'ascUrl' => (string)$this->uriBuilder->buildUriFromRoute('records', $ascParams),
                    'descUrl' => (string)$this->uriBuilder->buildUriFromRoute('records', $descParams),
                    'ascLabel' => $ascLabel,
                    'descLabel' => $descLabel,
                    'ascActive' => $currentDirection === 'asc',
                    'descActive' => $currentDirection === 'desc',
                ],
                'field' => [
                    'label' => $fieldLabel,
                    'title' => $fieldTitle,
                    'active' => $currentMode === 'field',
                    'url' => (string)$this->uriBuilder->buildUriFromRoute('records', $fieldParams),
                ],
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Build structured data for one sortable compact-view column header.
     *
     * @return array<string, mixed>
     */
    private function buildSortableColumnHeader(
        string $tableName,
        string $field,
        string $label,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
        bool $isSingleTableMode = false,
    ): array {
        $lang = $this->getLanguageService();

        $requestParameters = $this->requestParameterService;
        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        $baseParams = array_replace($baseParams, $requestParameters->getPreservedListParameters($request));

        $isActiveField = ($currentSortField === $field);
        $isAscActive = $isActiveField && $currentSortDirection !== 'desc';
        $isDescActive = $isActiveField && $currentSortDirection === 'desc';
        $ascLabel = $lang->sL('records_list_types.messages:sort.ascending');
        $descLabel = $lang->sL('records_list_types.messages:sort.descending');

        // Native multi-edit for this column: offered only in single-table mode
        // (mirrors core DatabaseRecordList::renderListTableFieldHeader) when
        // the field is editable for the current user. Uses TYPO3's own JS
        // hook — `.t3js-record-edit-multiple` — which walks to the enclosing
        // `[data-table]` element and opens FormEngine for the selected UIDs.
        $canMultiEdit = $isSingleTableMode
            && $field !== 'uid'
            && $this->isTableEditableForUser($tableName)
            && $this->isFieldEditableForUser($tableName, $field);
        $multiEditLabel = '';
        $multiEditColumnsOnly = '';
        $multiEditReturnUrl = '';
        if ($canMultiEdit) {
            $multiEditLabel = (string)($lang->translate('editThisColumn', 'core.mod_web_list', [$label]) ?? '');
            if ($multiEditLabel === '') {
                $multiEditLabel = (string)($lang->translate('action.editColumn', 'records_list_types.messages', ['label' => $label]) ?? '');
            }
            $multiEditColumnsOnly = json_encode([$field], JSON_THROW_ON_ERROR);
            try {
                $multiEditReturnUrl = (string)$this->uriBuilder->buildUriFromRoute('records', $baseParams);
            } catch (\Exception) {
                $multiEditReturnUrl = '';
            }
        }

        try {
            $ascParams = $requestParameters->withColumnSortParams($baseParams, $tableName, $field, 'asc');
            $descParams = $requestParameters->withColumnSortParams($baseParams, $tableName, $field, 'desc');

            return [
                'label' => $label,
                'hasSortUrls' => true,
                'isActiveField' => $isActiveField,
                'iconIdentifier' => $isActiveField
                    ? ($isDescActive ? 'actions-sort-amount-down' : 'actions-sort-amount-up')
                    : 'empty-empty',
                'ascUrl' => (string)$this->uriBuilder->buildUriFromRoute('records', $ascParams),
                'descUrl' => (string)$this->uriBuilder->buildUriFromRoute('records', $descParams),
                'ascLabel' => $ascLabel,
                'descLabel' => $descLabel,
                'isAscActive' => $isAscActive,
                'isDescActive' => $isDescActive,
                'canMultiEdit' => $canMultiEdit,
                'multiEditLabel' => $multiEditLabel,
                'multiEditColumnsOnly' => $multiEditColumnsOnly,
                'multiEditReturnUrl' => $multiEditReturnUrl,
            ];
        } catch (\Exception) {
            return [
                'label' => $label,
                'hasSortUrls' => false,
                'isActiveField' => false,
                'iconIdentifier' => 'empty-empty',
                'ascUrl' => '',
                'descUrl' => '',
                'ascLabel' => $ascLabel,
                'descLabel' => $descLabel,
                'isAscActive' => false,
                'isDescActive' => false,
                'canMultiEdit' => $canMultiEdit,
                'multiEditLabel' => $multiEditLabel,
                'multiEditColumnsOnly' => $multiEditColumnsOnly,
                'multiEditReturnUrl' => $multiEditReturnUrl,
            ];
        }
    }

    /**
     * Build the data payload for the native "Edit shown columns" bulk-edit
     * button in the compact view thead. Returns null when not applicable
     * (multi-table mode, read-only, missing permissions). When shown, the
     * button uses TYPO3's `t3js-record-edit-multiple` JS hook — same code
     * path the standard list view uses.
     *
     * @param array<int, array{field:string,label:string,type:string,isLabelField:bool}> $displayColumns
     * @return array{label:string,columnsOnly:string,returnUrl:string}|null
     */
    public function buildBulkEditHeader(
        string $tableName,
        array $displayColumns,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
        bool $isSingleTableMode,
    ): ?array {
        if (!$isSingleTableMode) {
            return null;
        }
        if (!$this->isTableEditableForUser($tableName)) {
            return null;
        }

        $editableFields = [];
        foreach ($displayColumns as $column) {
            $field = $column['field'] ?? '';
            if ($field === '' || $field === 'uid') {
                continue;
            }
            if (!$this->isFieldEditableForUser($tableName, $field)) {
                continue;
            }
            $editableFields[] = $field;
        }
        if ($editableFields === []) {
            return null;
        }

        $label = $this->getLanguageService()->sL('records_list_types.messages:action.editColumns');

        $baseParams = ['id' => $pageId, 'displayMode' => $viewMode];
        $baseParams = array_replace($baseParams, $this->requestParameterService->getPreservedListParameters($request));

        try {
            $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('records', $baseParams);
        } catch (\Exception) {
            $returnUrl = '';
        }

        try {
            $columnsOnly = json_encode($editableFields, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return [
            'label' => $label,
            'columnsOnly' => $columnsOnly,
            'returnUrl' => $returnUrl,
        ];
    }

    /**
     * Table-level editability (tables_modify + schema not readOnly + workspace
     * write allowed). Mirrors DatabaseRecordList::isEditable at table level.
     */
    private function isTableEditableForUser(string $tableName): bool
    {
        $be = $this->getBackendUserAuthentication();
        if ($be->isAdmin()) {
            return true;
        }
        if (!$this->tcaSchemaFactory->has($tableName)) {
            return false;
        }
        $schema = $this->tcaSchemaFactory->get($tableName);
        if ($schema->hasCapability(TcaSchemaCapability::AccessReadOnly)) {
            return false;
        }
        if (!$be->check('tables_modify', $tableName)) {
            return false;
        }
        if (!$schema->isWorkspaceAware() && !$be->workspaceAllowsLiveEditingInTable($tableName)) {
            return false;
        }
        return true;
    }

    /**
     * Field-level editability — no `readOnly` on the column configuration.
     */
    private function isFieldEditableForUser(string $tableName, string $field): bool
    {
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $columns = $tcaForTable['columns'];
        $columnConfig = is_array($columns[$field] ?? null) ? $columns[$field] : [];
        $config = is_array($columnConfig['config'] ?? null) ? $columnConfig['config'] : [];
        return !(bool)($config['readOnly'] ?? false);
    }

    /**
     * Generate sortable column headers for compact view.
     *
     * @param array<int, array{field: string, label: string, type: string, isLabelField: bool}> $displayColumns
     * @return array<int, array<string, mixed>>
     */
    public function getSortableColumnHeaders(
        string $tableName,
        array $displayColumns,
        string $currentSortField,
        string $currentSortDirection,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
        bool $isSingleTableMode = false,
    ): array {
        $headers = [];
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $tcaColumns = $tcaForTable['columns'];
        $ctrl = $tcaForTable['ctrl'];
        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';

        // Identifier column (always first fixed column)
        $uidLabel = $this->tcaConfigurationService->getFieldLabel('uid', $tcaColumns, $ctrl);
        $headers[] = [
            'field' => 'uid',
            'label' => $uidLabel,
            'header' => $this->buildSortableColumnHeader(
                $tableName,
                'uid',
                $uidLabel,
                $currentSortField,
                $currentSortDirection,
                $pageId,
                $viewMode,
                $request,
                $isSingleTableMode,
            ),
            'isFixed' => true,
            'type' => 'uid',
        ];

        // Title/Label column (second fixed column)
        $labelLabel = $this->tcaConfigurationService->getFieldLabel($labelField, $tcaColumns, $ctrl);
        $headers[] = [
            'field' => $labelField,
            'label' => $labelLabel,
            'header' => $this->buildSortableColumnHeader(
                $tableName,
                $labelField,
                $labelLabel,
                $currentSortField,
                $currentSortDirection,
                $pageId,
                $viewMode,
                $request,
                $isSingleTableMode,
            ),
            'isFixed' => true,
            'type' => 'title',
        ];

        // Dynamic columns
        foreach ($displayColumns as $column) {
            if ($column['isLabelField'] ?? false) {
                continue; // Skip label field, already added
            }

            $field = ArrayUtility::stringValue($column['field'] ?? null);
            $label = ArrayUtility::stringValue($column['label'] ?? null, $field);

            if ($field === '') {
                continue;
            }

            $headers[] = [
                'field' => $field,
                'label' => $label,
                'header' => $this->buildSortableColumnHeader(
                    $tableName,
                    $field,
                    $label,
                    $currentSortField,
                    $currentSortDirection,
                    $pageId,
                    $viewMode,
                    $request,
                    $isSingleTableMode,
                ),
                'isFixed' => false,
                'type' => $column['type'] ?? 'text',
            ];
        }

        return $headers;
    }

    private function getLanguageService(): LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if (!$lang instanceof LanguageService) {
            throw new \RuntimeException('LanguageService is not available.', 1758200001);
        }

        return $lang;
    }

    private function getBackendUserAuthentication(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('BackendUserAuthentication is not available.', 1758200002);
        }

        return $user;
    }
}
