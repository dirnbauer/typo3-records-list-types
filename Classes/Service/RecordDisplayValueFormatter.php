<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Turns a raw field value into the text the list view shows for it.
 *
 * BackendUtility::getProcessedValueExtra() is what DatabaseRecordList uses:
 * it resolves select and radio labels, the titles of related records and
 * categories, formats dates with the backend's date format, shows the path
 * for "pid", and crops text; rich text is reduced to plain text here.
 */
final class RecordDisplayValueFormatter
{
    /**
     * @param array<string, mixed> $row The record row after the workspace overlay
     */
    public function formatFieldValue(string $table, string $field, array $row, int $maxLength = 100): string
    {
        $value = $row[$field] ?? null;
        if (!is_scalar($value) || $value === '') {
            return '';
        }

        $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
        $pid = is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0;
        $processed = BackendUtility::getProcessedValueExtra(
            $table,
            $field,
            (string)$value,
            $maxLength,
            $uid,
            true,
            $table === 'pages' ? $uid : $pid,
            $row,
        );
        if (!is_scalar($processed)) {
            return '';
        }

        // Core passes text through as stored; rich text still holds its tags
        // and entities. Block boundaries become spaces so words stay apart.
        $text = (string)$processed;
        $text = preg_replace('#<(?:br\s*/?|/(?:p|div|li|h[1-6]|td|th|tr|blockquote|ul|ol))\s*>#i', '$0 ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
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

        if (isset($config['invertStateDisplay']) && (bool)$config['invertStateDisplay']) {
            return true;
        }

        $items = is_array($config['items'] ?? null) ? $config['items'] : [];
        return array_any($items, fn($item): bool => is_array($item) && isset($item['invertStateDisplay']) && (bool)$item['invertStateDisplay']);
    }
}
