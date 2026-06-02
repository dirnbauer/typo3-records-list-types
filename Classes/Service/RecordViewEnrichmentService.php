<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

/**
 * Enriches grid/compact/teaser record payloads with display values, language,
 * permissions, and edit URLs for Fluid templates.
 */
final readonly class RecordViewEnrichmentService implements SingletonInterface
{
    public function __construct(
        private TcaTableConfigurationService $tcaConfigurationService,
        private RecordDisplayValueFormatter $displayValueFormatter,
        private RecordListRequestParameterService $requestParameterService,
        private TcaSchemaFactory $tcaSchemaFactory,
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array{field: string, label: string, type: string, isLabelField: bool}> $displayColumns
     * @return array<int, array<string, mixed>>
     */
    public function enrichForAlternativeViews(
        array $records,
        array $displayColumns,
        string $tableName,
        RecordViewEnrichmentContext $context,
    ): array {
        $records = $this->enrichRecordsWithDisplayValues($records, $displayColumns, $tableName, $context);
        $records = $this->enrichRecordsWithLanguageInfo($records, $tableName, $context);
        return $this->enrichRecordsWithPermissions($records, $tableName, $context);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array{field: string, label: string, type: string, isLabelField: bool}> $displayColumns
     * @return array<int, array<string, mixed>>
     */
    public function enrichRecordsWithDisplayValues(
        array $records,
        array $displayColumns,
        string $tableName,
        RecordViewEnrichmentContext $context,
    ): array {
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $tcaColumns = $tcaForTable['columns'];
        $langService = $this->getLanguageService();

        foreach ($records as &$record) {
            $displayValues = [];
            /** @var array<string, mixed> $rawRecord */
            $rawRecord = is_array($record['rawRecord'] ?? null) ? $record['rawRecord'] : [];

            foreach ($displayColumns as $column) {
                $field = $column['field'];
                $type = $column['type'];
                $rawValue = $rawRecord[$field] ?? null;

                $displayRaw = $rawValue;
                if ($type === 'boolean' && $this->displayValueFormatter->shouldInvertBooleanDisplay($field, $tcaColumns)) {
                    $displayRaw = ((bool) $rawValue) ? 0 : 1;
                }
                $isLabelField = $column['isLabelField'] ?? false;

                $displayValues[$field] = [
                    'field' => $field,
                    'label' => $column['label'],
                    'type' => $type,
                    'isLabelField' => $isLabelField,
                    'raw' => $displayRaw,
                    'formatted' => $isLabelField
                        ? BackendUtility::getRecordTitle($tableName, $rawRecord, false, true)
                        : $this->displayValueFormatter->formatFieldValue(
                            $displayRaw,
                            $type,
                            $field,
                            $tcaColumns,
                            static fn(string $label): string => $langService instanceof LanguageService
                                ? $langService->sL($label)
                                : $label,
                        ),
                    'isEmpty' => in_array($rawValue, [null, '', 0, '0'], true),
                ];
            }

            $record['displayValues'] = $displayValues;
            $record = $this->enrichRecordWithEditUrls($record, $context);
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    public function enrichRecordsWithLanguageInfo(
        array $records,
        string $tableName,
        RecordViewEnrichmentContext $context,
    ): array {
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $languageField = $tcaForTable['ctrl']['languageField'] ?? null;

        if (!is_string($languageField) || $languageField === '') {
            return $records;
        }

        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $records;
        }

        $siteLanguages = $context->pageContext->site->getAvailableLanguages(
            $backendUser,
            false,
            $context->pageContext->pageId,
        );

        foreach ($records as &$record) {
            /** @var array<string, mixed> $rawRecord */
            $rawRecord = is_array($record['rawRecord'] ?? null) ? $record['rawRecord'] : [];
            $langUidRaw = $rawRecord[$languageField] ?? 0;
            $langUid = is_numeric($langUidRaw) ? (int) $langUidRaw : 0;

            $record['sysLanguageUid'] = $langUid;
            $record['languageFlagIdentifier'] = '';
            $record['languageTitle'] = '';

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
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    public function enrichRecordsWithPermissions(
        array $records,
        string $tableName,
        RecordViewEnrichmentContext $context,
    ): array {
        foreach ($records as &$record) {
            /** @var array<string, mixed> $raw */
            $raw = is_array($record['rawRecord'] ?? null) ? $record['rawRecord'] : [];
            $record['permissions'] = $this->computeRecordPermissions($tableName, $raw, $context);
        }
        unset($record);

        return $records;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{canEdit:bool,canDelete:bool,canToggleVisibility:bool,canLocalize:bool,canCopy:bool,canHistory:bool,canShowInfo:bool}
     */
    public function computeRecordPermissions(
        string $tableName,
        array $row,
        RecordViewEnrichmentContext $context,
    ): array {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [
                'canEdit' => false,
                'canDelete' => false,
                'canToggleVisibility' => false,
                'canLocalize' => false,
                'canCopy' => false,
                'canHistory' => false,
                'canShowInfo' => true,
            ];
        }

        $defaults = [
            'canEdit' => false,
            'canDelete' => false,
            'canToggleVisibility' => false,
            'canLocalize' => false,
            'canCopy' => false,
            'canHistory' => false,
            'canShowInfo' => true,
        ];

        if (!$this->tcaSchemaFactory->has($tableName)) {
            return $defaults;
        }
        $schema = $this->tcaSchemaFactory->get($tableName);
        $tcaCtrl = $this->tcaConfigurationService->getTcaForTable($tableName)['ctrl'];
        $hiddenField = '';
        $enableColumns = is_array($tcaCtrl['enablecolumns'] ?? null) ? $tcaCtrl['enablecolumns'] : [];
        if (is_string($enableColumns['disabled'] ?? null)) {
            $hiddenField = $enableColumns['disabled'];
        }
        $hasHiddenField = $hiddenField !== '';

        $isDeletePlaceholder = $this->isDeletePlaceholder($row);
        $languageAware = $schema->isLanguageAware();
        $languageId = 0;
        $parentPointer = 0;
        if ($languageAware) {
            $languageField = $schema->getCapability(TcaSchemaCapability::Language)
                ->getLanguageField()->getName();
            $transOrigField = $schema->getCapability(TcaSchemaCapability::Language)
                ->getTranslationOriginPointerField()->getName();
            $languageId = is_numeric($row[$languageField] ?? null) ? (int) $row[$languageField] : 0;
            $parentPointer = is_numeric($row[$transOrigField] ?? null) ? (int) $row[$transOrigField] : 0;
        }

        if ($backendUser->isAdmin()) {
            return [
                'canEdit' => !$isDeletePlaceholder,
                'canDelete' => !$isDeletePlaceholder,
                'canToggleVisibility' => $hasHiddenField && !$isDeletePlaceholder,
                'canLocalize' => $languageAware && $languageId === 0 && $parentPointer === 0 && !$isDeletePlaceholder,
                'canCopy' => !$isDeletePlaceholder,
                'canHistory' => !$isDeletePlaceholder,
                'canShowInfo' => !$isDeletePlaceholder,
            ];
        }

        $schemaReadOnly = $schema->hasCapability(TcaSchemaCapability::AccessReadOnly);
        $tableModify = !$schemaReadOnly && $backendUser->check('tables_modify', $tableName);

        $pagePerms = $this->resolvePagePermission($tableName, $row, $context);
        $pageEdit = $tableName === 'pages'
            ? $pagePerms->editPagePermissionIsGranted()
            : $pagePerms->editContentPermissionIsGranted();
        $pageDelete = $tableName === 'pages'
            ? $pagePerms->deletePagePermissionIsGranted()
            : $pagePerms->editContentPermissionIsGranted();

        $recordAccess = $backendUser->checkRecordEditAccess($tableName, $row)->isAllowed;
        $editLockOk = $this->checkEditLock($tableName, $row, $pageEdit, $context);

        $canEdit = $tableModify && $pageEdit && $recordAccess && $editLockOk && !$isDeletePlaceholder;

        $userTsConfig = $backendUser->getTSConfig();
        $disableDelete = (bool) trim(ArrayUtility::stringValue(
            ArrayUtility::valuePath($userTsConfig, ['options.', 'disableDelete.', $tableName])
                ?? ArrayUtility::valuePath($userTsConfig, ['options.', 'disableDelete']),
        ));

        $canDelete = $canEdit && !$disableDelete && $pageDelete && !$this->isCurrentBackendUser($tableName, $row);

        return [
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'canToggleVisibility' => $canEdit && $hasHiddenField && !$this->isCurrentBackendUser($tableName, $row),
            'canLocalize' => $canEdit && $languageAware && $languageId === 0 && $parentPointer === 0,
            'canCopy' => $tableModify && !$isDeletePlaceholder,
            'canHistory' => !$isDeletePlaceholder,
            'canShowInfo' => !$isDeletePlaceholder,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function enrichRecordWithEditUrls(array $record, RecordViewEnrichmentContext $context): array
    {
        $uidRaw = $record['uid'] ?? null;
        $tableNameRaw = $record['tableName'] ?? null;

        if (!is_numeric($uidRaw) || !is_string($tableNameRaw) || $tableNameRaw === '') {
            return $record;
        }

        $uid = (int) $uidRaw;
        if ($uid <= 0) {
            return $record;
        }

        $returnUrl = $this->buildContextualEditReturnUrl($record, $context);

        try {
            $record['editUrl'] = (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    $tableNameRaw => [
                        $uid => 'edit',
                    ],
                ],
                'module' => 'records',
                'returnUrl' => $returnUrl,
            ]);
            $record['contextualEditUrl'] = (string) $this->uriBuilder->buildUriFromRoute('record_edit_contextual', [
                'edit' => [
                    $tableNameRaw => [
                        $uid => 'edit',
                    ],
                ],
                'module' => 'records',
                'returnUrl' => $returnUrl,
            ]);
        } catch (Exception) {
            $record['editUrl'] = '';
            $record['contextualEditUrl'] = '';
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolvePagePermission(
        string $tableName,
        array $row,
        RecordViewEnrichmentContext $context,
    ): Permission {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new Permission(0);
        }

        if ($tableName === 'pages') {
            return new Permission($backendUser->calcPerms($row));
        }

        $pidRaw = $row['pid'] ?? 0;
        $pid = is_numeric($pidRaw) ? (int) $pidRaw : 0;

        if ($pid === $context->pageContext->pageId) {
            return $context->pageContext->pagePermissions;
        }

        $pageRow = BackendUtility::getRecord('pages', $pid);
        if (!is_array($pageRow)) {
            return new Permission(0);
        }

        return new Permission($backendUser->calcPerms($pageRow));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function checkEditLock(
        string $tableName,
        array $row,
        bool $permissionEdit,
        RecordViewEnrichmentContext $context,
    ): bool {
        if (!$permissionEdit) {
            return false;
        }
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $pagesCtrl = $this->tcaConfigurationService->getTcaForTable('pages')['ctrl'];
        $pageEditLockField = is_string($pagesCtrl['editlock'] ?? null) ? $pagesCtrl['editlock'] : '';
        $pageHasEditLock = false;
        if ($pageEditLockField !== '') {
            $pageRecord = $context->pageContext->pageRecord ?? [];
            $pageHasEditLock = (bool) ($pageRecord[$pageEditLockField] ?? false);
        }

        if ($tableName === 'pages') {
            $ownEditLockField = $pageEditLockField;
            if ($ownEditLockField !== '' && (bool) ($row[$ownEditLockField] ?? false)) {
                return false;
            }

            return true;
        }

        if ($pageHasEditLock) {
            return false;
        }
        $tableCtrl = $this->tcaConfigurationService->getTcaForTable($tableName)['ctrl'];
        $tableEditLockField = is_string($tableCtrl['editlock'] ?? null) ? $tableCtrl['editlock'] : '';
        if ($tableEditLockField !== '' && (bool) ($row[$tableEditLockField] ?? false)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isCurrentBackendUser(string $tableName, array $row): bool
    {
        if ($tableName !== 'be_users') {
            return false;
        }
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        $uidRaw = $row['uid'] ?? 0;
        $uid = is_numeric($uidRaw) ? (int) $uidRaw : 0;
        $currentId = is_numeric($backendUser->user['uid'] ?? null) ? (int) $backendUser->user['uid'] : 0;

        return $uid > 0 && $uid === $currentId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isDeletePlaceholder(array $row): bool
    {
        $stateRaw = $row['t3ver_state'] ?? 0;

        return (is_numeric($stateRaw) ? (int) $stateRaw : 0) === 2;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildContextualEditReturnUrl(array $record, RecordViewEnrichmentContext $context): string
    {
        $params = [
            'id' => $context->pageContext->pageId,
            'displayMode' => $context->viewMode,
        ];

        if ($context->request instanceof ServerRequestInterface) {
            $params = array_replace($params, $this->requestParameterService->getPreservedListParameters($context->request));
        }

        $tableName = $record['tableName'] ?? null;
        if (is_string($tableName) && $tableName !== '') {
            $params['table'] = $tableName;
        }

        try {
            return (string) $this->uriBuilder->buildUriFromRoute('records', $params);
        } catch (Exception) {
            return '';
        }
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    private function getLanguageService(): ?LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;

        return $lang instanceof LanguageService ? $lang : null;
    }
}
