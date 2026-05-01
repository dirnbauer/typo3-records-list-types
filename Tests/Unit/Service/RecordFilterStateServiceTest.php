<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use Webconsulting\RecordsListTypes\Service\RecordFilterStateService;

final class RecordFilterStateServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

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
    public function shouldShowReturnsTrueWhenStoredModulePreferenceIsEnabled(): void
    {
        $request = $this->createRequest([], new ModuleData('records', [
            RecordFilterStateService::SHOW_PARAMETER => true,
        ]));

        self::assertTrue($this->createSubject()->shouldShow($request));
    }

    #[Test]
    public function shouldShowReturnsFalseWhenStoredModulePreferenceIsDisabled(): void
    {
        $request = $this->createRequest([], new ModuleData('records', [
            RecordFilterStateService::SHOW_PARAMETER => false,
        ]));

        self::assertFalse($this->createSubject()->shouldShow($request));
    }

    #[Test]
    public function shouldShowPrefersExplicitRequestParameterOverStoredModulePreference(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::SHOW_PARAMETER => '0',
        ], new ModuleData('records', [
            RecordFilterStateService::SHOW_PARAMETER => true,
        ]));

        self::assertFalse($this->createSubject()->shouldShow($request));
    }

    #[Test]
    public function persistVisibilityPreferenceFromRequestStoresExplicitModulePreference(): void
    {
        $moduleData = new ModuleData('records', [
            'clipBoard' => true,
        ]);
        $request = $this->createRequest([
            RecordFilterStateService::SHOW_PARAMETER => '1',
        ], $moduleData);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->expects(self::once())
            ->method('pushModuleData')
            ->with(
                'records',
                self::callback(
                    static fn(array $data): bool => ($data[RecordFilterStateService::SHOW_PARAMETER] ?? null) === true,
                ),
            );
        $GLOBALS['BE_USER'] = $backendUser;

        $this->createSubject()->persistVisibilityPreferenceFromRequest($request);

        self::assertTrue($moduleData->get(RecordFilterStateService::SHOW_PARAMETER));
    }

    #[Test]
    public function persistVisibilityPreferenceFromRequestIgnoresRequestsWithoutExplicitPreference(): void
    {
        $moduleData = new ModuleData('records', []);
        $request = $this->createRequest([], $moduleData);

        $this->createSubject()->persistVisibilityPreferenceFromRequest($request);

        self::assertNull($moduleData->get(RecordFilterStateService::SHOW_PARAMETER));
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
    public function hasActiveValuesForTableIgnoresEmptyValues(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::VALUES_PARAMETER => [
                'tt_content' => [
                    'title' => '   ',
                    'dateRange' => [
                        'from' => '',
                        'to' => '',
                    ],
                ],
            ],
        ]);

        self::assertFalse($this->createSubject()->hasActiveValuesForTable($request, 'tt_content'));
    }

    #[Test]
    public function hasActiveValuesForTableDetectsScalarAndNestedValues(): void
    {
        $request = $this->createRequest([
            RecordFilterStateService::VALUES_PARAMETER => [
                'pages' => [
                    'title' => 'blog',
                ],
                'tt_content' => [
                    'dateRange' => [
                        'from' => '',
                        'to' => '2026-05-01',
                    ],
                ],
            ],
        ]);

        $subject = $this->createSubject();

        self::assertTrue($subject->hasActiveValuesForTable($request, 'pages'));
        self::assertTrue($subject->hasActiveValuesForTable($request, 'tt_content'));
        self::assertFalse($subject->hasActiveValuesForTable($request, 'sys_category'));
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
    private function createRequest(array $queryParams, ?ModuleData $moduleData = null): ServerRequest
    {
        $request = (new ServerRequest(new Uri('https://example.test/typo3/module/content/records'), 'GET'))
            ->withQueryParams($queryParams);

        if ($moduleData instanceof ModuleData) {
            $request = $request->withAttribute('moduleData', $moduleData);
        }

        return $request;
    }

    private function createSubject(): RecordFilterStateService
    {
        return new RecordFilterStateService();
    }
}
