<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\Event\ModifyDatabaseQueryForRecordListingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use Webconsulting\RecordsListTypes\Service\RecordFilterQueryService;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

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

        $params = ArrayUtility::mergedRequestParameters($request);
        $displayMode = $this->getStringParameter($params, 'displayMode');
        $deferWorkspaceFilters = $displayMode !== '' && $displayMode !== 'list'
            && $this->queryService->shouldDeferWorkspaceEvaluation(
                $event->getTable(),
                $event->getPageId(),
                $request,
                $this->getStringParameter($params, 'searchTerm'),
            );

        $this->queryService->applyActiveFilters(
            $event->getQueryBuilder(),
            $event->getTable(),
            $event->getPageId(),
            $request,
            $deferWorkspaceFilters,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getStringParameter(array $params, string $key): string
    {
        $value = $params[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
