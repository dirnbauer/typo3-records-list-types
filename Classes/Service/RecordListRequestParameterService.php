<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

final class RecordListRequestParameterService
{
    /**
     * Preserve list state when building links after a filter form submission.
     *
     * @return array<string, mixed>
     */
    public function getPreservedListParameters(ServerRequestInterface $request): array
    {
        $params = ArrayUtility::mergedRequestParameters($request);

        $preserved = [];
        foreach (['table', 'searchTerm', 'search_levels', 'pointer', 'filters', 'recordFilters', 'sort', 'sortingMode'] as $param) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $preserved[$param] = $params[$param];
            }
        }

        return $preserved;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function withSortingMode(array $parameters, string $tableName, string $mode): array
    {
        $sortingMode = is_array($parameters['sortingMode'] ?? null) ? $parameters['sortingMode'] : [];
        $sortingMode[$tableName] = $mode;
        $parameters['sortingMode'] = $sortingMode;

        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function withSortParams(array $parameters, string $tableName, ?string $field, string $direction): array
    {
        $sort = is_array($parameters['sort'] ?? null) ? $parameters['sort'] : [];
        $tableSort = is_array($sort[$tableName] ?? null) ? $sort[$tableName] : [];
        if ($field !== null) {
            $tableSort['field'] = $field;
        }
        $tableSort['direction'] = $direction;
        $sort[$tableName] = $tableSort;
        $parameters['sort'] = $sort;

        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function withColumnSortParams(array $parameters, string $tableName, string $field, string $direction): array
    {
        return $this->withSortingMode(
            $this->withSortParams($parameters, $tableName, $field, $direction),
            $tableName,
            'field',
        );
    }

    /**
     * Get the current pagination pointer for a specific table from the request.
     */
    public function getCurrentPointer(ServerRequestInterface $request, string $tableName): int
    {
        $pointer = ArrayUtility::mergedRequestParameters($request)['pointer'] ?? [];
        if (is_array($pointer) && isset($pointer[$tableName])) {
            $value = $pointer[$tableName];
            return is_numeric($value) ? max(1, (int) $value) : 1;
        }

        return 1;
    }
}
