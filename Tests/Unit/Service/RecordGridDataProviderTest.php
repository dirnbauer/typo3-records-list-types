<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\RecordsListTypes\Service\GridConfigurationService;
use Webconsulting\RecordsListTypes\Service\RecordGridDataProvider;
use Webconsulting\RecordsListTypes\Service\ThumbnailService;

final class RecordGridDataProviderTest extends TestCase
{
    private RecordGridDataProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new Context();
        $context->setAspect('workspace', new WorkspaceAspect(0));

        $tcaSchemaFactory = $this->createStub(TcaSchemaFactory::class);

        $this->subject = new RecordGridDataProvider(
            $this->createStub(ConnectionPool::class),
            $this->createStub(IconFactory::class),
            new GridConfigurationService($tcaSchemaFactory),
            new ThumbnailService($this->createStub(FileRepository::class)),
            $tcaSchemaFactory,
            $context,
        );
    }

    #[Test]
    #[DataProvider('recordsContainThumbnailsDataProvider')]
    public function recordsContainThumbnailsDetectsThumbnailUrls(array $records, bool $expected): void
    {
        self::assertSame($expected, $this->subject->recordsContainThumbnails($records));
    }

    /**
     * @return iterable<string, array{0: array<int, array<string, mixed>>, 1: bool}>
     */
    public static function recordsContainThumbnailsDataProvider(): iterable
    {
        yield 'empty list' => [[], false];
        yield 'no thumbnail urls' => [[['thumbnailUrl' => null], ['thumbnailUrl' => '']], false];
        yield 'has thumbnail url' => [[['thumbnailUrl' => '/fileadmin/test.jpg']], true];
    }
}
