<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
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
        private Context $context,
        private TcaSchemaFactory $tcaSchemaFactory,
        private ConnectionPool $connectionPool,
    ) {
        $this->appliedQueryBuilders = new WeakMap();
    }

    public function applyActiveFilters(
        QueryBuilder $queryBuilder,
        string $table,
        int $pageId,
        ServerRequestInterface $request,
        bool $deferWorkspaceFilters = false,
    ): void {
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
            if ($deferWorkspaceFilters && $this->isActiveFilterValue($values[$id])) {
                continue;
            }
            $this->applyFilter($queryBuilder, $table, $filter, $values[$id]);
        }
    }

    public function hasDeferredWorkspaceFilters(string $table, int $pageId, ServerRequestInterface $request): bool
    {
        if ($this->getCurrentWorkspaceId() === 0 || !$this->isWorkspaceAwareTable($table)) {
            return false;
        }
        if ($this->stateService->getSelectedTable($request) !== $table) {
            return false;
        }
        if (!$this->stateService->hasActiveValuesForTable($request, $table)) {
            return false;
        }

        $values = $this->stateService->getActiveValuesForTable($request, $table);
        foreach ($this->configurationService->getFiltersForTable($table, $pageId) as $filter) {
            $id = is_string($filter['id'] ?? null) ? $filter['id'] : '';
            if ($id !== '' && array_key_exists($id, $values) && $this->isActiveFilterValue($values[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row Already overlaid with BackendUtility::workspaceOL().
     */
    public function matchesDeferredWorkspaceFilters(string $table, int $pageId, ServerRequestInterface $request, array $row): bool
    {
        if (!$this->hasDeferredWorkspaceFilters($table, $pageId, $request)) {
            return true;
        }

        $values = $this->stateService->getActiveValuesForTable($request, $table);
        foreach ($this->configurationService->getFiltersForTable($table, $pageId) as $filter) {
            $id = is_string($filter['id'] ?? null) ? $filter['id'] : '';
            if ($id === '' || !array_key_exists($id, $values) || !$this->isActiveFilterValue($values[$id])) {
                continue;
            }
            if (!$this->rowMatchesFilter($table, $filter, $values[$id], $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyFilter(QueryBuilder $queryBuilder, string $table, array $filter, mixed $value): void
    {
        $type = is_string($filter['type'] ?? null) ? $filter['type'] : '';
        match ($type) {
            'text' => $this->applyTextFilter($queryBuilder, $table, $filter, $value),
            'boolean', 'select' => $this->applyExactFilter($queryBuilder, $table, $filter, $value),
            'dateRange' => $this->applyDateRangeFilter($queryBuilder, $table, $filter, $value),
            'category' => $this->applyCategoryFilter($queryBuilder, $table, $filter, $value),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $row
     */
    private function rowMatchesFilter(string $table, array $filter, mixed $value, array $row): bool
    {
        $type = is_string($filter['type'] ?? null) ? $filter['type'] : '';
        return match ($type) {
            'text' => $this->rowMatchesTextFilter($table, $filter, $value, $row),
            'boolean', 'select' => $this->rowMatchesExactFilter($table, $filter, $value, $row),
            'dateRange' => $this->rowMatchesDateRangeFilter($table, $filter, $value, $row),
            'category' => $this->rowMatchesCategoryFilter($table, $filter, $value, $row),
            default => $this->rowMatchesGenericFilter($table, $filter, $value, $row),
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
     * @param array<string, mixed> $row
     */
    private function rowMatchesTextFilter(string $table, array $filter, mixed $value, array $row): bool
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return true;
        }

        $fields = is_array($filter['fields'] ?? null) ? $filter['fields'] : [];
        $search = mb_strtolower(trim((string) $value));
        foreach ($fields as $field) {
            if (!is_string($field) || !$this->configurationService->fieldExists($table, $field)) {
                continue;
            }
            $fieldValue = $row[$field] ?? null;
            if (is_array($fieldValue)) {
                $fieldValue = reset($fieldValue);
            }
            if (is_scalar($fieldValue) && str_contains(mb_strtolower((string) $fieldValue), $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $row
     */
    private function rowMatchesExactFilter(string $table, array $filter, mixed $value, array $row): bool
    {
        if (!is_scalar($value) || (string) $value === '') {
            return true;
        }
        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field === '' || !$this->configurationService->fieldExists($table, $field)) {
            return true;
        }

        $actualValue = $row[$field] ?? null;
        if (is_array($actualValue)) {
            $actualValue = reset($actualValue);
        }

        if (is_numeric($value)) {
            return is_numeric($actualValue) && (int) $actualValue === (int) $value;
        }

        return is_scalar($actualValue) && (string) $actualValue === (string) $value;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $row
     */
    private function rowMatchesDateRangeFilter(string $table, array $filter, mixed $value, array $row): bool
    {
        if (!is_array($value)) {
            return true;
        }
        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field === '' || !$this->configurationService->fieldExists($table, $field)) {
            return true;
        }
        $from = is_scalar($value['from'] ?? null) ? trim((string) $value['from']) : '';
        $to = is_scalar($value['to'] ?? null) ? trim((string) $value['to']) : '';
        if ($from === '' && $to === '') {
            return true;
        }

        $actualValue = $row[$field] ?? null;
        if (is_array($actualValue)) {
            $actualValue = reset($actualValue);
        }
        if (!is_scalar($actualValue)) {
            return false;
        }

        $parameterType = $this->dateParameterType($table, $field);
        $actual = $parameterType === ParameterType::STRING
            ? (string) $actualValue
            : (is_numeric($actualValue) ? (int) $actualValue : 0);

        if ($from !== '') {
            $fromValue = $this->normalizeDateValue($table, $field, $from, false);
            if ($actual < $fromValue) {
                return false;
            }
        }
        if ($to !== '') {
            $toValue = $this->normalizeDateValue($table, $field, $to, true);
            if ($actual > $toValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $row
     */
    private function rowMatchesCategoryFilter(string $table, array $filter, mixed $value, array $row): bool
    {
        $categoryUids = $this->normalizeCategoryValue($value);
        if ($categoryUids === []) {
            return true;
        }
        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field === '' || !$this->configurationService->fieldExists($table, $field)) {
            return true;
        }

        $recordUids = $this->getWorkspaceRelationRecordUids($row);
        if ($recordUids === []) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_category_record_mm');
        $count = $queryBuilder
            ->count('*')
            ->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($recordUids, ArrayParameterType::INTEGER),
                ),
                $queryBuilder->expr()->in(
                    'uid_local',
                    $queryBuilder->createNamedParameter($categoryUids, ArrayParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter($table, ParameterType::STRING),
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter($field, ParameterType::STRING),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * Fallback for custom filter types. If a filter declares a single `field`,
     * compare it exactly; if it declares `fields`, use text matching over them.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $row
     */
    private function rowMatchesGenericFilter(string $table, array $filter, mixed $value, array $row): bool
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return true;
        }

        $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
        if ($field !== '') {
            return $this->rowMatchesExactFilter($table, ['field' => $field], $value, $row);
        }

        $fields = is_array($filter['fields'] ?? null) ? $filter['fields'] : [];
        if ($fields !== []) {
            return $this->rowMatchesTextFilter($table, ['fields' => $fields], $value, $row);
        }

        return true;
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

    private function isActiveFilterValue(mixed $value): bool
    {
        if (is_scalar($value)) {
            return trim((string) $value) !== '';
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $nestedValue) {
            if (is_scalar($nestedValue) && trim((string) $nestedValue) !== '') {
                return true;
            }
        }

        return false;
    }

    private function getCurrentWorkspaceId(): int
    {
        $workspaceId = $this->context->getPropertyFromAspect('workspace', 'id', 0);
        return is_numeric($workspaceId) ? (int) $workspaceId : 0;
    }

    private function isWorkspaceAwareTable(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)->isWorkspaceAware();
    }

    /**
     * @param array<string, mixed> $row
     * @return list<int>
     */
    private function getWorkspaceRelationRecordUids(array $row): array
    {
        $uids = [];
        foreach (['uid', '_ORIG_uid'] as $field) {
            $uid = $row[$field] ?? null;
            if (is_numeric($uid) && (int) $uid > 0) {
                $uids[] = (int) $uid;
            }
        }

        return array_values(array_unique($uids));
    }
}
