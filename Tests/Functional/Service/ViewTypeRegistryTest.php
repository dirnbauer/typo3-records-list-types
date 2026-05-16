<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\RecordsListTypes\Service\ViewTypeRegistry;

/**
 * Functional tests for ViewTypeRegistry.
 *
 * Tests view type registration, TSconfig merging, and configuration retrieval
 * with a full TYPO3 backend and database.
 */
final class ViewTypeRegistryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/records-list-types',
    ];

    private ViewTypeRegistry $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Pages.csv');
        $this->subject = $this->get(ViewTypeRegistry::class);
    }

    #[Test]
    public function getViewTypesIncludesBuiltinTypes(): void
    {
        $types = $this->subject->getViewTypes(1);

        self::assertArrayHasKey('list', $types);
        self::assertArrayHasKey('grid', $types);
        self::assertArrayHasKey('compact', $types);
        self::assertArrayHasKey('teaser', $types);
    }

    #[Test]
    public function getViewTypesIncludesCustomTypeFromTsconfig(): void
    {
        // Page 5 defines a custom 'kanban' type via TSconfig
        $types = $this->subject->getViewTypes(5);

        self::assertArrayHasKey('kanban', $types);
        self::assertSame('Kanban', $types['kanban']['label']);
        self::assertSame('content-text', $types['kanban']['icon']);
    }

    #[Test]
    public function getViewTypeReturnsConfigForExistingType(): void
    {
        $config = $this->subject->getViewType('grid', 1);

        self::assertNotNull($config);
        self::assertArrayHasKey('label', $config);
        self::assertArrayHasKey('icon', $config);
    }

    #[Test]
    public function getViewTypeReturnsNullForNonexistentType(): void
    {
        self::assertNull($this->subject->getViewType('nonexistent', 1));
    }

    #[Test]
    public function hasViewTypeReturnsTrueForBuiltinType(): void
    {
        self::assertTrue($this->subject->hasViewType('list', 1));
        self::assertTrue($this->subject->hasViewType('grid', 1));
        self::assertTrue($this->subject->hasViewType('compact', 1));
        self::assertTrue($this->subject->hasViewType('teaser', 1));
    }

    #[Test]
    public function hasViewTypeReturnsTrueForCustomType(): void
    {
        // Page 5 has kanban type
        self::assertTrue($this->subject->hasViewType('kanban', 5));
    }

    #[Test]
    public function hasViewTypeReturnsFalseForMissing(): void
    {
        self::assertFalse($this->subject->hasViewType('nonexistent', 1));
    }

    #[Test]
    public function isBuiltinTypeReturnsTrueForBuiltins(): void
    {
        self::assertTrue($this->subject->isBuiltinType('list'));
        self::assertTrue($this->subject->isBuiltinType('grid'));
        self::assertTrue($this->subject->isBuiltinType('compact'));
        self::assertTrue($this->subject->isBuiltinType('teaser'));
    }

    #[Test]
    public function isBuiltinTypeReturnsFalseForCustomTypes(): void
    {
        self::assertFalse($this->subject->isBuiltinType('kanban'));
        self::assertFalse($this->subject->isBuiltinType('nonexistent'));
    }

    #[Test]
    public function shouldDelegateToParentReturnsTrueForListType(): void
    {
        // 'list' has handler => 'list'
        self::assertTrue($this->subject->shouldDelegateToParent('list', 1));
    }

    #[Test]
    public function shouldDelegateToParentReturnsFalseForGridType(): void
    {
        self::assertFalse($this->subject->shouldDelegateToParent('grid', 1));
    }

    #[Test]
    public function getDefaultViewTypeReturnsListByDefault(): void
    {
        // Page 1 has no viewMode.default set
        self::assertSame('list', $this->subject->getDefaultViewType(1));
    }

    #[Test]
    public function getDefaultViewTypeReturnsConfiguredDefault(): void
    {
        // Page 2 has: mod.web_list.viewMode.default = grid
        self::assertSame('grid', $this->subject->getDefaultViewType(2));
    }

    #[Test]
    public function getTemplatePathsReturnsExpectedKeysForGrid(): void
    {
        $paths = $this->subject->getTemplatePaths('grid', 1);

        self::assertArrayHasKey('template', $paths);
        self::assertArrayHasKey('partial', $paths);
        self::assertArrayHasKey('templateRootPaths', $paths);
        self::assertArrayHasKey('partialRootPaths', $paths);
        self::assertArrayHasKey('layoutRootPaths', $paths);
        self::assertSame('GridView', $paths['template']);
        self::assertSame('Card', $paths['partial']);
    }

    #[Test]
    public function getTemplatePathsReturnsExpectedKeysForCompact(): void
    {
        $paths = $this->subject->getTemplatePaths('compact', 1);

        self::assertSame('CompactView', $paths['template']);
        self::assertSame('CompactRow', $paths['partial']);
    }

    #[Test]
    public function getTemplatePathsReturnsExpectedKeysForTeaser(): void
    {
        $paths = $this->subject->getTemplatePaths('teaser', 1);

        self::assertSame('TeaserView', $paths['template']);
        self::assertSame('TeaserCard', $paths['partial']);
    }

    #[Test]
    public function getTemplatePathsFallsBackToGridForUnknownType(): void
    {
        $paths = $this->subject->getTemplatePaths('nonexistent', 1);

        self::assertSame('GridView', $paths['template']);
    }

    #[Test]
    public function getTemplatePathsForCustomTypeReturnsCustomTemplate(): void
    {
        // Page 5 has kanban type with template = KanbanView
        $paths = $this->subject->getTemplatePaths('kanban', 5);

        self::assertSame('KanbanView', $paths['template']);
        self::assertSame('KanbanCard', $paths['partial']);
    }

    #[Test]
    public function getCssFilesReturnsFileForGridType(): void
    {
        $files = $this->subject->getCssFiles('grid', 1);

        self::assertNotEmpty($files);
        self::assertStringContainsString('grid-view.css', $files[0]);
    }

    #[Test]
    public function getCssFilesReturnsEmptyForUnknownType(): void
    {
        $files = $this->subject->getCssFiles('nonexistent', 1);

        self::assertSame([], $files);
    }

    #[Test]
    public function getCssFilesReturnsCustomCssForCustomType(): void
    {
        // Page 5 has kanban type with css = EXT:my_ext/Resources/Public/Css/kanban.css
        $files = $this->subject->getCssFiles('kanban', 5);

        self::assertNotEmpty($files);
        self::assertStringContainsString('kanban.css', $files[0]);
    }

    #[Test]
    public function getJsModulesAlwaysIncludesBaseModule(): void
    {
        $modules = $this->subject->getJsModules('grid', 1);

        self::assertContains('@webconsulting/records-list-types/GridViewActions.js', $modules);
    }

    #[Test]
    public function getDisplayColumnsConfigReturnsTcaDefaultForGrid(): void
    {
        $config = $this->subject->getDisplayColumnsConfig('grid', 1);

        self::assertArrayHasKey('columns', $config);
        self::assertArrayHasKey('fromTCA', $config);
        self::assertTrue($config['fromTCA']);
    }

    #[Test]
    public function getDisplayColumnsConfigReturnsFalseFromTcaForTeaser(): void
    {
        $config = $this->subject->getDisplayColumnsConfig('teaser', 1);

        self::assertFalse($config['fromTCA']);
        self::assertNotEmpty($config['columns']);
    }

    #[Test]
    public function getDisplayColumnsConfigReturnsCustomColumnsForCustomType(): void
    {
        // Page 5 kanban: displayColumns = title,status, columnsFromTCA = 0
        $config = $this->subject->getDisplayColumnsConfig('kanban', 5);

        self::assertContains('title', $config['columns']);
        self::assertContains('status', $config['columns']);
        self::assertFalse($config['fromTCA']);
    }

    #[Test]
    public function getAllowedViewTypesFiltersToConfiguredTypes(): void
    {
        // Page 2: allowed = list,grid,compact
        $allowed = $this->subject->getAllowedViewTypes(2);

        self::assertArrayHasKey('list', $allowed);
        self::assertArrayHasKey('grid', $allowed);
        self::assertArrayHasKey('compact', $allowed);
        self::assertArrayNotHasKey('teaser', $allowed);
    }

    #[Test]
    public function getAllowedViewTypesReturnsAllWhenNotConfigured(): void
    {
        // Page 1 has no allowed restriction
        $allowed = $this->subject->getAllowedViewTypes(1);

        self::assertArrayHasKey('list', $allowed);
        self::assertArrayHasKey('grid', $allowed);
        self::assertArrayHasKey('compact', $allowed);
        self::assertArrayHasKey('teaser', $allowed);
    }

    #[Test]
    public function clearCacheAllowsReloading(): void
    {
        $types1 = $this->subject->getViewTypes(1);
        $this->subject->clearCache();
        $types2 = $this->subject->getViewTypes(1);

        self::assertSame(array_keys($types1), array_keys($types2));
    }
}
