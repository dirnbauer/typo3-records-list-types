<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\RecordsListTypes\Constants;
use Webconsulting\RecordsListTypes\Service\RecordFilterConfigurationService;
use Webconsulting\RecordsListTypes\Service\RecordFilterStateService;

#[AsEventListener(event: ModifyButtonBarEvent::class)]
final readonly class RecordFilterButtonBarListener
{
    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private RecordFilterConfigurationService $configurationService,
        private RecordFilterStateService $stateService,
        private PageRenderer $pageRenderer,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isRecordsModule($request)) {
            return;
        }

        $pageId = $this->getPageIdFromRequest($request);
        if (!$this->configurationService->isEnabled($pageId)) {
            return;
        }
        $table = $this->stateService->getSelectedTable($request);
        if ($table === '' || !$this->hasFilterPanelContent($table, $pageId)) {
            return;
        }
        $this->pageRenderer->addCssFile('EXT:records_list_types/Resources/Public/Css/base.css');

        $lang = $this->getLanguageService();
        $translatedLabel = $lang?->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:filter.show') ?? '';
        $label = $translatedLabel !== '' ? $translatedLabel : 'Show filters';
        $toggle = $this->componentFactory->createDropDownToggle()
            ->setActive($this->stateService->shouldShow($request))
            ->setHref($this->buildToggleUrl($request))
            ->setLabel($label)
            ->setIcon($this->iconFactory->getIcon('actions-filter'));

        $buttons = $event->getButtons();
        $translatedViewLabel = $lang?->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.view') ?? '';
        $viewLabel = $translatedViewLabel !== '' ? $translatedViewLabel : 'View';
        $added = false;
        foreach ($buttons[ButtonBar::BUTTON_POSITION_RIGHT] ?? [] as &$group) {
            foreach ($group as $button) {
                if ($button instanceof DropDownButton && $button->getLabel() === $viewLabel) {
                    $button->addItem($toggle);
                    $added = true;
                    break 2;
                }
            }
        }
        unset($group);

        if (!$added) {
            $dropdown = $this->componentFactory->createDropDownButton()
                ->setLabel($viewLabel)
                ->setIcon($this->iconFactory->getIcon('actions-cog'))
                ->setShowLabelText(true)
                ->addItem($toggle);
            $buttons[ButtonBar::BUTTON_POSITION_RIGHT][0][] = $dropdown;
        }

        $event->setButtons($buttons);
    }

    private function buildToggleUrl(ServerRequestInterface $request): string
    {
        $params = $this->stateService->getMergedParameters($request);
        if ($this->stateService->shouldShow($request)) {
            $params[RecordFilterStateService::SHOW_PARAMETER] = '0';
            unset($params[RecordFilterStateService::VALUES_PARAMETER]);
        } else {
            $params[RecordFilterStateService::SHOW_PARAMETER] = '1';
        }

        return (string) $request->getUri()->withQuery(http_build_query($params));
    }

    private function hasFilterPanelContent(string $table, int $pageId): bool
    {
        return $this->configurationService->getFiltersForTable($table, $pageId) !== []
            || $this->configurationService->getWarningsForTable($table, $pageId) !== [];
    }

    private function isRecordsModule(ServerRequestInterface $request): bool
    {
        $route = $request->getAttribute('route');
        if ($route instanceof Route) {
            $routePath = $route->getPath();
            if (str_contains($routePath, '/module/content/records')
                || str_contains($routePath, '/module/web/list')) {
                return true;
            }
            $moduleName = $route->getOption('moduleName');
            if (is_string($moduleName) && in_array($moduleName, Constants::MODULE_IDENTIFIERS, true)) {
                return true;
            }
        }

        $module = $request->getAttribute('module');
        return $module instanceof ModuleInterface
            && in_array($module->getIdentifier(), Constants::MODULE_IDENTIFIERS, true);
    }

    private function getPageIdFromRequest(ServerRequestInterface $request): int
    {
        $params = $this->stateService->getMergedParameters($request);
        $id = $params['id'] ?? null;
        return is_numeric($id) ? (int) $id : 0;
    }

    private function getLanguageService(): ?LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        return $lang instanceof LanguageService ? $lang : null;
    }
}
