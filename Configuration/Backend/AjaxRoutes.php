<?php

/**
 * AJAX routes for the Records List Types extension
 */
return [
    // Save view mode preference
    'records_list_types_set_view_mode' => [
        'path' => '/records-list-types/set-view-mode',
        'target' => \Webconsulting\RecordsListTypes\Controller\Ajax\ViewModeController::class . '::setViewModeAction',
    ],
    // Get current view mode
    'records_list_types_get_view_mode' => [
        'path' => '/records-list-types/get-view-mode',
        'target' => \Webconsulting\RecordsListTypes\Controller\Ajax\ViewModeController::class . '::getViewModeAction',
    ],
];
