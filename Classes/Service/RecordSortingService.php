<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Stringable;

final class RecordSortingService
{
    /**
     * Sort overlaid workspace rows by their effective raw field value.
     *
     * @param array<int, array<string, mixed>> $records
     */
    public function sortRecordsByRawField(array &$records, string $sortField, string $sortDirection): void
    {
        $descending = strtolower($sortDirection) === 'desc';

        usort(
            $records,
            static function (array $left, array $right) use ($sortField, $descending): int {
                $leftRaw = is_array($left['rawRecord'] ?? null) ? $left['rawRecord'] : [];
                $rightRaw = is_array($right['rawRecord'] ?? null) ? $right['rawRecord'] : [];

                $comparison = self::compareSortableValues($leftRaw[$sortField] ?? null, $rightRaw[$sortField] ?? null);
                if ($comparison === 0) {
                    $comparison = self::compareSortableValues($leftRaw['uid'] ?? null, $rightRaw['uid'] ?? null);
                }

                return $descending ? -$comparison : $comparison;
            },
        );
    }

    /**
     * Return a stable workspace identity for a row after overlay.
     *
     * Versioned records point back to the live row via t3ver_oid; live rows and
     * new workspace-only records fall back to their own effective uid.
     *
     * @param array<string, mixed> $row
     */
    public function getWorkspaceRecordIdentity(array $row, int $fallbackUid): string
    {
        $liveUidRaw = $row['t3ver_oid'] ?? 0;
        $liveUid = is_numeric($liveUidRaw) ? (int) $liveUidRaw : 0;
        return (string) ($liveUid > 0 ? $liveUid : $fallbackUid);
    }

    private static function compareSortableValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === null || $left === '') {
            return 1;
        }
        if ($right === null || $right === '') {
            return -1;
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        $leftString = self::sortableValueAsString($left);
        $rightString = self::sortableValueAsString($right);
        if ($leftString === $rightString) {
            return 0;
        }
        if ($leftString === null) {
            return 1;
        }
        if ($rightString === null) {
            return -1;
        }

        return strnatcasecmp($leftString, $rightString);
    }

    private static function sortableValueAsString(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }
}
