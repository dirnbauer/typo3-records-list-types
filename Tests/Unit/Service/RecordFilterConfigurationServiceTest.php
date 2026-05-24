<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        unset($GLOBALS['TCA']['tx_demo']);
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
            $this->createStub(ConnectionPool::class),
        );
    }
}
