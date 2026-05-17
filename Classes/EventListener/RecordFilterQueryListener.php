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

        $displayMode = $this->getDisplayMode($request);
        $deferWorkspaceFilters = $displayMode !== '' && $displayMode !== 'list'
            && $this->queryService->hasDeferredWorkspaceFilters($event->getTable(), $event->getPageId(), $request);

        $this->queryService->applyActiveFilters(
            $event->getQueryBuilder(),
            $event->getTable(),
            $event->getPageId(),
            $request,
            $deferWorkspaceFilters,
        );
    }

    private function getDisplayMode(ServerRequestInterface $request): string
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];
        $displayMode = $queryParams['displayMode'] ?? $bodyParams['displayMode'] ?? '';
        return is_scalar($displayMode) ? trim((string) $displayMode) : '';
    }
}
