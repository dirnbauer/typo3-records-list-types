<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Resolves normalized TCA ctrl/columns configuration for record-list views.
 *
 * This is the single place where a TCA field is turned into a human readable,
 * localized column label. Both the grid/compact/teaser column lists and the
 * sorting dropdown go through {@see self::getFieldLabel()}.
 */
final class TcaTableConfigurationService implements SingletonInterface
{
    /**
     * Label references for columns that TYPO3 adds to every schema.
     *
     * The two identifier columns use this extension's own short labels so that
     * a card footer badge, a column header and the sorting dropdown all say the
     * same word; core's `labels.uid` ("Unique ID") is Info-panel wording.
     *
     * @var array<string, string>
     */
    private const SYSTEM_FIELD_LABELS = [
        'uid' => 'records_list_types.messages:record.idLabel',
        'pid' => 'records_list_types.messages:record.pageLabel',
        'crdate' => 'core.general:LGL.creationDate',
        'tstamp' => 'core.general:LGL.timestamp',
    ];

    /**
     * Label references for columns a table declares through `ctrl`. The key is
     * the `ctrl` path, the value the label reference.
     *
     * @var array<string, string>
     */
    private const CTRL_FIELD_LABELS = [
        'sortby' => 'core.core:labels.sorting',
        'crdate' => 'core.general:LGL.creationDate',
        'tstamp' => 'core.general:LGL.timestamp',
        'languageField' => 'core.general:LGL.language',
        'transOrigPointerField' => 'core.general:LGL.l18n_parent',
    ];

    /**
     * Label references for the `ctrl.enablecolumns` fields.
     *
     * @var array<string, string>
     */
    private const ENABLE_COLUMN_LABELS = [
        'disabled' => 'core.general:LGL.hidden',
        'starttime' => 'core.general:LGL.starttime',
        'endtime' => 'core.general:LGL.endtime',
        'fe_group' => 'core.general:LGL.fe_group',
    ];

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
        // A table may rename a system column (tt_address labels `hidden` as
        // "Enabled"), so its own label wins — but only when it carries meaning.
        // TYPO3 v14 adds system columns to every schema with the bare field
        // name as their label, and that must not end up on screen.
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        $rawLabel = is_string($fieldDef['label'] ?? null) ? $fieldDef['label'] : '';
        if ($rawLabel !== '' && strcasecmp($rawLabel, $field) !== 0) {
            $ownLabel = $this->translateTcaLabel($rawLabel);
            if ($ownLabel !== '') {
                return $ownLabel;
            }
        }

        $reference = $this->resolveSystemLabelReference($field, $ctrl);
        if ($reference !== null) {
            $translated = $this->translateTcaLabel($reference);
            if ($translated !== '') {
                return $translated;
            }
        }

        return $field;
    }

    /**
     * Translate a TCA label reference (`LLL:EXT:…` or a v14 `domain:key`).
     *
     * `LanguageService::sL()` echoes its input back when a reference cannot be
     * resolved, which is how a reference to a label that does not exist used to
     * reach the UI verbatim. An unchanged reference counts as unresolved.
     */
    public function translateTcaLabel(string $label, string $fallback = ''): string
    {
        if ($label === '') {
            return $fallback;
        }

        if (!$this->isLabelReference($label)) {
            return $label;
        }

        $langService = $this->getLanguageService();
        $translated = $langService instanceof LanguageService ? $langService->sL($label) : '';
        if ($translated === '' || $translated === $label) {
            return $fallback;
        }

        return $translated;
    }

    /**
     * @param array<string, mixed> $ctrl
     */
    private function resolveSystemLabelReference(string $field, array $ctrl): ?string
    {
        foreach (self::CTRL_FIELD_LABELS as $ctrlKey => $reference) {
            if ($field !== '' && ($ctrl[$ctrlKey] ?? null) === $field) {
                return $reference;
            }
        }

        $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        foreach (self::ENABLE_COLUMN_LABELS as $enableKey => $reference) {
            if ($field !== '' && ($enableColumns[$enableKey] ?? null) === $field) {
                return $reference;
            }
        }

        return self::SYSTEM_FIELD_LABELS[$field] ?? null;
    }

    private function isLabelReference(string $label): bool
    {
        return str_starts_with($label, 'LLL:')
            || preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*:[A-Za-z0-9_.\-]+$/', $label) === 1;
    }

    /**
     * @param array<string, mixed> $tcaColumns
     * @param array<string, mixed> $ctrl
     */
    public function getFieldType(string $field, array $tcaColumns, array $ctrl): string
    {
        if (in_array($field, ['crdate', 'tstamp', $ctrl['crdate'] ?? null, $ctrl['tstamp'] ?? null], true)) {
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
