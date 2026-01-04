<?php

/**
 * Extension Manager/Repository configuration file for records_list_types.
 *
 * Multiple list view types for TYPO3 v14 Records module.
 * Provides grid, compact, and teaser layouts as alternatives to the traditional list view,
 * with thumbnail support, TSconfig configuration, and PSR-14 integration.
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'Records List Types',
    'description' => 'Adds multiple view types (Grid, Compact, Teaser) to the TYPO3 Records module. Enables visual browsing of records with thumbnails, configurable via TSconfig. Supports Bootstrap 5, dark mode, and integrates via PSR-14 events.',
    'category' => 'be',
    'author' => 'Webconsulting',
    'author_email' => 'office@webconsulting.at',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'backend' => '14.0.0-14.99.99',
            'fluid' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
