<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Records List Types',
    'description' => 'Multiple view modes for the TYPO3 v14 backend Records module with workspace-aware overlays.',
    'category' => 'be',
    'author' => 'Webconsulting',
    'author_email' => 'office@webconsulting.at',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'php' => '8.3.0-8.5.99',
            'typo3' => '14.3.0-14.99.99',
            'backend' => '14.3.0-14.99.99',
            'fluid' => '14.3.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
