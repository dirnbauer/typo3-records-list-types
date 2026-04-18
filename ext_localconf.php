<?php

declare(strict_types=1);

use TYPO3\CMS\Backend\Controller\RecordListController;
use Webconsulting\RecordsListTypes\Html\BackendFragmentSanitizerBuilder;

defined('TYPO3') || die();

// =============================================================================
// XClass: Extend RecordListController with multiple view types support
// =============================================================================

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][RecordListController::class] = [
    'className' => \Webconsulting\RecordsListTypes\Controller\RecordListController::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['htmlSanitizer']['recordsListTypesBackend']
    = BackendFragmentSanitizerBuilder::class;

// =============================================================================
// AJAX routes are registered in Configuration/Backend/AjaxRoutes.php
// =============================================================================

// =============================================================================
// Default Page TSconfig
// =============================================================================
// In TYPO3 v14+, TSconfig is automatically loaded from:
// - Configuration/page.tsconfig (Page TSconfig)
// - Configuration/user.tsconfig (User TSconfig)
// No manual registration needed.
