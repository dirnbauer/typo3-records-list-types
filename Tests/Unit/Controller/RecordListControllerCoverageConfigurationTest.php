<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecordListControllerCoverageConfigurationTest extends TestCase
{
    #[Test]
    public function recordListControllerIsNotExcludedFromSourceCoverage(): void
    {
        $config = simplexml_load_file(__DIR__ . '/../../../phpunit.xml.dist');
        if ($config === false) {
            throw new RuntimeException('Unable to read phpunit.xml.dist.', 1770000100);
        }

        $excludedFiles = [];
        foreach ($config->source->exclude->file ?? [] as $file) {
            $excludedFiles[] = (string) $file;
        }

        self::assertNotContains(
            'Classes/Controller/RecordListController.php',
            $excludedFiles,
            'The XClass controller must stay inside PHPUnit source coverage.',
        );
    }
}
