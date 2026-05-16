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

// The identifier must match the `build=` attribute used in every Fluid
// template of this extension (records-list-types-backend-fragments).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['htmlSanitizer']['records-list-types-backend-fragments']
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
