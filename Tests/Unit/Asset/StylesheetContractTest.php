<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The views style TYPO3's own components with TYPO3's design tokens. Own
 * colours or colour-scheme queries would break dark mode: the backend sets
 * its scheme on <html>, which prefers-color-scheme does not see.
 */
final class StylesheetContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function stylesheetProvider(): iterable
    {
        $paths = glob(dirname(__DIR__, 3) . '/Resources/Public/Css/*.css');
        foreach ($paths === false ? [] : $paths as $path) {
            yield basename($path) => [$path];
        }
    }

    #[Test]
    #[DataProvider('stylesheetProvider')]
    public function colorsComeFromTypo3Tokens(string $path): void
    {
        $css = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($path));

        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, basename($path) . ' hard-codes a hex colour.');
        self::assertDoesNotMatchRegularExpression('/\b(?:rgba?|hsla?|oklch|lab|lch)\(/', $css, basename($path) . ' hard-codes a colour function.');
        self::assertStringNotContainsString('--bs-', $css, basename($path) . ' uses Bootstrap variables that do not follow the backend colour scheme.');
        self::assertStringNotContainsString('prefers-color-scheme', $css, basename($path) . ' queries the OS colour scheme instead of following the backend.');
    }

    #[Test]
    public function everyStylesheetTheRegistryLoadsExists(): void
    {
        $root = dirname(__DIR__, 3);
        $registry = (string)file_get_contents($root . '/Classes/Service/ViewTypeRegistry.php');
        preg_match_all('#EXT:records_list_types/(Resources/Public/Css/[a-z-]+\.css)#', $registry, $matches);

        self::assertNotSame([], $matches[1]);
        foreach (array_unique($matches[1]) as $relativePath) {
            self::assertFileExists($root . '/' . $relativePath);
        }
    }
}
