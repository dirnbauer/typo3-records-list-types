<?php

declare(strict_types=1);

defined('TYPO3') or die();

// =============================================================================
// XClass: Extend RecordListController with multiple view types support
// =============================================================================

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Backend\Controller\RecordListController::class] = [
    'className' => \Webconsulting\RecordsListTypes\Controller\RecordListController::class,
];

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
