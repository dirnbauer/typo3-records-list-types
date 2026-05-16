<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\Event\ModifyDatabaseQueryForRecordListingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use Webconsulting\RecordsListTypes\Service\RecordFilterQueryService;

#[AsEventListener(event: ModifyDatabaseQueryForRecordListingEvent::class)]
final readonly class RecordFilterQueryListener
{
    public function __construct(
        private RecordFilterQueryService $queryService,
    ) {}

    public function __invoke(ModifyDatabaseQueryForRecordListingEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        $this->queryService->applyActiveFilters(
            $event->getQueryBuilder(),
            $event->getTable(),
            $event->getPageId(),
            $request,
        );
    }
}
