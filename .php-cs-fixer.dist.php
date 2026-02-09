<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->in(__DIR__ . '/Tests')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_line_empty_body' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => true,
        ],
        'phpdoc_line_span' => [
            'const' => 'single',
            'method' => 'multi',
            'property' => 'single',
        ],
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],
    ])
    ->setFinder($finder);
