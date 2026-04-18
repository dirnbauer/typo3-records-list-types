<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\ViewHelpers;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Webconsulting\RecordsListTypes\Html\BackendFragmentSanitizerBuilder;

/**
 * Render backend-generated HTML fragments through a TYPO3 sanitizer.
 *
 * This is intended for markup returned by TYPO3 backend component APIs
 * (DatabaseRecordList buttons, heading links, dropdowns, etc.) so templates
 * do not have to use f:format.raw directly.
 */
final class RenderBackendHtmlViewHelper extends AbstractViewHelper
{
    protected $escapeChildren = false;
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'value',
            'string',
            'Backend-generated HTML fragment to sanitize and render',
            false,
            '',
        );
    }

    public function render(): string
    {
        $value = $this->arguments['value'] ?? '';
        $html = is_string($value) && $value !== '' ? $value : (string) $this->renderChildren();
        if ($html === '') {
            return '';
        }

        $sanitizer = GeneralUtility::makeInstance(BackendFragmentSanitizerBuilder::class)->build();
        return $sanitizer->sanitize($html);
    }
}
