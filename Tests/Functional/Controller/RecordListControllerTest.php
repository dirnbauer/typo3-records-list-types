<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Backend\Clipboard\Clipboard;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Context\PageContextFactory;
use TYPO3\CMS\Backend\Domain\Model\Language\PageLanguageInformation;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\RecordsListTypes\Controller\RecordListController;
use Webconsulting\RecordsListTypes\RecordList\AlternativeDatabaseRecordList;
use Webconsulting\RecordsListTypes\Service\RecordViewEnrichmentContext;
use Webconsulting\RecordsListTypes\Service\RecordViewEnrichmentService;

#[CoversClass(RecordListController::class)]
#[CoversClass(RecordViewEnrichmentService::class)]
final class RecordListControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/records-list-types',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUsers.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function renderSearchBoxUsesRecordsRouteAndPreservesCustomViewState(): void
    {
        $controller = $this->createControllerForPage(1);
        $this->setControllerProperty($controller, 'table', 'tt_content');
        $this->setControllerProperty($controller, 'modTSconfig', []);

        $request = (new ServerRequest('https://example.test/typo3/module/records'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams([
                'id' => 1,
                'displayMode' => 'grid',
                'recordFilters' => [
                    'tt_content' => [
                        'hidden' => '0',
                    ],
                ],
                'sort' => [
                    'tt_content' => [
                        'field' => 'header',
                        'direction' => 'desc',
                    ],
                ],
            ]);

        $html = $this->invokeRenderSearchBox(
            $controller,
            $request,
            $this->get(DatabaseRecordList::class),
            'hero',
            1,
        );
        $formAction = $this->extractFormAction($html);
        $params = $this->parseQueryParams($formAction);

        self::assertStringContainsString('/record', $formAction);
        self::assertSame('1', (string) ($params['id'] ?? ''));
        self::assertSame('grid', $params['displayMode'] ?? null);
        self::assertSame('tt_content', $params['table'] ?? null);
        self::assertSame('0', $params['recordFilters']['tt_content']['hidden'] ?? null);
        self::assertSame('header', $params['sort']['tt_content']['field'] ?? null);
        self::assertSame('desc', $params['sort']['tt_content']['direction'] ?? null);
        self::assertStringContainsString('value="hero"', $html);
    }

    #[Test]
    public function enrichRecordWithEditUrlsKeepsCustomViewReturnUrlContext(): void
    {
        $pageContext = $this->createPageContext(1);
        $request = (new ServerRequest('https://example.test/typo3/module/records'))
            ->withQueryParams([
                'id' => 1,
                'displayMode' => 'compact',
                'table' => 'tt_content',
                'recordFilters' => [
                    'tt_content' => [
                        'hidden' => '0',
                    ],
                ],
            ])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $context = new RecordViewEnrichmentContext($pageContext, 'compact', $request);
        $enrichmentService = $this->get(RecordViewEnrichmentService::class);
        self::assertInstanceOf(RecordViewEnrichmentService::class, $enrichmentService);
        $record = $enrichmentService->enrichRecordWithEditUrls([
            'uid' => 42,
            'tableName' => 'tt_content',
            'rawRecord' => [
                'uid' => 42,
                'pid' => 1,
            ],
        ], $context);

        self::assertIsString($record['editUrl'] ?? null);
        self::assertIsString($record['contextualEditUrl'] ?? null);
        self::assertNotSame('', $record['editUrl']);
        self::assertNotSame('', $record['contextualEditUrl']);

        $editParams = $this->parseQueryParams($record['editUrl']);
        $returnParams = $this->parseQueryParams((string) ($editParams['returnUrl'] ?? ''));

        self::assertSame('edit', $editParams['edit']['tt_content'][42] ?? null);
        self::assertSame('records', $editParams['module'] ?? null);
        self::assertSame('1', (string) ($returnParams['id'] ?? ''));
        self::assertSame('compact', $returnParams['displayMode'] ?? null);
        self::assertSame('tt_content', $returnParams['table'] ?? null);
        self::assertSame('0', $returnParams['recordFilters']['tt_content']['hidden'] ?? null);
    }

    #[Test]
    #[DataProvider('tableVisibilityProvider')]
    public function explicitlyRequestedTableRespectsAccessAndVisibility(array $tsConfig, string $table): void
    {
        $controller = $this->createControllerForPage(1);
        $this->setControllerProperty($controller, 'modTSconfig', $tsConfig);
        $method = new ReflectionMethod(RecordListController::class, 'getSearchableTables');

        self::assertSame([], $method->invoke(
            $controller,
            1,
            $table,
            '',
            0,
            new ServerRequest('https://example.test/typo3/'),
        ));
    }

    public static function tableVisibilityProvider(): iterable
    {
        yield 'unknown table' => [[], 'missing_table'];
        yield 'hidden table' => [['hideTables' => 'tt_content'], 'tt_content'];
        yield 'wildcard' => [['hideTables' => '*'], 'tt_content'];
        yield 'table override' => [['table' => ['tt_content' => ['hideTable' => '1']]], 'tt_content'];
    }

    #[Test]
    #[DataProvider('editorTableAccessProvider')]
    public function explicitlyRequestedTableRespectsEditorTablePermissions(string $allowedTables, array $expected): void
    {
        $this->setUpBackendUser(2);
        $GLOBALS['BE_USER']->groupData['tables_select'] = $allowedTables;
        $controller = $this->createControllerForPage(1);
        $this->setControllerProperty($controller, 'modTSconfig', []);
        $method = new ReflectionMethod(RecordListController::class, 'getSearchableTables');

        self::assertSame($expected, $method->invoke(
            $controller,
            1,
            'tt_content',
            '',
            0,
            new ServerRequest('https://example.test/typo3/'),
        ));
    }

    public static function editorTableAccessProvider(): iterable
    {
        yield 'allowed' => ['pages,tt_content', ['tt_content']];
        yield 'denied' => ['pages', []];
    }

    #[Test]
    public function scalarTsConfigCanDisableTableHeaderActions(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->update('pages', [
            'TSconfig' => "mod.web_list.displayRecordDownload = 0\nmod.web_list.displayColumnSelector = 0",
        ], ['uid' => 1]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 1, 'header' => 'Configured table actions', 'CType' => 'text',
        ]);

        $response = $this->get(RecordListController::class)->mainAction($this->createBackendRequest(1, 'grid'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('Configured table actions', $html);
        self::assertStringNotContainsString('typo3-recordlist-record-download-button', $html);
        self::assertStringNotContainsString('typo3-backend-column-selector-button', $html);
    }

    #[Test]
    public function preparingAnotherTableDoesNotChangeAnExistingRecordList(): void
    {
        $first = GeneralUtility::makeInstance(AlternativeDatabaseRecordList::class);
        $first->setRequest($this->createBackendRequest(1, 'grid'));
        $first->start(1, 'pages', 0);
        $second = GeneralUtility::makeInstance(AlternativeDatabaseRecordList::class);
        $second->setRequest($this->createBackendRequest(1, 'grid'));
        $second->start(1, 'tt_content', 0);

        self::assertSame(['pages'], $first->getTablesToRender());
        self::assertSame(['tt_content'], $second->getTablesToRender());
    }

    #[Test]
    #[DataProvider('clipboardVisibilityProvider')]
    public function bulkActionsUseTheNormalizedClipboard(bool $clipboardShown): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 1, 'header' => 'Clipboard example', 'CType' => 'text',
        ]);
        $request = $this->createBackendRequest(1, 'grid');
        $request->getAttribute('moduleData')->set('clipBoard', $clipboardShown);
        $clipboard = GeneralUtility::makeInstance(Clipboard::class);
        $clipboard->initializeClipboard($request);
        $clipboard->setCmd(['setP' => 'tab_1']);
        $clipboard->endClipboard();

        $response = $this->get(RecordListController::class)->mainAction($request);
        self::assertSame($clipboardShown, str_contains(
            (string) $response->getBody(),
            'data-multi-record-selection-action="copyMarked"',
        ));
    }

    public static function clipboardVisibilityProvider(): iterable
    {
        yield 'visible clipboard with bulk pad' => [true];
        yield 'hidden clipboard resets to normal pad' => [false];
    }

    #[Test]
    #[DataProvider('viewModeProvider')]
    public function rendersRecordsWithEveryView(string $mode): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 1, 'header' => 'Rendered example record', 'CType' => 'text', 'bodytext' => 'Example body',
        ]);
        $request = $this->createBackendRequest(1, $mode);
        $response = $this->get(RecordListController::class)->mainAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Rendered example record', (string) $response->getBody());
    }

    #[Test]
    #[DataProvider('viewModeProvider')]
    public function inaccessiblePageNeverRendersItsRecords(string $mode): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 3, 'header' => 'Confidential record on inaccessible page', 'CType' => 'text',
        ]);
        $this->setUpBackendUser(2);
        $GLOBALS['BE_USER']->groupData['tables_select'] = 'pages,tt_content';
        $request = $this->createBackendRequest(3, $mode);
        self::assertFalse($request->getAttribute('pageContext')->isAccessible());

        $response = $this->get(RecordListController::class)->mainAction($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Confidential record on inaccessible page', (string) $response->getBody());
    }

    public static function viewModeProvider(): iterable
    {
        foreach (['list', 'grid', 'compact', 'teaser'] as $mode) {
            yield $mode => [$mode];
        }
    }

    private function createBackendRequest(int $pageId, string $mode): ServerRequestInterface
    {
        $module = $this->get(ModuleProvider::class)->getModule('records');
        $route = $this->get(Router::class)->getRoute('records');
        $route->setOption('_identifier', 'records');
        $request = (new ServerRequest('https://example.test/typo3/module/content/records', 'GET', serverParams: [
            'HTTP_HOST' => 'example.test', 'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->instancePath . '/public/index.php', 'HTTPS' => 'on',
        ]))
            ->withQueryParams(['id' => $pageId, 'table' => 'tt_content', 'displayMode' => $mode])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', new NullSite())
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', ModuleData::createFromModule($module, []))
            ->withAttribute('route', $route);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $request = $request->withAttribute('pageContext', $this->get(PageContextFactory::class)->createFromRequest($request, $pageId, $GLOBALS['BE_USER']));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    private function createControllerForPage(int $pageId): RecordListController
    {
        $controller = $this->get(RecordListController::class);
        self::assertInstanceOf(RecordListController::class, $controller);
        $this->setControllerProperty($controller, 'pageContext', $this->createPageContext($pageId));

        return $controller;
    }

    private function createPageContext(int $pageId): PageContext
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getAvailableLanguages')->willReturn([]);

        return new PageContext(
            pageId: $pageId,
            pageRecord: [
                'uid' => $pageId,
                'title' => 'Test Page',
            ],
            site: $site,
            rootLine: [],
            pageTsConfig: [],
            selectedLanguageIds: [0],
            languageInformation: new PageLanguageInformation($pageId, [], [], [], [], false, []),
            pagePermissions: new Permission(Permission::ALL),
        );
    }

    private function invokeRenderSearchBox(
        RecordListController $controller,
        ServerRequestInterface $request,
        DatabaseRecordList $dbList,
        string $searchWord,
        int $searchLevels,
    ): string {
        $method = new ReflectionMethod(RecordListController::class, 'renderSearchBox');
        $result = $method->invoke($controller, $request, $dbList, $searchWord, $searchLevels);
        self::assertIsString($result);

        return $result;
    }

    private function setControllerProperty(RecordListController $controller, string $propertyName, mixed $value): void
    {
        $reflectionClass = new ReflectionClass($controller);
        while (!$reflectionClass->hasProperty($propertyName) && ($parent = $reflectionClass->getParentClass()) !== false) {
            $reflectionClass = $parent;
        }

        $property = $reflectionClass->getProperty($propertyName);
        $property->setValue($controller, $value);
    }

    private function extractFormAction(string $html): string
    {
        preg_match('/<form[^>]+action="([^"]+)"/', $html, $matches);
        self::assertNotSame([], $matches, 'Search box form action was not rendered.');

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseQueryParams(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query, 'URL does not contain a query string: ' . $url);

        $params = [];
        parse_str($query, $params);
        return $params;
    }
}
