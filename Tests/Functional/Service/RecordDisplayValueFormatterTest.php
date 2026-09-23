<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\RecordsListTypes\Service\RecordDisplayValueFormatter;

/**
 * Values read like in the list view, because they come from the same Core API.
 */
#[CoversClass(RecordDisplayValueFormatter::class)]
final class RecordDisplayValueFormatterTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/records-list-types',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUsers.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function richTextBecomesPlainTextWithoutEntities(): void
    {
        $row = ['uid' => 1, 'pid' => 1, 'bodytext' => "<p>Tom&nbsp;&amp; Jerry</p>\n<p>again</p>"];

        self::assertSame('Tom & Jerry again', new RecordDisplayValueFormatter()->formatFieldValue('tt_content', 'bodytext', $row));
    }

    #[Test]
    public function timestampsUseTheBackendDateFormat(): void
    {
        $row = ['uid' => 1, 'pid' => 1, 'crdate' => 1_767_225_600];

        self::assertSame(
            BackendUtility::datetime(1_767_225_600),
            new RecordDisplayValueFormatter()->formatFieldValue('tt_content', 'crdate', $row),
        );
    }

    #[Test]
    public function selectValuesShowTheirItemLabel(): void
    {
        $row = ['uid' => 1, 'pid' => 1, 'CType' => 'text'];
        $expected = BackendUtility::getProcessedValue('tt_content', 'CType', 'text');

        self::assertIsString($expected);
        self::assertNotSame('text', $expected);
        self::assertSame($expected, new RecordDisplayValueFormatter()->formatFieldValue('tt_content', 'CType', $row));
    }

    #[Test]
    public function emptyValuesStayEmpty(): void
    {
        self::assertSame('', new RecordDisplayValueFormatter()->formatFieldValue('tt_content', 'header', ['uid' => 1, 'header' => '']));
        self::assertSame('', new RecordDisplayValueFormatter()->formatFieldValue('tt_content', 'header', ['uid' => 1]));
    }
}
