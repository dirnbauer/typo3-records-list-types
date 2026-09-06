<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Ssch\TYPO3Rector\CodeQuality\General\ConvertImplicitVariablesToExplicitGlobalsRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Configuration',
        __DIR__ . '/Tests',
        __DIR__ . '/ext_localconf.php',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSets([
        Typo3SetList::GENERAL,
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    ->withRules([
        ConvertImplicitVariablesToExplicitGlobalsRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ])
    // Keep compact guard clauses and explicit boolean branches readable.
    ->withSkip([
        \Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector::class,
        \Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector::class,
        \Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector::class,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
