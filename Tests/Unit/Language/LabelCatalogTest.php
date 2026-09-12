<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Language;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SimpleXMLElement;
use SplFileInfo;

/**
 * Guards the label catalog: every referenced key exists, every unit carries
 * context for translators, target files mirror the source, and templates or
 * JavaScript never fall back to hard-coded English.
 */
final class LabelCatalogTest extends TestCase
{
    private const string XLIFF_NAMESPACE = 'urn:oasis:names:tc:xliff:document:2.0';
    private const string DOMAIN = 'records_list_types.messages';
    private const string SOURCE_FILE = 'Resources/Private/Language/locallang.xlf';
    private const array TARGET_LANGUAGES = ['de', 'fr', 'es', 'it'];
    private const array MACHINE_DRAFT_LANGUAGES = ['fr', 'es', 'it'];

    /** Core labels that the JavaScript reads from TYPO3.lang. */
    private const array CORE_JAVASCRIPT_KEYS = ['labels.no_title'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogFileProvider(): iterable
    {
        yield 'en' => [self::SOURCE_FILE];
        foreach (self::TARGET_LANGUAGES as $language) {
            yield $language => ['Resources/Private/Language/' . $language . '.locallang.xlf'];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targetLanguageProvider(): iterable
    {
        foreach (self::TARGET_LANGUAGES as $language) {
            yield $language => [$language];
        }
    }

    #[Test]
    public function sourceCatalogIsAnXliff2TemplateWithOneFileElement(): void
    {
        $xml = $this->loadXml(self::SOURCE_FILE);

        self::assertSame('2.0', (string) $xml['version']);
        self::assertSame('en', (string) $xml['srcLang']);
        self::assertNull($xml['trgLang'] ?? null, 'The source catalog must not declare a target language.');
        self::assertCount(1, $xml->xpath('/x:xliff/x:file') ?: []);
    }

    #[Test]
    #[DataProvider('targetLanguageProvider')]
    public function targetCatalogMirrorsTheSourceUnits(string $language): void
    {
        $sourceUnits = $this->loadUnits(self::SOURCE_FILE);
        $file = 'Resources/Private/Language/' . $language . '.locallang.xlf';
        $targetUnits = $this->loadUnits($file);

        self::assertSame($language, (string) $this->loadXml($file)['trgLang']);
        self::assertSame(array_keys($sourceUnits), array_keys($targetUnits), $file . ' must contain the same unit ids in the same order as the source.');
        foreach ($targetUnits as $id => $unit) {
            self::assertSame($sourceUnits[$id]['source'], $unit['source'], $file . ': source text of "' . $id . '" differs from locallang.xlf.');
            self::assertNotSame('', $unit['target'], $file . ': unit "' . $id . '" has no target.');
            self::assertNotSame('', $unit['state'], $file . ': unit "' . $id . '" has no segment state.');
        }
    }

    #[Test]
    #[DataProvider('catalogFileProvider')]
    public function everyUnitCarriesATranslatorNote(string $file): void
    {
        foreach ($this->loadUnits($file) as $id => $unit) {
            self::assertNotSame('', trim($unit['note']), $file . ': unit "' . $id . '" has no <note>.');
        }
    }

    #[Test]
    #[DataProvider('catalogFileProvider')]
    public function catalogUsesIcuPlaceholdersOnly(string $file): void
    {
        foreach ($this->loadUnits($file) as $id => $unit) {
            foreach ([$unit['source'], $unit['target']] as $text) {
                self::assertDoesNotMatchRegularExpression('/%(?:\d+\$)?[sd]/', $text, $file . ': unit "' . $id . '" uses sprintf placeholders.');
            }
        }
    }

    #[Test]
    public function sourceLabelsUseSentenceCaseInsteadOfAllCaps(): void
    {
        foreach ($this->loadUnits(self::SOURCE_FILE) as $id => $unit) {
            self::assertDoesNotMatchRegularExpression('/\b[A-Z]{3,}\b/', $unit['source'], 'Unit "' . $id . '" shouts in all caps.');
        }
    }

    #[Test]
    public function machineDraftsAreMarkedAsPendingReview(): void
    {
        foreach (self::MACHINE_DRAFT_LANGUAGES as $language) {
            $file = 'Resources/Private/Language/' . $language . '.locallang.xlf';
            $xml = $this->loadXml($file);
            $fileNotes = $xml->xpath('/x:xliff/x:file/x:notes/x:note') ?: [];
            self::assertNotSame([], $fileNotes, $file . ' must carry a file-level review note.');
            self::assertStringContainsString('Machine draft', (string) $fileNotes[0]);
            foreach ($this->loadUnits($file) as $id => $unit) {
                self::assertSame('translated', $unit['state'], $file . ': draft unit "' . $id . '" must not claim a reviewed state.');
            }
        }
    }

    #[Test]
    public function everyReferencedKeyExistsInTheSourceCatalog(): void
    {
        $units = $this->loadUnits(self::SOURCE_FILE);
        $missing = [];
        foreach ($this->collectReferencedKeys() as $key => $locations) {
            if (!isset($units[$key])) {
                $missing[] = $key . ' (' . implode(', ', $locations) . ')';
            }
        }

        self::assertSame([], $missing, 'Referenced label keys are missing from locallang.xlf.');
    }

    #[Test]
    public function deprecatedAliasesAreNotReferencedAnymore(): void
    {
        $deprecated = array_keys(array_filter($this->loadUnits(self::SOURCE_FILE), static fn(array $unit): bool => $unit['deprecated']));
        self::assertNotSame([], $deprecated, 'The 1.x aliases are expected to stay in the catalog until 2.0.');

        $referenced = $this->collectReferencedKeys();
        foreach ($deprecated as $key) {
            self::assertArrayNotHasKey($key, $referenced, 'Deprecated alias "' . $key . '" is still referenced.');
        }
    }

    #[Test]
    public function templatesContainNoHardCodedEnglish(): void
    {
        foreach ($this->getFiles('Resources/Private', 'html') as $relativePath => $path) {
            $template = (string) file_get_contents($path);

            self::assertStringNotContainsString('LLL:EXT:', $template, $relativePath . ' must reference labels through translation domains.');
            self::assertStringNotContainsString('extensionName:', $template, $relativePath . ' must reference labels through translation domains.');
            self::assertDoesNotMatchRegularExpression('/<f:translate\b[^>]*\bdefault="/', $template, $relativePath . ' duplicates label text in a default attribute.');
            self::assertDoesNotMatchRegularExpression('/f:translate\([^)]*\bdefault:/', $template, $relativePath . ' duplicates label text in a default argument.');
            self::assertDoesNotMatchRegularExpression('/\b(?:title|aria-label)="[^"{]*[A-Za-z][^"{]*"/', $template, $relativePath . ' has an untranslated title or aria-label literal.');
            self::assertDoesNotMatchRegularExpression('/<em>\s*[A-Za-z][^<{]*<\/em>/', $template, $relativePath . ' has an untranslated <em> literal.');
        }
    }

    #[Test]
    public function javaScriptReadsLabelsInsteadOfLiterals(): void
    {
        $units = $this->loadUnits(self::SOURCE_FILE);

        foreach ($this->getFiles('Resources/Public/JavaScript', 'js') as $relativePath => $path) {
            $script = (string) file_get_contents($path);

            self::assertDoesNotMatchRegularExpression('/showNotification\(\s*[\'"]/', $script, $relativePath . ' passes a literal to showNotification().');

            preg_match_all('/this\.(?:lang|label)\(\'([A-Za-z0-9_.]+)\',\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $script, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $key, $fallback]) {
                if (in_array($key, self::CORE_JAVASCRIPT_KEYS, true)) {
                    continue;
                }
                self::assertArrayHasKey($key, $units, $relativePath . ' references the unknown label "' . $key . '".');
                self::assertSame($units[$key]['source'], $fallback, $relativePath . ': the fallback of "' . $key . '" drifted from locallang.xlf.');
            }
        }
    }

    #[Test]
    public function javaScriptLabelPrefixesAreRegisteredForInlineExport(): void
    {
        $registered = [];
        foreach (['Classes/Controller/RecordListController.php', 'Classes/Controller/ContentElement/ElementHistoryController.php'] as $file) {
            $php = (string) file_get_contents($this->root() . '/' . $file);
            preg_match_all('/addInlineLanguageLabelFile\([^)]*,\s*\'([a-zA-Z0-9]+\.)\'\)/', $php, $matches);
            $registered = array_merge($registered, $matches[1]);
        }
        preg_match_all('/foreach \(\[([^\]]+)\] as \$labelPrefix\)/', (string) file_get_contents($this->root() . '/Classes/Controller/RecordListController.php'), $loop);
        self::assertNotSame([], $loop[1], 'RecordListController must register the JavaScript label prefixes.');
        preg_match_all('/\'([a-zA-Z0-9]+\.)\'/', $loop[1][0], $loopPrefixes);
        $registered = array_values(array_unique(array_merge($registered, $loopPrefixes[1])));

        $units = array_keys($this->loadUnits(self::SOURCE_FILE));
        foreach ($registered as $prefix) {
            self::assertNotSame([], array_filter($units, static fn(string $id): bool => str_starts_with($id, $prefix)), 'Registered prefix "' . $prefix . '" matches no unit.');
        }

        foreach ($this->getFiles('Resources/Public/JavaScript', 'js') as $relativePath => $path) {
            preg_match_all('/this\.(?:lang|label)\(\'([A-Za-z0-9_.]+)\'/', (string) file_get_contents($path), $matches);
            foreach (array_unique($matches[1]) as $key) {
                if (in_array($key, self::CORE_JAVASCRIPT_KEYS, true)) {
                    continue;
                }
                $prefix = substr($key, 0, (int) strpos($key, '.') + 1);
                self::assertContains($prefix, $registered, $relativePath . ': prefix "' . $prefix . '" of "' . $key . '" is not exported to TYPO3.lang.');
            }
        }
    }

    /**
     * @return array<string, list<string>> key => locations
     */
    private function collectReferencedKeys(): array
    {
        $patterns = [
            '/' . preg_quote(self::DOMAIN, '/') . ':([A-Za-z0-9_.]+)/',
            '/this\.(?:lang|label)\(\'([A-Za-z0-9_.]+)\'/',
            '/translateExtensionLabel\(\'([A-Za-z0-9_.]+)\'/',
            '/\$this->translate\(\'([A-Za-z0-9_.]+)\'/',
            '/->translate\(\'([A-Za-z0-9_.]+)\', \'' . preg_quote(self::DOMAIN, '/') . '\'/',
        ];
        $files = array_merge(
            $this->getFiles('Classes', 'php'),
            $this->getFiles('Resources/Private', 'html'),
            $this->getFiles('Resources/Public/JavaScript', 'js'),
            ['Configuration/page.tsconfig' => $this->root() . '/Configuration/page.tsconfig'],
        );

        $referenced = [];
        foreach ($files as $relativePath => $path) {
            $content = (string) file_get_contents($path);
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[1] as $key) {
                    if (in_array($key, self::CORE_JAVASCRIPT_KEYS, true)) {
                        continue;
                    }
                    $referenced[$key][] = $relativePath;
                }
            }
        }

        return array_map(static fn(array $locations): array => array_values(array_unique($locations)), $referenced);
    }

    /**
     * @return array<string, array{source: string, target: string, note: string, state: string, deprecated: bool}>
     */
    private function loadUnits(string $file): array
    {
        $xml = $this->loadXml($file);
        $units = [];
        foreach ($xml->xpath('//x:unit') ?: [] as $unit) {
            $unit->registerXPathNamespace('x', self::XLIFF_NAMESPACE);
            $segment = ($unit->xpath('./x:segment') ?: [null])[0];
            if (!$segment instanceof SimpleXMLElement) {
                throw new RuntimeException('Unit without segment in ' . $file, 1757700001);
            }
            $segment->registerXPathNamespace('x', self::XLIFF_NAMESPACE);
            $note = $unit->xpath('./x:notes/x:note') ?: [];
            $units[(string) $unit['id']] = [
                'source' => (string) (($segment->xpath('./x:source') ?: [''])[0]),
                'target' => (string) (($segment->xpath('./x:target') ?: [''])[0]),
                'note' => $note === [] ? '' : (string) $note[0],
                'state' => (string) ($segment['state'] ?? ''),
                'deprecated' => (string) ($segment['subState'] ?? '') === 'deprecated',
            ];
        }

        return $units;
    }

    private function loadXml(string $file): SimpleXMLElement
    {
        $xml = simplexml_load_file($this->root() . '/' . $file);
        if ($xml === false) {
            throw new RuntimeException('Unable to parse ' . $file, 1757700002);
        }
        $xml->registerXPathNamespace('x', self::XLIFF_NAMESPACE);

        return $xml;
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function getFiles(string $directory, string $extension): array
    {
        $base = $this->root() . '/' . $directory;
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->getExtension() === $extension) {
                $files[$directory . '/' . str_replace($base . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
