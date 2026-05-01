<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\SingletonInterface;
use WeakMap;

/**
 * Applies configured record filters to TYPO3 record-list query builders.
 *
 * The service is intentionally request-driven so alternative view modes can use
 * the current controller request, while the classic list view can still apply
 * the same logic through TYPO3's record-list query event.
 */
final readonly class RecordFilterQueryService implements SingletonInterface
{
    /** @var WeakMap<QueryBuilder, true> */
    private WeakMap $appliedQueryBuilders;

    public function __construct(
        private RecordFilterConfigurationService $configurationService,
        private RecordFilterStateService $stateService,
        private LlmRecordSearchService $llmRecordSearchService,
    ) {
        $this->appliedQueryBuilders = new WeakMap();
    }

    public function applyActiveFilters(QueryBuilder $queryBuilder, string $table, int $pageId, ServerRequestInterface $request): void
    {
        if (isset($this->appliedQueryBuilders[$queryBuilder])) {
            return;
        }
        if ($this->stateService->getSelectedTable($request) !== $table) {
            return;
        }
        if (!$this->stateService->hasActiveValuesForTable($request, $table)) {
            return;
        }

        $values = $this->stateService->getActiveValuesForTable($request, $table);
        $filters = $this->configurationService->getFiltersForTable($table, $pageId);
        if ($filters === []) {
            return;
        }

        $this->appliedQueryBuilders[$queryBuilder] = true;
        foreach ($filters as $filter) {
            $id = is_string($filter['id'] ?? null) ? $filter['id'] : '';
            if ($id === '' || !array_key_exists($id, $values)) {
                continue;
            }
            $this->applyFilter($queryBuilder, $table, $pageId, $filter, $values[$id]);
        }
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyFilter(QueryBuilder $queryBuilder, string $table, int $pageId, array $filter, mixed $value): void
    {
        $type = is_string($filter['type'] ?? null) ? $filter['type'] : '';
        match ($type) {
            'text' => $this->applyTextFilter($queryBuilder, $table, $filter, $value),
            'boolean', 'select' => $this->applyExactFilter($queryBuilder, $table, $filter, $value),
            'dateRange' => $this->applyDateRangeFilter($queryBuilder, $table, $filter, $value),
            'category' => $this->applyCategoryFilter($queryBuilder, $table, $filter, $value),
            'llm' => $this->applyLlmFilter($queryBuilder, $table, $pageId, $filter, $value),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyTextFilter(QueryBuilder $queryBuilder, string $table, array $filter, mixed $value): void
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return;
        }
        $fields = is_array($filter['fields'] ?? null) ? $filter['fields'] : [];
        $search = '%' . $queryBuilder->escapeLikeWildcards(trim((string) $value)) . '%';
        $constraints = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !$this->configurationService->fieldExists($table, $field)) {
                continue;
            }
            $constraints[] = $queryBuilder->expr()->comparison(
                'LOWER(' . $queryBuilder->castFieldToTextType($field) . ')',
                'LIKE',
                'LOWER(' . $queryBuilder->createNamedParameter($search) . ')',
            );
        }
        if ($constraints !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$constraints));
        }
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyExactFilter(QueryBuilder $queryBuilder, string $table, array $filter, mixed $value): void
    {
        if (!is_scalar($value) || (string) $value === '') {
            return;
        }
        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field === '' || !$this->configurationService->fieldExists($table, $field)) {
            return;
        }
        $parameterType = is_numeric($value) ? ParameterType::INTEGER : ParameterType::STRING;
        $parameterValue = is_numeric($value) ? (int) $value : (string) $value;
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($parameterValue, $parameterType),
            ),
        );
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyDateRangeFilter(QueryBuilder $queryBuilder, string $table, array $filter, mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field === '' || !$this->configurationService->fieldExists($table, $field)) {
            return;
        }
        $from = is_scalar($value['from'] ?? null) ? trim((string) $value['from']) : '';
        $to = is_scalar($value['to'] ?? null) ? trim((string) $value['to']) : '';
        if ($from === '' && $to === '') {
            return;
        }

        if ($from !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte(
                    $field,
                    $queryBuilder->createNamedParameter($this->normalizeDateValue($table, $field, $from, false), $this->dateParameterType($table, $field)),
                ),
            );
        }
        if ($to !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte(
                    $field,
                    $queryBuilder->createNamedParameter($this->normalizeDateValue($table, $field, $to, true), $this->dateParameterType($table, $field)),
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyCategoryFilter(QueryBuilder $queryBuilder, string $table, array $filter, mixed $value): void
    {
        $categoryUids = $this->normalizeCategoryValue($value);
        if ($categoryUids === []) {
            return;
        }
        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field === '' || !$this->configurationService->fieldExists($table, $field)) {
            return;
        }

        $subQuery = 'SELECT 1 FROM sys_category_record_mm record_filter_category_mm'
            . ' WHERE record_filter_category_mm.uid_foreign = ' . $queryBuilder->quoteIdentifier($table . '.uid')
            . ' AND ' . $queryBuilder->expr()->in(
                'record_filter_category_mm.uid_local',
                $queryBuilder->createNamedParameter($categoryUids, ArrayParameterType::INTEGER),
            )
            . ' AND record_filter_category_mm.tablenames = ' . $queryBuilder->createNamedParameter($table)
            . ' AND record_filter_category_mm.fieldname = ' . $queryBuilder->createNamedParameter($field);

        $queryBuilder->andWhere('EXISTS (' . $subQuery . ')');
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyLlmFilter(QueryBuilder $queryBuilder, string $table, int $pageId, array $filter, mixed $value): void
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return;
        }
        $uids = $this->llmRecordSearchService->findMatchingUids($table, $pageId, trim((string) $value), $filter);
        if ($uids === null) {
            return;
        }
        if ($uids === []) {
            $queryBuilder->andWhere('0=1');
            return;
        }
        $queryBuilder->andWhere(
            $queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
            ),
        );
    }

    /**
     * @return list<int>
     */
    private function normalizeCategoryValue(mixed $value): array
    {
        if (!is_scalar($value)) {
            return [];
        }

        $uids = [];
        foreach (explode(',', (string) $value) as $uid) {
            if (!is_numeric($uid)) {
                continue;
            }
            $uid = (int) $uid;
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids));
    }

    private function normalizeDateValue(string $table, string $field, string $date, bool $endOfDay): int|string
    {
        if ($this->dateParameterType($table, $field) === ParameterType::STRING) {
            return $date;
        }
        $time = strtotime($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
        return $time === false ? 0 : $time;
    }

    private function dateParameterType(string $table, string $field): ParameterType
    {
        $fieldConfig = $this->configurationService->getFieldConfig($table, $field);
        $config = is_array($fieldConfig['config'] ?? null) ? $fieldConfig['config'] : [];
        $dbType = is_string($config['dbType'] ?? null) ? strtolower($config['dbType']) : '';
        return str_contains($dbType, 'date') ? ParameterType::STRING : ParameterType::INTEGER;
    }
}
