<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use Webconsulting\RecordsListTypes\Service\RecordListRequestParameterService;

final class RecordListRequestParameterServiceTest extends TestCase
{
    #[Test]
    public function getPreservedListParametersMergesQueryAndBodyAndKeepsOnlyListState(): void
    {
        $request = (new ServerRequest('https://example.test/typo3/module/records'))
            ->withQueryParams([
                'id' => 123,
                'table' => 'pages',
                'searchTerm' => 'hero',
            ])
            ->withParsedBody([
                'table' => 'tt_content',
                'displayMode' => 'grid',
                'recordFilters' => [
                    'tt_content' => [
                        'hidden' => '0',
                    ],
                ],
                'pointer' => [
                    'tt_content' => '3',
                ],
                'empty' => '',
            ]);

        self::assertSame(
            [
                'table' => 'tt_content',
                'searchTerm' => 'hero',
                'pointer' => [
                    'tt_content' => '3',
                ],
                'recordFilters' => [
                    'tt_content' => [
                        'hidden' => '0',
                    ],
                ],
            ],
            $this->createSubject()->getPreservedListParameters($request),
        );
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

        $result = $this->createSubject()->withColumnSortParams($parameters, 'tt_content', 'header', 'desc');

        self::assertSame('field', $result['sortingMode']['tt_content']);
        self::assertSame('header', $result['sort']['tt_content']['field']);
        self::assertSame('desc', $result['sort']['tt_content']['direction']);
        self::assertSame(123, $result['id']);
        self::assertSame('compact', $result['displayMode']);
    }

    #[Test]
    public function withSortParamsKeepsExistingFieldWhenOnlyDirectionChanges(): void
    {
        $parameters = [
            'sort' => [
                'pages' => [
                    'field' => 'title',
                    'direction' => 'asc',
                ],
            ],
        ];

        $result = $this->createSubject()->withSortParams($parameters, 'pages', null, 'desc');

        self::assertSame('title', $result['sort']['pages']['field']);
        self::assertSame('desc', $result['sort']['pages']['direction']);
    }

    #[Test]
    public function getCurrentPointerReturnsTableSpecificPointerFromQuery(): void
    {
        $request = (new ServerRequest('https://example.test/typo3/module/records'))
            ->withQueryParams([
                'pointer' => [
                    'tt_content' => '4',
                ],
            ]);

        self::assertSame(4, $this->createSubject()->getCurrentPointer($request, 'tt_content'));
    }

    #[Test]
    public function getCurrentPointerFallsBackToOneForInvalidBodyPointer(): void
    {
        $request = (new ServerRequest('https://example.test/typo3/module/records'))
            ->withParsedBody([
                'pointer' => [
                    'tt_content' => 'invalid',
                ],
            ]);

        self::assertSame(1, $this->createSubject()->getCurrentPointer($request, 'tt_content'));
    }

    private function createSubject(): RecordListRequestParameterService
    {
        return new RecordListRequestParameterService();
    }
}
