<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

final class RecordDisplayValueFormatter
{
    /**
     * @param array<string, mixed> $tcaColumns
     * @param (callable(string): string)|null $translateLabel
     */
    public function formatFieldValue(
        mixed $value,
        string $type,
        string $field,
        array $tcaColumns,
        ?callable $translateLabel = null,
    ): string {
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
                return $this->formatSelectValue($value, $field, $tcaColumns, $translateLabel);

            case 'relation':
                if (is_numeric($value)) {
                    return $value > 0 ? $value . ' item(s)' : '';
                }
                return is_scalar($value) ? (string) $value : '';

            default:
                $textInput = is_scalar($value) ? (string) $value : '';
                $text = strip_tags(html_entity_decode($textInput));
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;
                return trim($text);
        }
    }

    /**
     * Check if a boolean/check field should invert its display value.
     *
     * @param array<string, mixed> $tcaColumns
     */
    public function shouldInvertBooleanDisplay(string $field, array $tcaColumns): bool
    {
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        $config = is_array($fieldDef['config'] ?? null) ? $fieldDef['config'] : [];

        if (isset($config['invertStateDisplay']) && (bool) $config['invertStateDisplay']) {
            return true;
        }

        $items = is_array($config['items'] ?? null) ? $config['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['invertStateDisplay']) && (bool) $item['invertStateDisplay']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tcaColumns
     * @param (callable(string): string)|null $translateLabel
     */
    private function formatSelectValue(
        mixed $value,
        string $field,
        array $tcaColumns,
        ?callable $translateLabel,
    ): string {
        $fieldDef = is_array($tcaColumns[$field] ?? null) ? $tcaColumns[$field] : [];
        $config = is_array($fieldDef['config'] ?? null) ? $fieldDef['config'] : [];
        $items = is_array($config['items'] ?? null) ? $config['items'] : [];
        $valueStr = is_scalar($value) ? (string) $value : '';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemValue = $item['value'] ?? $item[1] ?? null;
            $itemValueStr = is_scalar($itemValue) ? (string) $itemValue : '';
            if ($itemValueStr !== $valueStr) {
                continue;
            }

            $itemLabelVal = $item['label'] ?? $item[0] ?? $valueStr;
            $itemLabel = is_string($itemLabelVal) ? $itemLabelVal : $valueStr;
            if (str_starts_with($itemLabel, 'LLL:')) {
                if ($translateLabel !== null) {
                    $translated = $translateLabel($itemLabel);
                    return $translated !== '' ? $translated : $valueStr;
                }
                return $valueStr;
            }
            return $itemLabel;
        }

        return $valueStr;
    }
}
