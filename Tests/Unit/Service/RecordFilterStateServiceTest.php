<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use Webconsulting\RecordsListTypes\Service\RecordFilterStateService;

final class RecordFilterStateServiceTest extends TestCase
{
    #[Test]
    public function shouldShowReturnsTrueWhenFilterPanelIsEnabled(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::SHOW_PARAMETER => '1',
        ]);

        self::assertTrue($this->createSubject()->shouldShow($request));
    }

    #[Test]
    public function shouldShowReturnsFalseWhenFilterPanelIsDisabled(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::SHOW_PARAMETER => '0',
            RecordFilterStateService::VALUES_PARAMETER => [
                'tt_content' => [
                    'title' => 'hero',
                ],
            ],
        ]);

        self::assertFalse($this->createSubject()->shouldShow($request));
    }

    #[Test]
    public function shouldShowReturnsTrueWhenActiveFilterValuesExist(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::VALUES_PARAMETER => [
                'tt_content' => [
                    'title' => 'hero',
                ],
            ],
        ]);

        self::assertTrue($this->createSubject()->shouldShow($request));
    }

    #[Test]
    public function getActiveValuesForTableReturnsOnlyRequestedTable(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::VALUES_PARAMETER => [
                'pages' => [
                    'title' => 'home',
                ],
                'tt_content' => [
                    'title' => 'hero',
                    'hidden' => '0',
                ],
            ],
        ]);

        self::assertSame(
            [
                'title' => 'hero',
                'hidden' => '0',
            ],
            $this->createSubject()->getActiveValuesForTable($request, 'tt_content'),
        );
    }

    #[Test]
    public function getSelectedTableReturnsTableParameter(): void
    {
        $request = $this->createRequest([
            'table' => 'tt_content',
        ]);

        self::assertSame('tt_content', $this->createSubject()->getSelectedTable($request));
    }

    #[Test]
    public function getSelectedTableReturnsEmptyStringWhenTableIsMissing(): void
    {
        $request = $this->createRequest([]);

        self::assertSame('', $this->createSubject()->getSelectedTable($request));
    }

    #[Test]
    public function getSelectedTableReturnsEmptyStringForNestedTableParameter(): void
    {
        $request = $this->createRequest([
            'table' => ['tt_content'],
        ]);

        self::assertSame('', $this->createSubject()->getSelectedTable($request));
    }

    #[Test]
    public function attachValuesAddsScalarAndDateRangeValuesToFilters(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::VALUES_PARAMETER => [
                'tx_news_domain_model_news' => [
                    'title' => 'launch',
                    'dateRange' => [
                        'from' => '2026-01-01',
                        'to' => '2026-01-31',
                    ],
                ],
            ],
        ]);

        $filters = [
            [
                'id' => 'title',
                'type' => 'text',
            ],
            [
                'id' => 'dateRange',
                'type' => 'dateRange',
            ],
        ];

        self::assertSame(
            [
                [
                    'id' => 'title',
                    'type' => 'text',
                    'value' => 'launch',
                    'fromValue' => '',
                    'toValue' => '',
                ],
                [
                    'id' => 'dateRange',
                    'type' => 'dateRange',
                    'value' => '',
                    'fromValue' => '2026-01-01',
                    'toValue' => '2026-01-31',
                ],
            ],
            $this->createSubject()->attachValues($filters, $request, 'tx_news_domain_model_news'),
        );
    }

    #[Test]
    public function attachValuesSelectsCanonicalCategoryOptionForTranslatedCategoryUid(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::VALUES_PARAMETER => [
                'tt_content' => [
                    'categories' => '12',
                ],
            ],
        ]);

        $filters = [[
            'id' => 'categories',
            'type' => 'category',
            'options' => [
                [
                    'value' => '',
                    'label' => 'Any',
                ],
                [
                    'value' => '10,11,12',
                    'label' => 'News (Nachrichten, Notizie)',
                ],
            ],
        ]];

        self::assertSame(
            [
                [
                    'id' => 'categories',
                    'type' => 'category',
                    'options' => [
                        [
                            'value' => '',
                            'label' => 'Any',
                        ],
                        [
                            'value' => '10,11,12',
                            'label' => 'News (Nachrichten, Notizie)',
                        ],
                    ],
                    'value' => '10,11,12',
                    'fromValue' => '',
                    'toValue' => '',
                    'selectedOption' => [
                        'value' => '10,11,12',
                        'label' => 'News (Nachrichten, Notizie)',
                    ],
                ],
            ],
            $this->createSubject()->attachValues($filters, $request, 'tt_content'),
        );
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function createRequest(array $queryParams): ServerRequest
    {
        return (new ServerRequest(new Uri('https://example.test/typo3/module/content/records'), 'GET'))
            ->withQueryParams($queryParams);
    }

    private function createSubject(): RecordFilterStateService
    {
        return new RecordFilterStateService();
    }
}
