<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\RecordsListTypes\Service\RecordFilterStateService;
use Webconsulting\RecordsListTypes\Service\RecordFilterViewDataFactory;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;

#[AsEventListener(event: RenderAdditionalContentToRecordListEvent::class)]
final readonly class RecordFilterAdditionalContentListener
{
    public function __construct(
        private RecordFilterStateService $stateService,
        private RecordFilterViewDataFactory $viewDataFactory,
        private ViewModeResolver $viewModeResolver,
        private ViewFactoryInterface $viewFactory,
    ) {}

    public function __invoke(RenderAdditionalContentToRecordListEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->stateService->shouldShow($request)) {
            return;
        }

        $params = $this->stateService->getMergedParameters($request);
        $pageId = is_numeric($params['id'] ?? null) ? (int) $params['id'] : 0;
        $table = is_string($params['table'] ?? null) ? $params['table'] : '';
        if ($table === '') {
            return;
        }

        $viewMode = $this->viewModeResolver->getActiveViewMode($request, $pageId);
        if ($viewMode !== 'list') {
            return;
        }

        $filters = $this->viewDataFactory->createForTable($table, $pageId, $viewMode, $request);
        if (($filters['items'] ?? []) === [] && ($filters['warnings'] ?? []) === []) {
            return;
        }

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:records_list_types/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:records_list_types/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:records_list_types/Resources/Private/Layouts/'],
            request: $request,
        ));
        $view->assign('filters', $filters);
        $event->addContentAbove($view->render('RecordFilters'));
    }
}
