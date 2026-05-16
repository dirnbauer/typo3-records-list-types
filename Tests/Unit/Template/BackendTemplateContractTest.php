<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Template;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class BackendTemplateContractTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function getBackendTemplates(): array
    {
        $basePath = dirname(__DIR__, 3) . '/Resources/Private';
        $templates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->getExtension() !== 'html') {
                continue;
            }

            $relativePath = str_replace($basePath . '/', '', $file->getPathname());
            $templates[$relativePath] = $file->getPathname();
        }

        ksort($templates);
        return $templates;
    }

    #[Test]
    public function backendTemplatesDoNotUseFrontendContentAreaRendering(): void
    {
        foreach (self::getBackendTemplates() as $relativePath => $templatePath) {
            $template = (string) file_get_contents($templatePath);

            self::assertStringNotContainsString('f:render.contentArea', $template, $relativePath);
            self::assertStringNotContainsString('f:mark.contentArea', $template, $relativePath);
            self::assertStringNotContainsString('lib.dynamicContent', $template, $relativePath);
            self::assertStringNotContainsString('v:content.render', $template, $relativePath);
            self::assertStringNotContainsString('flux:content.render', $template, $relativePath);
        }
    }

    #[Test]
    public function backendRecordTemplatesKeepContextualEditTriggers(): void
    {
        $templateBase = dirname(__DIR__, 3) . '/Resources/Private';
        $recordTemplates = [
            'Partials/Card.html',
            'Partials/CompactRow.html',
            'Partials/TeaserCard.html',
            'Partials/TranslationRowCompact.html',
            'Partials/TranslationRowTeaser.html',
            'Partials/TranslationStrip.html',
            'Templates/GenericView.html',
        ];

        foreach ($recordTemplates as $relativePath) {
            $template = (string) file_get_contents($templateBase . '/' . $relativePath);

            self::assertStringContainsString(
                'typo3-backend-contextual-record-edit-trigger',
                $template,
                $relativePath . ' must keep backend contextual editing instead of frontend Visual Editor markers.',
            );
        }
    }
}
