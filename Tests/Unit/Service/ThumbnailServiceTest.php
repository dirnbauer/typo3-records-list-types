<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Webconsulting\RecordsListTypes\Service\ThumbnailService;

/**
 * Tests for the ThumbnailService.
 */
final class ThumbnailServiceTest extends TestCase
{
    private FileRepository&MockObject $fileRepositoryMock;
    private ResourceFactory&MockObject $resourceFactoryMock;
    private ThumbnailService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileRepositoryMock = $this->createMock(FileRepository::class);
        $this->resourceFactoryMock = $this->createMock(ResourceFactory::class);

        $this->subject = new ThumbnailService(
            $this->fileRepositoryMock,
            $this->resourceFactoryMock,
        );
    }

    #[Test]
    public function getDefaultDimensionsReturnsExpectedValues(): void
    {
        $dimensions = $this->subject->getDefaultDimensions();

        self::assertArrayHasKey('width', $dimensions);
        self::assertArrayHasKey('height', $dimensions);
        self::assertSame(400, $dimensions['width']);
        self::assertSame(225, $dimensions['height']);
    }

    #[Test]
    #[DataProvider('imageMimeTypeProvider')]
    public function isImageFileReturnsTrueForImageMimeTypes(string $mimeType): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock->method('getMimeType')->willReturn($mimeType);

        self::assertTrue($this->subject->isImageFile($fileMock));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function imageMimeTypeProvider(): array
    {
        return [
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml'],
            'bmp' => ['image/bmp'],
            'tiff' => ['image/tiff'],
        ];
    }

    #[Test]
    #[DataProvider('nonImageMimeTypeProvider')]
    public function isImageFileReturnsFalseForNonImageMimeTypes(string $mimeType): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $fileMock->method('getMimeType')->willReturn($mimeType);

        self::assertFalse($this->subject->isImageFile($fileMock));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonImageMimeTypeProvider(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'text' => ['text/plain'],
            'html' => ['text/html'],
            'json' => ['application/json'],
            'video' => ['video/mp4'],
            'audio' => ['audio/mpeg'],
            'zip' => ['application/zip'],
        ];
    }

    #[Test]
    public function getFirstImageReturnsNullWhenNoReferences(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->with('tt_content', 'image', 1)
            ->willReturn([]);

        self::assertNull($this->subject->getFirstImage('tt_content', 1, 'image'));
    }

    #[Test]
    public function getAllImagesReturnsEmptyArrayWhenNoReferences(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([]);

        self::assertSame([], $this->subject->getAllImages('tt_content', 1, 'image'));
    }

    #[Test]
    public function getThumbnailDataReturnsNotExistsWhenNoImage(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([]);

        $result = $this->subject->getThumbnailData('tt_content', 1, 'image');

        self::assertNull($result['file']);
        self::assertNull($result['url']);
        self::assertFalse($result['exists']);
    }

    #[Test]
    public function getFirstImageReturnsNullOnException(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willThrowException(new \RuntimeException('DB error'));

        self::assertNull($this->subject->getFirstImage('tt_content', 1, 'image'));
    }

    #[Test]
    public function getAllImagesReturnsEmptyArrayOnException(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willThrowException(new \RuntimeException('DB error'));

        self::assertSame([], $this->subject->getAllImages('tt_content', 1, 'image'));
    }

    #[Test]
    public function getFirstFileReferenceReturnsNullWhenNoReferences(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([]);

        self::assertNull($this->subject->getFirstFileReference('tt_content', 1, 'image'));
    }

    #[Test]
    public function getFirstFileReferenceReturnsNullOnException(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willThrowException(new \RuntimeException('DB error'));

        self::assertNull($this->subject->getFirstFileReference('tt_content', 1, 'image'));
    }
}
