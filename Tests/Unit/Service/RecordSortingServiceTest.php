<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Service\RecordSortingService;

final class RecordSortingServiceTest extends TestCase
{
    #[Test]
    public function sortRecordsByRawFieldUsesOverlaidWorkspaceValuesAscending(): void
    {
        $records = [
            ['rawRecord' => ['uid' => 10, 'sorting' => 300]],
            ['rawRecord' => ['uid' => 11, 'sorting' => 100]],
            ['rawRecord' => ['uid' => 12, 'sorting' => 200]],
        ];

        $this->createSubject()->sortRecordsByRawField($records, 'sorting', 'asc');

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

        $this->createSubject()->sortRecordsByRawField($records, 'sorting', 'desc');

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

        $this->createSubject()->sortRecordsByRawField($records, 'sorting', 'asc');

        self::assertSame([1, 2, 3], $this->extractRawUids($records));
    }

    #[Test]
    public function getWorkspaceRecordIdentityUsesLiveUidForVersionedRows(): void
    {
        $identity = $this->createSubject()->getWorkspaceRecordIdentity([
            'uid' => 42,
            't3ver_oid' => 7,
        ], 42);

        self::assertSame('7', $identity);
    }

    #[Test]
    public function getWorkspaceRecordIdentityFallsBackToEffectiveUidForLiveRows(): void
    {
        $identity = $this->createSubject()->getWorkspaceRecordIdentity([
            'uid' => 42,
            't3ver_oid' => 0,
        ], 42);

        self::assertSame('42', $identity);
    }

    private function createSubject(): RecordSortingService
    {
        return new RecordSortingService();
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
