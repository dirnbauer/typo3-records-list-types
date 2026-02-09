<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\EventListener;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\RecordsListTypes\Constants;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;

/**
 * GridViewButtonBarListener - Injects view mode toggle buttons into the DocHeader.
 *
 * Adds a dropdown for switching between different view modes (list, grid, compact)
 * when multiple modes are available, or nothing if only one mode is allowed.
 */
#[AsEventListener(event: ModifyButtonBarEvent::class)]
final class GridViewButtonBarListener
{
    public function __construct(
        private readonly ViewModeResolver $viewModeResolver,
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {}

    private function getComponentFactory(): ComponentFactory
    {
        return GeneralUtility::makeInstance(ComponentFactory::class);
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        // Only act on the Records module
        if (!$this->isRecordsModule($request)) {
            return;
        }

        $pageId = $this->getPageIdFromRequest($request);

        // Always load CSS for button styling
        $this->pageRenderer->addCssFile('EXT:records_list_types/Resources/Public/Css/view-mode-toggle.css');

        // Check if toggle should be shown (requires at least 2 allowed modes)
        if (!$this->viewModeResolver->shouldShowToggle($pageId)) {
            return;
        }

        // Get current and allowed modes
        $currentMode = $this->viewModeResolver->getActiveViewMode($request, $pageId);
        $viewModes = $this->viewModeResolver->getViewModesForDisplay($pageId);

        // Filter to only allowed modes
        $allowedModes = array_filter($viewModes, fn($config) => $config['allowed']);

        // Need at least 2 modes to show a toggle
        if (count($allowedModes) < 2) {
            return;
        }

        $buttons = $event->getButtons();

        // Create dropdown button with view mode options
        $dropdownButton = $this->createViewModeDropdown(
            $allowedModes,
            $currentMode,
            $request,
            $pageId,
        );

        // Add button to the right side, in group 5
        if (!isset($buttons[ButtonBar::BUTTON_POSITION_RIGHT])) {
            $buttons[ButtonBar::BUTTON_POSITION_RIGHT] = [];
        }
        if (!isset($buttons[ButtonBar::BUTTON_POSITION_RIGHT][5])) {
            $buttons[ButtonBar::BUTTON_POSITION_RIGHT][5] = [];
        }

        $buttons[ButtonBar::BUTTON_POSITION_RIGHT][5][] = $dropdownButton;

        $event->setButtons($buttons);
    }

    /**
     * Create a dropdown button with view mode options.
     *
     * @param array<string, array{id: string, label: string, icon: string, description: string, allowed: bool}> $allowedModes
     */
    private function createViewModeDropdown(
        array $allowedModes,
        string $currentMode,
        ServerRequestInterface $request,
        int $pageId,
    ): DropDownButton {
        $queryParams = $request->getQueryParams();
        $lang = $this->getLanguageService();

        // Get current mode config for the dropdown label
        $currentModeConfig = $allowedModes[$currentMode] ?? null;
        if ($currentModeConfig === null) {
            $currentModeConfig = reset($allowedModes);
        }
        if ($currentModeConfig === false) {
            return GeneralUtility::makeInstance(ComponentFactory::class)->createDropDownButton();
        }

        $componentFactory = $this->getComponentFactory();

        // Create dropdown button
        $dropdownButton = $componentFactory->createDropDownButton()
            ->setLabel($lang->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:button.viewMode'))
            ->setIcon($this->iconFactory->getIcon($currentModeConfig['icon'], IconSize::SMALL))
            ->setShowLabelText(true);

        // Add radio items for each view mode
        foreach ($allowedModes as $modeId => $modeConfig) {
            $routeParams = [
                'id' => $pageId,
                'displayMode' => $modeId,
            ];

            // Preserve other important parameters
            $preserveParams = ['table', 'search_field', 'search_levels', 'showLimit', 'pointer', 'searchTerm'];
            foreach ($preserveParams as $param) {
                if (isset($queryParams[$param])) {
                    $routeParams[$param] = $queryParams[$param];
                }
            }

            try {
                $url = (string) $this->uriBuilder->buildUriFromRoute(Constants::MODULE_ROUTE, $routeParams);
            } catch (Exception $e) {
                $params = $queryParams;
                $params['displayMode'] = $modeId;
                $url = (string) $request->getUri()->withQuery(http_build_query($params));
            }

            $dropdownItem = $componentFactory->createDropDownRadio()
                ->setActive($currentMode === $modeId)
                ->setLabel($modeConfig['label'])
                ->setHref($url)
                ->setIcon($this->iconFactory->getIcon($modeConfig['icon'], IconSize::SMALL));

            $dropdownButton->addItem($dropdownItem);
        }

        return $dropdownButton;
    }

    /**
     * Get the language service.
     */
    private function getLanguageService(): LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if (!$lang instanceof LanguageService) {
            throw new \RuntimeException('LanguageService not available', 1735600100);
        }
        return $lang;
    }

    /**
     * Check if the current request is for the Records module.
     */
    private function isRecordsModule(ServerRequestInterface $request): bool
    {
        $route = $request->getAttribute('route');
        if ($route instanceof \TYPO3\CMS\Core\Routing\Route) {
            $routePath = $route->getPath();
            if (str_contains($routePath, '/module/content/records')
                || str_contains($routePath, '/module/web/list')) {
                return true;
            }

            $moduleName = $route->getOption('moduleName');
            if (is_string($moduleName) && in_array($moduleName, Constants::MODULE_IDENTIFIERS, true)) {
                return true;
            }

            $routeIdentifier = $route->getOption('_identifier');
            $routeIdentifier = is_string($routeIdentifier) ? $routeIdentifier : '';
            foreach (Constants::MODULE_IDENTIFIERS as $identifier) {
                if (str_starts_with($routeIdentifier, $identifier)) {
                    return true;
                }
            }
        }

        $module = $request->getAttribute('module');
        if (is_object($module) && method_exists($module, 'getIdentifier')) {
            /** @var object $module */
            $moduleIdentifier = (string) $module->getIdentifier();
            if (in_array($moduleIdentifier, Constants::MODULE_IDENTIFIERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the page ID from the request.
     */
    private function getPageIdFromRequest(ServerRequestInterface $request): int
    {
        $queryParams = $request->getQueryParams();

        $idParam = $queryParams['id'] ?? null;
        if ($idParam !== null) {
            return (int) $idParam;
        }

        $routeParams = $request->getAttribute('routing');
        if (is_array($routeParams) && isset($routeParams['id'])) {
            return (int) $routeParams['id'];
        }

        return 0;
    }
}
