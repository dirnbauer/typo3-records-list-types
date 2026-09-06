<?php

declare(strict_types=1);

use TYPO3\CMS\Backend\Controller\ContentElement\ElementHistoryController;
use TYPO3\CMS\Backend\Controller\RecordListController;
use Webconsulting\RecordsListTypes\Html\BackendFragmentSanitizerBuilder;

defined('TYPO3') || die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][RecordListController::class] = [
    'className' => \Webconsulting\RecordsListTypes\Controller\RecordListController::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][ElementHistoryController::class] = [
    'className' => \Webconsulting\RecordsListTypes\Controller\ContentElement\ElementHistoryController::class,
];

// The identifier must match the `build=` attribute used in every Fluid
// template of this extension (records-list-types-backend-fragments).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['htmlSanitizer']['records-list-types-backend-fragments']
    = BackendFragmentSanitizerBuilder::class;
