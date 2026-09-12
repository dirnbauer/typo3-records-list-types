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
    private function getBackendTemplates(): array
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
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
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

    #[Test]
    public function visibilityTogglesCarryStateAwareAccessibleNames(): void
    {
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
            $template = (string) file_get_contents($templatePath);

            self::assertSame(
                substr_count($template, 'data-gridview-action="show"'),
                substr_count($template, 'aria-label="{f:translate(key: \'records_list_types.messages:action.unhide\')}"'),
                $relativePath . ': every unhide toggle needs the "Unhide record" accessible name.',
            );
            self::assertSame(
                substr_count($template, 'data-gridview-action="hide"'),
                substr_count($template, 'aria-label="{f:translate(key: \'records_list_types.messages:action.hide\')}"'),
                $relativePath . ': every hide toggle needs the "Hide record" accessible name.',
            );
            self::assertStringNotContainsString('(currently', $template, $relativePath . ' must not append the state in parentheses.');
        }
    }

    #[Test]
    public function sortingModeToggleExposesThePressedState(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/SortingModeToggle.html');

        self::assertStringContainsString('aria-pressed="true"', $template);
        self::assertStringContainsString('aria-pressed="false"', $template);
        self::assertStringContainsString('role="button"', $template, 'Inactive segments are links and need the button role for aria-pressed.');
    }

    #[Test]
    public function iconOnlyRecordActionsHaveAccessibleNames(): void
    {
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
            $template = (string) file_get_contents($templatePath);
            preg_match_all('/<(?:button|a|typo3-backend-contextual-record-edit-trigger|typo3-backend-localization-button)\b[^>]*data-gridview-action="(?:delete|info)"[^>]*>/s', $template, $matches);
            foreach ($matches[0] as $element) {
                if (str_contains($element, 'dropdown-item')) {
                    continue; // menu entries carry visible text
                }
                self::assertMatchesRegularExpression('/\b(?:aria-label|title)="\{f:translate\(/', $element, $relativePath . ': icon-only action lacks an accessible name: ' . $element);
            }
        }
    }
}
