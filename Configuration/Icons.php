<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Icon configuration for the Records List Types extension.
 */
return [
    'tx-recordslisttypes-extension' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:records_list_types/Resources/Public/Icons/Extension.svg',
    ],
];
