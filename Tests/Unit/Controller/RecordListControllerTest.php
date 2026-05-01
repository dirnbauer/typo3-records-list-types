<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Webconsulting\RecordsListTypes\Controller\RecordListController;

final class RecordListControllerTest extends TestCase
{
    #[Test]
    public function sortRecordsByRawFieldUsesOverlaidWorkspaceValuesAscending(): void
    {
        $records = [
            ['rawRecord' => ['uid' => 10, 'sorting' => 300]],
            ['rawRecord' => ['uid' => 11, 'sorting' => 100]],
            ['rawRecord' => ['uid' => 12, 'sorting' => 200]],
        ];

        $this->sortRecordsByRawField($records, 'sorting', 'asc');

        self::assertSame([11, 12, 10], $this->extractRawUids($records));
    }

    #[Test]
    public function sortRecordsByRawFieldUsesOverlaidWorkspaceValuesDescending(): void
    {
        $records = [
            ['rawRecord' => ['uid' => 10, 'sorting' => 300]],
            ['rawRecord' => ['uid' => 11, 'sorting' => 100]],
            ['rawRecord' => ['uid' => 12, 'sorting' => 200]],
        ];

        $this->sortRecordsByRawField($records, 'sorting', 'desc');

        self::assertSame([10, 12, 11], $this->extractRawUids($records));
    }

    #[Test]
    public function sortRecordsByRawFieldFallsBackToUidForEqualValues(): void
    {
        $records = [
            ['rawRecord' => ['uid' => 3, 'sorting' => 100]],
            ['rawRecord' => ['uid' => 1, 'sorting' => 100]],
            ['rawRecord' => ['uid' => 2, 'sorting' => 100]],
        ];

        $this->sortRecordsByRawField($records, 'sorting', 'asc');

        self::assertSame([1, 2, 3], $this->extractRawUids($records));
    }

    #[Test]
    public function withColumnSortParamsSwitchesTableToFieldSorting(): void
    {
        $parameters = [
            'id' => 123,
            'displayMode' => 'compact',
            'sortingMode' => [
                'tt_content' => 'manual',
            ],
            'sort' => [
                'tt_content' => [
                    'field' => 'sorting',
                    'direction' => 'asc',
                ],
            ],
        ];

        $result = $this->withColumnSortParams($parameters, 'tt_content', 'header', 'desc');

        self::assertSame('field', $result['sortingMode']['tt_content']);
        self::assertSame('header', $result['sort']['tt_content']['field']);
        self::assertSame('desc', $result['sort']['tt_content']['direction']);
        self::assertSame(123, $result['id']);
        self::assertSame('compact', $result['displayMode']);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function sortRecordsByRawField(array &$records, string $sortField, string $sortDirection): void
    {
        $controller = (new ReflectionClass(RecordListController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RecordListController::class, 'sortRecordsByRawField');
        $method->invokeArgs($controller, [&$records, $sortField, $sortDirection]);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function withColumnSortParams(array $parameters, string $tableName, string $field, string $direction): array
    {
        $controller = (new ReflectionClass(RecordListController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RecordListController::class, 'withColumnSortParams');
        $result = $method->invoke($controller, $parameters, $tableName, $field, $direction);

        self::assertIsArray($result);
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return list<int>
     */
    private function extractRawUids(array $records): array
    {
        return array_map(
            static fn(array $record): int => (int) ($record['rawRecord']['uid'] ?? 0),
            $records,
        );
    }
}
