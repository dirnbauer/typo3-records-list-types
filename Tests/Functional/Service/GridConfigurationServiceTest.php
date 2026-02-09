<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\RecordsListTypes\Service\GridConfigurationService;

/**
 * Functional tests for GridConfigurationService.
 *
 * Tests TSconfig parsing for grid view per-table field mappings.
 */
final class GridConfigurationServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/records-list-types',
    ];

    private GridConfigurationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Pages.csv');
        $this->subject = $this->get(GridConfigurationService::class);
    }

    #[Test]
    public function getTableConfigReturnsTitleFieldFromTsconfig(): void
    {
        // Page 3 has: mod.web_list.gridView.table.tt_content.titleField = header
        $config = $this->subject->getTableConfig('tt_content', 3);

        self::assertSame('header', $config['titleField']);
    }

    #[Test]
    public function getTableConfigReturnsDescriptionField(): void
    {
        // Page 3 has: mod.web_list.gridView.table.tt_content.descriptionField = bodytext
        $config = $this->subject->getTableConfig('tt_content', 3);

        self::assertSame('bodytext', $config['descriptionField']);
    }

    #[Test]
    public function getTableConfigReturnsImageField(): void
    {
        // Page 3 has: mod.web_list.gridView.table.tt_content.imageField = image
        $config = $this->subject->getTableConfig('tt_content', 3);

        self::assertSame('image', $config['imageField']);
    }

    #[Test]
    public function getTableConfigFallsBackToTcaLabelField(): void
    {
        // Page 1 has no TSconfig for tt_content, should fall back to TCA label
        $config = $this->subject->getTableConfig('tt_content', 1);

        // tt_content TCA label is 'header'
        self::assertSame('header', $config['titleField']);
    }

    #[Test]
    public function getTableConfigFallsBackToUidForUnknownTable(): void
    {
        // Unknown table with no TCA - should fall back to 'uid'
        $config = $this->subject->getTableConfig('tx_nonexistent_table', 1);

        self::assertSame('uid', $config['titleField']);
    }

    #[Test]
    public function getTableConfigReturnsDescriptionFieldFromDefaultTsconfig(): void
    {
        // Default page.tsconfig sets pages.descriptionField = abstract
        $config = $this->subject->getTableConfig('pages', 1);

        self::assertSame('abstract', $config['descriptionField']);
    }

    #[Test]
    public function getTableConfigReturnsImageFieldFromDefaultTsconfig(): void
    {
        // Default page.tsconfig sets pages.imageField = media
        $config = $this->subject->getTableConfig('pages', 1);

        self::assertSame('media', $config['imageField']);
    }

    #[Test]
    public function getTableConfigReturnsNullForUnconfiguredTable(): void
    {
        // A table not mentioned in the default TSconfig should have null fields
        $config = $this->subject->getTableConfig('tx_nonexistent_table', 1);

        self::assertNull($config['descriptionField']);
        self::assertNull($config['imageField']);
    }

    #[Test]
    public function getTableConfigDefaultsPreviewToTrue(): void
    {
        $config = $this->subject->getTableConfig('pages', 1);

        self::assertTrue($config['preview']);
    }

    #[Test]
    public function getTableConfigRespectsPreviewDisabled(): void
    {
        // Page 6 has: mod.web_list.gridView.table.tt_content.preview = 0
        $config = $this->subject->getTableConfig('tt_content', 6);

        self::assertFalse($config['preview']);
    }

    #[Test]
    public function getTableConfigIncludesHiddenField(): void
    {
        $config = $this->subject->getTableConfig('tt_content', 1);

        self::assertArrayHasKey('hiddenField', $config);
        self::assertIsString($config['hiddenField']);
    }

    #[Test]
    public function getTableConfigCachesResults(): void
    {
        $config1 = $this->subject->getTableConfig('tt_content', 3);
        $config2 = $this->subject->getTableConfig('tt_content', 3);

        self::assertSame($config1, $config2);
    }

    #[Test]
    public function clearCacheResetsTableConfigCache(): void
    {
        $this->subject->getTableConfig('tt_content', 3);
        $this->subject->clearCache();

        // After clearing, should re-read from TSconfig (same result but fresh)
        $config = $this->subject->getTableConfig('tt_content', 3);
        self::assertSame('header', $config['titleField']);
    }

    #[Test]
    public function getColumnCountReturnsConfiguredValue(): void
    {
        // Page 3 has: mod.web_list.gridView.cols = 3
        $cols = $this->subject->getColumnCount(3);

        self::assertSame(3, $cols);
    }

    #[Test]
    public function getColumnCountReturnsDefaultWhenNotConfigured(): void
    {
        // Page 1 has no cols configured, default is 4
        $cols = $this->subject->getColumnCount(1);

        self::assertSame(4, $cols);
    }

    #[Test]
    public function getGlobalConfigReturnsExpectedStructure(): void
    {
        $config = $this->subject->getGlobalConfig(1);

        self::assertArrayHasKey('cols', $config);
        self::assertArrayHasKey('default', $config);
        self::assertArrayHasKey('allowedViews', $config);
        self::assertIsInt($config['cols']);
        self::assertIsString($config['default']);
        self::assertIsArray($config['allowedViews']);
    }

    #[Test]
    public function hasImageFieldReturnsTrueWhenConfigured(): void
    {
        // Page 3 has imageField = image for tt_content
        self::assertTrue($this->subject->hasImageField('tt_content', 3));
    }

    #[Test]
    public function hasImageFieldReturnsFalseForUnconfiguredTable(): void
    {
        // A table not mentioned in the default TSconfig
        self::assertFalse($this->subject->hasImageField('tx_nonexistent_table', 1));
    }

    #[Test]
    public function isPreviewEnabledReturnsTrueByDefault(): void
    {
        self::assertTrue($this->subject->isPreviewEnabled('pages', 1));
    }

    #[Test]
    public function isPreviewEnabledReturnsFalseWhenDisabled(): void
    {
        // Page 6 has: preview = 0
        self::assertFalse($this->subject->isPreviewEnabled('tt_content', 6));
    }

    #[Test]
    public function getConfiguredTablesReturnsTablesFromTsconfig(): void
    {
        // Page 3 has configurations for tt_content and pages
        $tables = $this->subject->getConfiguredTables(3);

        self::assertContains('tt_content', $tables);
        self::assertContains('pages', $tables);
    }

    #[Test]
    public function getConfiguredTablesReturnsDefaultTsconfigTables(): void
    {
        // Page 1 inherits from default page.tsconfig which configures several tables
        $tables = $this->subject->getConfiguredTables(1);

        self::assertContains('pages', $tables);
        self::assertContains('tt_content', $tables);
        self::assertContains('fe_users', $tables);
        self::assertContains('tx_news_domain_model_news', $tables);
    }
}
