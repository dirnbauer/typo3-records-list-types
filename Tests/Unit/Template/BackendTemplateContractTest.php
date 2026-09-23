<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackendTemplateContractTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function getBackendTemplates(): array
    {
        $basePath = dirname(__DIR__, 3) . '/Resources/Private';
        $templates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
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
            $template = (string)file_get_contents($templatePath);

            self::assertStringNotContainsString('f:render.contentArea', $template, $relativePath);
            self::assertStringNotContainsString('f:mark.contentArea', $template, $relativePath);
            self::assertStringNotContainsString('lib.dynamicContent', $template, $relativePath);
            self::assertStringNotContainsString('v:content.render', $template, $relativePath);
            self::assertStringNotContainsString('flux:content.render', $template, $relativePath);
        }
    }

    #[Test]
    public function recordTitlesKeepTheContextualEditTrigger(): void
    {
        $templateBase = dirname(__DIR__, 3) . '/Resources/Private';
        $title = (string)file_get_contents($templateBase . '/Partials/Record/Title.html');
        self::assertStringContainsString(
            'typo3-backend-contextual-record-edit-trigger',
            $title,
            'Record titles must keep backend contextual editing instead of frontend Visual Editor markers.',
        );

        foreach (['Partials/Card.html', 'Partials/CompactRow.html', 'Partials/TeaserCard.html', 'Partials/TranslationRowCompact.html', 'Partials/TranslationStrip.html', 'Templates/GenericView.html'] as $relativePath) {
            self::assertStringContainsString(
                'partial="Record/Title"',
                (string)file_get_contents($templateBase . '/' . $relativePath),
                $relativePath . ' must render record titles through Record/Title.',
            );
        }
    }

    #[Test]
    public function viewsRenderCoreRecordControlsAndIcons(): void
    {
        $templateBase = dirname(__DIR__, 3) . '/Resources/Private';
        foreach (['Partials/Card.html', 'Partials/CompactRow.html', 'Partials/TeaserCard.html', 'Partials/TranslationRowCompact.html', 'Templates/GenericView.html'] as $relativePath) {
            $template = (string)file_get_contents($templateBase . '/' . $relativePath);
            self::assertStringContainsString('partial="Record/Controls"', $template, $relativePath . ' must render Core\'s control panel.');
            self::assertStringContainsString('partial="Record/Icon"', $template, $relativePath . ' must render the record icon with its context menu.');
        }
    }

    #[Test]
    public function visibilityTogglesCarryStateAwareAccessibleNames(): void
    {
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
            $template = (string)file_get_contents($templatePath);

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
    public function sortingModeToggleMarksTheActiveModeAsCurrent(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/SortingModeToggle.html');

        self::assertStringContainsString('aria-current="true"', $template);
        self::assertStringNotContainsString('role="button"', $template, 'Mode links navigate; a button role would also promise the Space key.');
    }

    #[Test]
    public function menusOpenAsPopoversSoCardsAndScrollingTablesCannotClipThem(): void
    {
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
            self::assertStringNotContainsString(
                'data-bs-toggle="dropdown"',
                (string)file_get_contents($templatePath),
                $relativePath . ' must open its menu with popovertarget, like the rest of the views.',
            );
        }
    }

    #[Test]
    public function onlyCoreGeneratedFragmentsAreRenderedRaw(): void
    {
        $allowed = ['{record.iconHtml}', '{record.controlsHtml}', '{body}'];
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
            $template = (string)file_get_contents($templatePath);
            preg_match_all('/<f:format\.raw>(.*?)<\/f:format\.raw>/s', $template, $matches);
            foreach ($matches[1] as $content) {
                if (str_contains($content, '{pageInput}') || str_contains($content, 'pagination.pageOfTotal')) {
                    continue; // the page input replaces a placeholder inside a translated sentence
                }
                self::assertContains(trim($content), $allowed, $relativePath . ' renders unescaped output: ' . trim($content));
            }
        }
    }

    #[Test]
    public function recordCheckboxesHaveAnAccessibleName(): void
    {
        $checkbox = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Partials/Record/Checkbox.html');

        self::assertStringContainsString('t3js-multi-record-selection-check', $checkbox);
        self::assertStringContainsString("aria-label=\"{f:translate(key: 'records_list_types.messages:a11y.selectRecord'", $checkbox);
    }

    #[Test]
    public function iconOnlyRecordActionsHaveAccessibleNames(): void
    {
        foreach ($this->getBackendTemplates() as $relativePath => $templatePath) {
            $template = (string)file_get_contents($templatePath);
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
