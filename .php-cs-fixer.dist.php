<?php

declare(strict_types=1);

/*
 * TYPO3 coding guidelines (typo3/coding-standards) for the extension.
 * Run `Build/Scripts/runTests.sh -s cgl` for a dry run.
 */

use PhpCsFixer\Finder;
use TYPO3\CodingStandards\CsFixerConfig;

$finder = (new Finder())
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->in(__DIR__ . '/Tests')
    ->append([__DIR__ . '/ext_localconf.php', __DIR__ . '/rector.php', __FILE__])
    ->name('*.php')
    ->ignoreDotFiles(false)
    ->ignoreVCS(true);

return CsFixerConfig::create()
    ->setFinder($finder);
