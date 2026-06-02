<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Backend\Context\PageContext;
use TYPO3\CMS\Backend\Domain\Model\Language\PageLanguageInformation;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\RecordsListTypes\Controller\RecordListController;
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
        $record = GeneralUtility::makeInstance(RecordViewEnrichmentService::class)->enrichRecordWithEditUrls([
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

    private function createControllerForPage(int $pageId): RecordListController
    {
        $controller = (new ReflectionClass(RecordListController::class))->newInstanceWithoutConstructor();
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
