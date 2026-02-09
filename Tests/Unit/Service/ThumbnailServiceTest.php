<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ProcessedFile;
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

    // ========================================================================
    // getFirstImage
    // ========================================================================

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
    public function getFirstImageReturnsFileWhenImageReferenceExists(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock->method('getMimeType')->willReturn('image/jpeg');

        $fileRefMock = $this->createMock(FileReference::class);
        $fileRefMock->method('getOriginalFile')->willReturn($fileMock);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$fileRefMock]);

        $result = $this->subject->getFirstImage('tt_content', 1, 'image');

        self::assertSame($fileMock, $result);
    }

    #[Test]
    public function getFirstImageReturnsNullWhenReferenceIsNotImage(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock->method('getMimeType')->willReturn('application/pdf');

        $fileRefMock = $this->createMock(FileReference::class);
        $fileRefMock->method('getOriginalFile')->willReturn($fileMock);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$fileRefMock]);

        self::assertNull($this->subject->getFirstImage('tt_content', 1, 'image'));
    }

    #[Test]
    public function getFirstImageReturnsNullOnException(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willThrowException(new RuntimeException('DB error'));

        self::assertNull($this->subject->getFirstImage('tt_content', 1, 'image'));
    }

    #[Test]
    public function getFirstImageReturnsNullWhenReferenceIsNotFileReference(): void
    {
        // Simulate a non-FileReference object in the array
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([new stdClass()]);

        self::assertNull($this->subject->getFirstImage('tt_content', 1, 'image'));
    }

    // ========================================================================
    // getAllImages
    // ========================================================================

    #[Test]
    public function getAllImagesReturnsEmptyArrayWhenNoReferences(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([]);

        self::assertSame([], $this->subject->getAllImages('tt_content', 1, 'image'));
    }

    #[Test]
    public function getAllImagesReturnsOnlyImageFiles(): void
    {
        $imageMock = $this->createMock(File::class);
        $imageMock->method('getMimeType')->willReturn('image/png');

        $pdfMock = $this->createMock(File::class);
        $pdfMock->method('getMimeType')->willReturn('application/pdf');

        $imageRef = $this->createMock(FileReference::class);
        $imageRef->method('getOriginalFile')->willReturn($imageMock);

        $pdfRef = $this->createMock(FileReference::class);
        $pdfRef->method('getOriginalFile')->willReturn($pdfMock);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$imageRef, $pdfRef]);

        $result = $this->subject->getAllImages('tt_content', 1, 'media');

        self::assertCount(1, $result);
        self::assertSame($imageMock, $result[0]);
    }

    #[Test]
    public function getAllImagesReturnsMultipleImages(): void
    {
        $img1 = $this->createMock(File::class);
        $img1->method('getMimeType')->willReturn('image/jpeg');

        $img2 = $this->createMock(File::class);
        $img2->method('getMimeType')->willReturn('image/webp');

        $ref1 = $this->createMock(FileReference::class);
        $ref1->method('getOriginalFile')->willReturn($img1);

        $ref2 = $this->createMock(FileReference::class);
        $ref2->method('getOriginalFile')->willReturn($img2);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$ref1, $ref2]);

        $result = $this->subject->getAllImages('tt_content', 1, 'image');

        self::assertCount(2, $result);
    }

    #[Test]
    public function getAllImagesReturnsEmptyArrayOnException(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willThrowException(new RuntimeException('DB error'));

        self::assertSame([], $this->subject->getAllImages('tt_content', 1, 'image'));
    }

    // ========================================================================
    // getThumbnailUrl
    // ========================================================================

    #[Test]
    public function getThumbnailUrlReturnsUrlFromProcessedFile(): void
    {
        $processedMock = $this->createMock(ProcessedFile::class);
        $processedMock->method('getPublicUrl')->willReturn('/fileadmin/_processed_/test.jpg');

        // process() exists on File, not FileInterface
        $fileMock = $this->createMock(File::class);
        $fileMock->method('process')
            ->with(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, self::anything())
            ->willReturn($processedMock);

        $url = $this->subject->getThumbnailUrl($fileMock);

        self::assertSame('/fileadmin/_processed_/test.jpg', $url);
    }

    #[Test]
    public function getThumbnailUrlUsesCustomDimensions(): void
    {
        $processedMock = $this->createMock(ProcessedFile::class);
        $processedMock->method('getPublicUrl')->willReturn('/test.jpg');

        $fileMock = $this->createMock(File::class);
        $fileMock->expects(self::once())
            ->method('process')
            ->with(
                ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
                ['width' => '200c', 'height' => '150c'],
            )
            ->willReturn($processedMock);

        $this->subject->getThumbnailUrl($fileMock, 200, 150);
    }

    #[Test]
    public function getThumbnailUrlReturnsNullOnException(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock->method('process')
            ->willThrowException(new RuntimeException('Processing failed'));

        self::assertNull($this->subject->getThumbnailUrl($fileMock));
    }

    // ========================================================================
    // getThumbnailData
    // ========================================================================

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
    public function getThumbnailDataReturnsExistsWithFileAndUrl(): void
    {
        $processedMock = $this->createMock(ProcessedFile::class);
        $processedMock->method('getPublicUrl')->willReturn('/test.jpg');

        $fileMock = $this->createMock(File::class);
        $fileMock->method('getMimeType')->willReturn('image/jpeg');
        $fileMock->method('process')->willReturn($processedMock);

        $fileRefMock = $this->createMock(FileReference::class);
        $fileRefMock->method('getOriginalFile')->willReturn($fileMock);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$fileRefMock]);

        $result = $this->subject->getThumbnailData('tt_content', 1, 'image');

        self::assertSame($fileMock, $result['file']);
        self::assertSame('/test.jpg', $result['url']);
        self::assertTrue($result['exists']);
    }

    // ========================================================================
    // getFirstFileReference
    // ========================================================================

    #[Test]
    public function getFirstFileReferenceReturnsNullWhenNoReferences(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([]);

        self::assertNull($this->subject->getFirstFileReference('tt_content', 1, 'image'));
    }

    #[Test]
    public function getFirstFileReferenceReturnsReferenceForImageFile(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock->method('getMimeType')->willReturn('image/jpeg');

        $fileRefMock = $this->createMock(FileReference::class);
        $fileRefMock->method('getOriginalFile')->willReturn($fileMock);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$fileRefMock]);

        $result = $this->subject->getFirstFileReference('tt_content', 1, 'image');

        self::assertSame($fileRefMock, $result);
    }

    #[Test]
    public function getFirstFileReferenceReturnsNullForNonImageFile(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock->method('getMimeType')->willReturn('application/pdf');

        $fileRefMock = $this->createMock(FileReference::class);
        $fileRefMock->method('getOriginalFile')->willReturn($fileMock);

        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willReturn([$fileRefMock]);

        self::assertNull($this->subject->getFirstFileReference('tt_content', 1, 'image'));
    }

    #[Test]
    public function getFirstFileReferenceReturnsNullOnException(): void
    {
        $this->fileRepositoryMock
            ->method('findByRelation')
            ->willThrowException(new RuntimeException('DB error'));

        self::assertNull($this->subject->getFirstFileReference('tt_content', 1, 'image'));
    }
}
