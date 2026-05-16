<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Utility;

/**
 * Small typed boundary helpers for TYPO3 TSconfig and request arrays.
 */
final class ArrayUtility
{
    /**
     * @param array<mixed> $source
     * @param non-empty-list<string> $path
     * @return array<string, mixed>
     */
    public static function arrayPath(array $source, array $path): array
    {
        $current = $source;
        foreach ($path as $segment) {
            $next = $current[$segment] ?? null;
            if (!is_array($next)) {
                return [];
            }
            $current = $next;
        }

        return self::stringKeyArray($current);
    }

    /**
     * @param array<mixed> $source
     * @param non-empty-list<string> $path
     */
    public static function valuePath(array $source, array $path): mixed
    {
        $current = $source;
        $lastKey = array_key_last($path);
        foreach ($path as $index => $segment) {
            $next = $current[$segment] ?? null;
            if ($index === $lastKey) {
                return $next;
            }
            if (!is_array($next)) {
                return null;
            }
            $current = $next;
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $source
     * @return array<string, mixed>
     */
    public static function stringKeyArray(array $source): array
    {
        $result = [];
        foreach ($source as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    public static function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function intValue(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function commaSeparatedList(mixed $value): array
    {
        $string = self::stringValue($value);
        if ($string === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $string)),
            static fn(string $item): bool => $item !== '',
        ));
    }
}
