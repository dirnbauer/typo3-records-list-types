<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Context\PageContext;

/**
 * Request-scoped inputs for enriching alternative record-list view payloads.
 */
final readonly class RecordViewEnrichmentContext
{
    public function __construct(
        public PageContext $pageContext,
        public string $viewMode = 'list',
        public ?ServerRequestInterface $request = null,
    ) {}
}
