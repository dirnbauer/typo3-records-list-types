<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Field\CategoryFieldType;
use TYPO3\CMS\Core\Schema\Field\FieldCollection;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\RecordsListTypes\Service\RecordFilterConfigurationService;

final class RecordFilterConfigurationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        unset($tca['tx_demo']);
        $GLOBALS['TCA'] = $tca;
        parent::tearDown();
    }

    #[Test]
    public function resolveFieldsResolvesCategoryAliasFromPreparedTcaSchema(): void
    {
        $subject = $this->createSubject($this->createSchema([
            'categories' => $this->createCategoryField('categories', 'manyToMany'),
        ]));

        self::assertSame(['categories'], $subject->resolveFields('tx_demo', 'categories'));
    }

    #[Test]
    public function resolveFieldsResolvesSingularCategoryAliasFromPreparedTcaSchema(): void
    {
        $subject = $this->createSubject($this->createSchema([
            'category' => $this->createCategoryField('category', 'manyToMany'),
        ]));

        self::assertSame(['category'], $subject->resolveFields('tx_demo', 'category'));
    }

    #[Test]
    public function resolveFieldsIgnoresOneToManyCategoryFields(): void
    {
        $subject = $this->createSubject($this->createSchema([
            'selected_category' => $this->createCategoryField('selected_category', 'oneToMany'),
        ]));

        self::assertSame([], $subject->resolveFields('tx_demo', 'categories'));
    }

    #[Test]
    public function textFilterExposesResolvedFieldsAsCommaSeparatedList(): void
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $tca['tx_demo'] = [
            'ctrl' => [
                'label' => 'title',
            ],
            'columns' => [
                'title' => ['label' => 'Title'],
                'nav_title' => ['label' => 'Navigation title'],
                'abstract' => ['label' => 'Abstract'],
            ],
        ];
        $GLOBALS['TCA'] = $tca;
        $subject = $this->createSubject($this->createSchema([]));

        $method = new ReflectionMethod(RecordFilterConfigurationService::class, 'buildTextFilter');
        $filter = $method->invoke($subject, 'tx_demo', 'title', [
            'fields' => 'title,nav_title,abstract',
        ]);

        self::assertIsArray($filter);
        self::assertSame(['title', 'nav_title', 'abstract'], $filter['fields']);
        self::assertSame('title, nav_title, abstract', $filter['fieldList']);
    }

    /**
     * @param array<string, CategoryFieldType> $fields
     */
    private function createSchema(array $fields): TcaSchema
    {
        return new TcaSchema('tx_demo', new FieldCollection($fields), []);
    }

    private function createCategoryField(string $name, string $relationship): CategoryFieldType
    {
        return new CategoryFieldType($name, [
            'type' => 'category',
            'label' => 'Categories',
            'relationship' => $relationship,
        ], []);
    }

    private function createSubject(TcaSchema $schema): RecordFilterConfigurationService
    {
        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->expects(self::atLeastOnce())->method('has')->with('tx_demo')->willReturn(true);
        $schemaFactory->expects(self::atLeastOnce())->method('get')->with('tx_demo')->willReturn($schema);

        return new RecordFilterConfigurationService(
            $schemaFactory,
            self::createStub(ConnectionPool::class),
        );
    }
}
