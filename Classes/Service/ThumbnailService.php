<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Exception;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * ThumbnailService - Handles image thumbnail resolution and generation.
 *
 * Resolves FAL file references from records and prepares them for
 * display as thumbnails in the Grid View cards.
 */
final class ThumbnailService implements SingletonInterface
{
    /** Default thumbnail dimensions. */
    private const DEFAULT_WIDTH = 400;
    private const DEFAULT_HEIGHT = 225;

    public function __construct(
        private readonly FileRepository $fileRepository,
    ) {}

    /**
     * Get the first image file from a record's FAL field.
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @param string $fieldName The FAL field name
     * @return FileInterface|null The file object or null if not found
     */
    public function getFirstImage(string $table, int $uid, string $fieldName): ?FileInterface
    {
        try {
            $fileReferences = $this->fileRepository->findByRelation(
                $table,
                $fieldName,
                $uid,
            );

            if ($fileReferences === []) {
                return null;
            }

            // Get the first file reference
            $firstReference = reset($fileReferences);

            if ($firstReference instanceof FileReference) {
                $file = $firstReference->getOriginalFile();

                // Only return image files
                if ($this->isImageFile($file)) {
                    return $file;
                }
            }

            return null;
        } catch (Exception $e) {
            // Log error but don't break rendering
            return null;
        }
    }

    /**
     * Get all images from a record's FAL field.
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @param string $fieldName The FAL field name
     * @return FileInterface[] Array of file objects
     */
    public function getAllImages(string $table, int $uid, string $fieldName): array
    {
        try {
            $fileReferences = $this->fileRepository->findByRelation(
                $table,
                $fieldName,
                $uid,
            );

            $images = [];
            foreach ($fileReferences as $reference) {
                if ($reference instanceof FileReference) {
                    $file = $reference->getOriginalFile();
                    if ($this->isImageFile($file)) {
                        $images[] = $file;
                    }
                }
            }

            return $images;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Check if a file is an image.
     *
     * @param FileInterface $file The file to check
     * @return bool True if the file is an image
     */
    public function isImageFile(FileInterface $file): bool
    {
        $mimeType = $file->getMimeType();
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Get the thumbnail URL for a file.
     *
     * @param FileInterface $file The file to get a thumbnail for
     * @param int $width Thumbnail width
     * @param int $height Thumbnail height
     * @return string|null The thumbnail URL or null if processing failed
     */
    public function getThumbnailUrl(
        FileInterface $file,
        int $width = self::DEFAULT_WIDTH,
        int $height = self::DEFAULT_HEIGHT,
    ): ?string {
        try {
            $processingInstructions = [
                'width' => $width . 'c',
                'height' => $height . 'c',
            ];

            if (!$file instanceof File) {
                return null;
            }
            $processedFile = $file->process(
                ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
                $processingInstructions,
            );

            return $processedFile->getPublicUrl();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get thumbnail data for a record.
     *
     * Returns an array with file and URL information suitable for Fluid templates.
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @param string $fieldName The FAL field name
     * @return array{file: ?FileInterface, url: ?string, exists: bool}
     */
    public function getThumbnailData(string $table, int $uid, string $fieldName): array
    {
        $file = $this->getFirstImage($table, $uid, $fieldName);

        if ($file === null) {
            return [
                'file' => null,
                'url' => null,
                'exists' => false,
            ];
        }

        return [
            'file' => $file,
            'url' => $this->getThumbnailUrl($file),
            'exists' => true,
        ];
    }

    /**
     * Get the FileReference object for a record (for use in Fluid templates).
     *
     * @param string $table The database table name
     * @param int $uid The record UID
     * @param string $fieldName The FAL field name
     * @return FileReference|null The file reference or null
     */
    public function getFirstFileReference(string $table, int $uid, string $fieldName): ?FileReference
    {
        try {
            $fileReferences = $this->fileRepository->findByRelation(
                $table,
                $fieldName,
                $uid,
            );

            if ($fileReferences === []) {
                return null;
            }

            $firstReference = reset($fileReferences);

            if ($firstReference instanceof FileReference && $this->isImageFile($firstReference->getOriginalFile())) {
                return $firstReference;
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get default thumbnail dimensions.
     *
     * @return array{width: int, height: int}
     */
    public function getDefaultDimensions(): array
    {
        return [
            'width' => self::DEFAULT_WIDTH,
            'height' => self::DEFAULT_HEIGHT,
        ];
    }
}
