<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves which TCA fields to show in grid, compact, and teaser views.
 */
final readonly class RecordDisplayColumnResolver implements SingletonInterface
{
    public function __construct(
        private TcaTableConfigurationService $tcaConfigurationService,
    ) {}

    /**
     * @param array<string, mixed> $modTsConfig
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    public function getDisplayColumns(string $tableName, array $modTsConfig): array
    {
        $columns = [];
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $ctrl = $tcaForTable['ctrl'];
        $tcaColumns = $tcaForTable['columns'];
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';

        $displayFields = $backendUser->getModuleData('list/displayFields');
        $displayFieldsArray = is_array($displayFields) ? $displayFields : [];
        $userSelectedFields = is_array($displayFieldsArray[$tableName] ?? null) ? $displayFieldsArray[$tableName] : [];

        if ($userSelectedFields !== []) {
            $fieldList = $userSelectedFields;
        } else {
            $modTableConfig = is_array($modTsConfig['table'] ?? null) ? $modTsConfig['table'] : [];
            $tableConfigArr = is_array($modTableConfig[$tableName] ?? null) ? $modTableConfig[$tableName] : [];
            $showFieldsVal = $tableConfigArr['showFields'] ?? '';
            $showFields = is_string($showFieldsVal) ? $showFieldsVal : '';

            if ($showFields !== '') {
                $fieldList = GeneralUtility::trimExplode(',', $showFields, true);
            } else {
                $searchFieldsVal = $ctrl['searchFields'] ?? '';
                $searchFields = is_string($searchFieldsVal) ? $searchFieldsVal : '';
                if ($searchFields !== '') {
                    $fieldList = GeneralUtility::trimExplode(',', $searchFields, true);
                } else {
                    $fieldList = [$labelField];
                }
            }
        }

        if (!in_array($labelField, $fieldList, true)) {
            array_unshift($fieldList, $labelField);
        }

        foreach ($fieldList as $rawField) {
            $field = is_string($rawField) ? $rawField : '';
            if ($field === '') {
                continue;
            }
            $skipFields = [
                't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage',
                'l10n_parent', 'l10n_source', 'l10n_diffsource', 'l10n_state',
                'sys_language_uid',
            ];
            if (in_array($field, $skipFields, true)) {
                continue;
            }

            $coreSystemFields = ['uid', 'pid'];
            $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
            $disabledField = $enableColumns['disabled'] ?? null;
            $sortbyField = $ctrl['sortby'] ?? null;
            $crdateField = $ctrl['crdate'] ?? null;
            $tstampField = $ctrl['tstamp'] ?? null;

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

            $columns[] = [
                'field' => $field,
                'label' => $this->tcaConfigurationService->getFieldLabel($field, $tcaColumns, $ctrl),
                'type' => $this->tcaConfigurationService->getFieldType($field, $tcaColumns, $ctrl),
                'isLabelField' => ($field === $labelField),
            ];
        }

        return $columns;
    }

    /**
     * @param array<int, string> $fieldNames
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    public function getSpecificDisplayColumns(string $tableName, array $fieldNames): array
    {
        $columns = [];
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $ctrl = $tcaForTable['ctrl'];
        $tcaColumns = $tcaForTable['columns'];
        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';

        foreach ($fieldNames as $field) {
            if ($field === 'label') {
                $field = $labelField;
            } elseif ($field === 'datetime' || $field === 'date') {
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
                $teaserFields = ['teaser', 'abstract', 'description', 'bodytext', 'short'];
                foreach ($teaserFields as $tf) {
                    if (isset($tcaColumns[$tf])) {
                        $field = $tf;
                        break;
                    }
                }
            }

            if (!isset($tcaColumns[$field]) && !in_array($field, ['uid', 'pid', $ctrl['crdate'] ?? '', $ctrl['tstamp'] ?? ''], true)) {
                continue;
            }

            $columns[] = [
                'field' => $field,
                'label' => $this->tcaConfigurationService->getFieldLabel($field, $tcaColumns, $ctrl),
                'type' => $this->tcaConfigurationService->getFieldType($field, $tcaColumns, $ctrl),
                'isLabelField' => ($field === $labelField),
            ];
        }

        return $columns;
    }

    /**
     * @return array<int, array{field: string, label: string, type: string, isLabelField: bool}>
     */
    public function getTeaserDisplayColumns(string $tableName): array
    {
        $columns = [];
        $tcaForTable = $this->tcaConfigurationService->getTcaForTable($tableName);
        $ctrl = $tcaForTable['ctrl'];
        $tcaColumns = $tcaForTable['columns'];

        $labelVal = $ctrl['label'] ?? 'uid';
        $labelField = is_string($labelVal) ? $labelVal : 'uid';
        if (isset($tcaColumns[$labelField])) {
            $columns[] = [
                'field' => $labelField,
                'label' => $this->tcaConfigurationService->getFieldLabel($labelField, $tcaColumns, $ctrl),
                'type' => 'text',
                'isLabelField' => true,
            ];
        }

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
                'label' => $this->tcaConfigurationService->getFieldLabel($dateField, $tcaColumns, $ctrl),
                'type' => 'datetime',
                'isLabelField' => false,
            ];
        }

        $teaserFields = ['teaser', 'abstract', 'description', 'bodytext', 'short'];
        foreach ($teaserFields as $field) {
            if (isset($tcaColumns[$field]) && $field !== $labelField) {
                $columns[] = [
                    'field' => $field,
                    'label' => $this->tcaConfigurationService->getFieldLabel($field, $tcaColumns, $ctrl),
                    'type' => 'text',
                    'isLabelField' => false,
                ];
                break;
            }
        }

        return $columns;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
