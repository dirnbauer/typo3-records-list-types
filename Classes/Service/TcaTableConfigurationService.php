<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Resolves normalized TCA ctrl/columns configuration for record-list views.
 */
final class TcaTableConfigurationService implements SingletonInterface
{
    /**
     * @return array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>}
     */
    public function getTcaForTable(string $tableName): array
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
     * @param array<string, mixed> $tcaColumns
     * @param array<string, mixed> $ctrl
     */
    public function getFieldLabel(string $field, array $tcaColumns, array $ctrl): string
    {
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        if (isset($fieldDef['label'])) {
            $labelRawVal = $fieldDef['label'];
            $label = is_string($labelRawVal) ? $labelRawVal : '';

            return $this->translateTcaLabel($label, $field);
        }

        $systemLabels = [
            'uid' => 'UID',
            'pid' => 'Page',
        ];

        if (isset($systemLabels[$field])) {
            return $systemLabels[$field];
        }

        $langService = $this->getLanguageService();
        if (!$langService instanceof LanguageService) {
            return $field;
        }

        if ($field === ($ctrl['crdate'] ?? null) || $field === 'crdate') {
            $translated = $langService->sL('core.general:LGL.creationDate');

            return $translated !== '' ? $translated : 'Created';
        }
        if ($field === ($ctrl['tstamp'] ?? null) || $field === 'tstamp') {
            $translated = $langService->sL('core.general:LGL.timestamp');

            return $translated !== '' ? $translated : 'Modified';
        }
        if ($field === ($ctrl['sortby'] ?? null)) {
            $translated = $langService->sL('core.general:LGL.sorting');

            return $translated !== '' ? $translated : 'Sorting';
        }

        $enableCols = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        $disabledField = $enableCols['disabled'] ?? null;
        if ($field === $disabledField) {
            $translated = $langService->sL('core.general:LGL.hidden');

            return $translated !== '' ? $translated : 'Hidden';
        }

        return $field;
    }

    public function translateTcaLabel(string $label, string $fallback = ''): string
    {
        if ($label === '') {
            return $fallback !== '' ? $fallback : $label;
        }

        if (str_starts_with($label, 'LLL:') || str_contains($label, ':')) {
            $langService = $this->getLanguageService();
            if ($langService instanceof LanguageService) {
                $translated = $langService->sL($label);

                return $translated !== '' ? $translated : ($fallback !== '' ? $fallback : $label);
            }
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $tcaColumns
     * @param array<string, mixed> $ctrl
     */
    public function getFieldType(string $field, array $tcaColumns, array $ctrl): string
    {
        if (in_array($field, ['crdate', 'tstamp'], true)) {
            return 'datetime';
        }

        $enableCols = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        $disabledField = $enableCols['disabled'] ?? null;
        if ($field === $disabledField) {
            return 'boolean';
        }

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

    private function getLanguageService(): ?LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;

        return $lang instanceof LanguageService ? $lang : null;
    }
}
