<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads record-list filter state from query/body parameters.
 */
final readonly class RecordFilterStateService
{
    public const string SHOW_PARAMETER = 'filters';
    public const string VALUES_PARAMETER = 'recordFilters';

    public function shouldShow(ServerRequestInterface $request): bool
    {
        $params = $this->getMergedParameters($request);
        $show = $params[self::SHOW_PARAMETER] ?? null;

        if ($show !== null) {
            if (!is_scalar($show)) {
                return false;
            }
            return !in_array((string) $show, ['0', 'false', 'off', ''], true);
        }

        return isset($params[self::VALUES_PARAMETER]) && is_array($params[self::VALUES_PARAMETER]);
    }

    public function getSelectedTable(ServerRequestInterface $request): string
    {
        $params = $this->getMergedParameters($request);
        $table = $params['table'] ?? null;
        return is_scalar($table) ? trim((string) $table) : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getActiveValuesForTable(ServerRequestInterface $request, string $table): array
    {
        $params = $this->getMergedParameters($request);
        $allValues = $params[self::VALUES_PARAMETER] ?? [];
        if (!is_array($allValues)) {
            return [];
        }

        $tableValues = $allValues[$table] ?? [];
        return is_array($tableValues) ? $tableValues : [];
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     * @return array<int, array<string, mixed>>
     */
    public function attachValues(array $filters, ServerRequestInterface $request, string $table): array
    {
        $values = $this->getActiveValuesForTable($request, $table);

        foreach ($filters as &$filter) {
            $id = is_string($filter['id'] ?? null) ? $filter['id'] : '';
            $value = $values[$id] ?? null;
            $filter['value'] = is_scalar($value) ? (string) $value : '';
            if (is_array($value)) {
                $from = $value['from'] ?? '';
                $to = $value['to'] ?? '';
                $filter['fromValue'] = is_scalar($from) ? (string) $from : '';
                $filter['toValue'] = is_scalar($to) ? (string) $to : '';
            } else {
                $filter['fromValue'] = '';
                $filter['toValue'] = '';
            }
        }
        unset($filter);

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMergedParameters(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];

        return array_replace_recursive($queryParams, $bodyParams);
    }
}
