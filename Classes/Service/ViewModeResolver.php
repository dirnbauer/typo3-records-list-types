<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;

/**
 * ViewModeResolver - Single source of truth for determining which view to render.
 *
 * Supports multiple view modes:
 * - list: Standard table view (TYPO3 default)
 * - grid: Card-based grid view
 * - compact: Single-line compact view
 * - Custom modes can be registered via RegisterViewModesEvent
 *
 * Resolution precedence:
 * 1. Request parameter (?displayMode=grid) - Highest priority, also saves preference
 * 2. User preference ($BE_USER->uc['records_view_mode'])
 * 3. Page TSconfig (mod.web_list.viewMode.default)
 * 4. Fallback: "list"
 *
 * @see RegisterViewModesEvent for adding custom view modes
 */
final class ViewModeResolver implements SingletonInterface
{
    /**
     * Default view modes. Additional modes can be registered via:
     * - RegisterViewModesEvent (PSR-14 event)
     * - TSconfig: mod.web_list.viewMode.types.{id} { label, icon, description }
     *
     * Icons available in TYPO3:
     * - actions-viewmode-list (horizontal lines)
     * - actions-viewmode-tiles (grid of boxes)
     * - actions-list-alternative (bullet list)
     * - actions-menu (hamburger menu)
     * - content-news (news icon)
     */
    private const DEFAULT_VIEW_MODES = [
        'list' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.list',
            'icon' => 'actions-viewmode-list',
            'description' => 'Standard table view',
        ],
        'grid' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.grid',
            'icon' => 'actions-viewmode-tiles',
            'description' => 'Card-based grid view',
        ],
        'compact' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.compact',
            'icon' => 'actions-menu',
            'description' => 'Compact single-line view',
        ],
        'teaser' => [
            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:viewMode.teaser',
            'icon' => 'content-news',
            'description' => 'Teaser list with title, date, description',
        ],
    ];

    private const USER_CONFIG_KEY = 'records_view_mode';
    private const DEFAULT_MODE = 'list';

    /**
     * Cached view modes (includes custom modes from event + TSconfig)
     * @var array<string, array{label: string, icon: string, description: string}>|null
     */
    private ?array $viewModes = null;

    /**
     * Get the active view mode for the current request.
     *
     * @param ServerRequestInterface $request The current backend request
     * @param int $pageId The current page ID for TSconfig resolution
     * @return string The view mode identifier
     */
    public function getActiveViewMode(ServerRequestInterface $request, int $pageId): string
    {
        $allowedModes = $this->getAllowedModes($pageId);

        // 1. Explicit request parameter (highest priority)
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['displayMode']) && in_array($queryParams['displayMode'], $allowedModes, true)) {
            // Save preference when explicitly switching
            $this->setUserPreference($queryParams['displayMode']);
            return $queryParams['displayMode'];
        }

        // 2. User preference (stored in backend user configuration)
        $userPreference = $this->getUserPreference();
        if ($userPreference !== null && in_array($userPreference, $allowedModes, true)) {
            return $userPreference;
        }

        // 3. Page TSconfig default
        $tsConfigDefault = $this->getTsConfigDefault($pageId);
        if ($tsConfigDefault !== null && in_array($tsConfigDefault, $allowedModes, true)) {
            return $tsConfigDefault;
        }

        // 4. Fallback to first allowed mode or default
        return $allowedModes[0] ?? self::DEFAULT_MODE;
    }

    /**
     * Get all registered view modes.
     *
     * Modes are collected from:
     * 1. Built-in modes (list, grid, compact)
     * 2. Custom modes registered via RegisterViewModesEvent
     * 3. Custom modes defined in TSconfig (mod.web_list.viewMode.types.{id})
     *
     * @param int $pageId Optional page ID for TSconfig-based modes
     * @return array<string, array{label: string, icon: string, description: string}>
     */
    public function getViewModes(int $pageId = 0): array
    {
        if ($this->viewModes !== null) {
            return $this->viewModes;
        }

        // Start with default modes
        $modes = self::DEFAULT_VIEW_MODES;

        // Allow extensions to register custom modes via PSR-14 event
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $event = new RegisterViewModesEvent($modes);
        $eventDispatcher->dispatch($event);
        $modes = $event->getViewModes();

        // Also check TSconfig for custom modes
        if ($pageId > 0) {
            $tsConfig = BackendUtility::getPagesTSconfig($pageId);
            $customModes = $tsConfig['mod.']['web_list.']['viewMode.']['types.'] ?? [];

            foreach ($customModes as $modeId => $config) {
                $modeId = rtrim($modeId, '.');
                if (is_array($config) && !isset($modes[$modeId])) {
                    $modes[$modeId] = [
                        'label' => $config['label'] ?? $modeId,
                        'icon' => $config['icon'] ?? 'actions-viewmode-list',
                        'description' => $config['description'] ?? '',
                    ];
                }
            }
        }

        $this->viewModes = $modes;
        return $modes;
    }

    /**
     * Get allowed view modes for a page.
     *
     * @param int $pageId The page ID
     * @return string[] Array of allowed mode identifiers
     */
    public function getAllowedModes(int $pageId): array
    {
        $allModes = $this->getViewModes($pageId);

        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $allowedString = $tsConfig['mod.']['web_list.']['viewMode.']['allowed']
            ?? $tsConfig['mod.']['web_list.']['allowedViews']
            ?? implode(',', array_keys($allModes)); // Default: all registered modes

        $configured = array_map('trim', explode(',', $allowedString));

        // Filter to only valid modes
        return array_values(array_filter($configured, fn($mode) => isset($allModes[$mode])));
    }

    /**
     * Check if the given mode is valid (registered).
     *
     * @param string $mode The mode identifier to check
     * @param int $pageId Optional page ID for TSconfig-based modes
     */
    public function isValidMode(string $mode, int $pageId = 0): bool
    {
        $modes = $this->getViewModes($pageId);
        return isset($modes[$mode]);
    }

    /**
     * Get the user's stored view mode preference.
     */
    public function getUserPreference(): ?string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return null;
        }

        $preference = $backendUser->uc[self::USER_CONFIG_KEY] ?? null;
        return is_string($preference) ? $preference : null;
    }

    /**
     * Store the user's view mode preference.
     *
     * @param string $mode The mode identifier to store
     * @param int $pageId Optional page ID for validation against TSconfig modes
     */
    public function setUserPreference(string $mode, int $pageId = 0): void
    {
        if (!$this->isValidMode($mode, $pageId)) {
            $modes = $this->getViewModes($pageId);
            throw new InvalidArgumentException(
                sprintf('Invalid view mode "%s". Allowed: %s', $mode, implode(', ', array_keys($modes))),
                1735600000,
            );
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return; // Silently fail if no user
        }

        $backendUser->uc[self::USER_CONFIG_KEY] = $mode;
        $backendUser->writeUC();
    }

    /**
     * Get the default view mode from Page TSconfig.
     */
    private function getTsConfigDefault(int $pageId): ?string
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        return $tsConfig['mod.']['web_list.']['viewMode.']['default']
            ?? $tsConfig['mod.']['web_list.']['gridView.']['default']
            ?? null;
    }

    /**
     * Check if a specific view mode is allowed for the given page.
     *
     * @param string $mode The view mode to check
     * @param int $pageId The page ID to check
     * @return bool True if the mode is allowed
     */
    public function isModeAllowed(string $mode, int $pageId): bool
    {
        return in_array($mode, $this->getAllowedModes($pageId), true);
    }

    /**
     * Check if grid view is allowed for the given page.
     * @deprecated Use isModeAllowed('grid', $pageId) instead
     */
    public function isGridViewAllowed(int $pageId): bool
    {
        return $this->isModeAllowed('grid', $pageId);
    }

    /**
     * Check if a user is forced to a specific view (disabling the toggle).
     *
     * @return string|null The forced view mode, or null if not forced
     */
    public function getForcedViewMode(): ?string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return null;
        }

        // Check User TSconfig for forced view
        $userTsConfig = $backendUser->getTSConfig();
        $forcedView = $userTsConfig['options.']['layout.']['records.']['forceView'] ?? null;

        if ($forcedView !== null && $this->isValidMode($forcedView)) {
            return $forcedView;
        }

        return null;
    }

    /**
     * Check if the view mode toggle should be displayed.
     *
     * @param int $pageId The current page ID
     * @return bool True if the toggle should be shown
     */
    public function shouldShowToggle(int $pageId): bool
    {
        // Don't show toggle if only one mode is allowed
        $allowedModes = $this->getAllowedModes($pageId);
        if (count($allowedModes) < 2) {
            return false;
        }

        // Don't show toggle if a view is forced via User TSconfig
        if ($this->getForcedViewMode() !== null) {
            return false;
        }

        return true;
    }

    /**
     * Get view mode configuration with resolved labels.
     *
     * @param int $pageId The page ID for allowed modes
     * @return array<string, array{id: string, label: string, icon: string, description: string, allowed: bool}>
     */
    public function getViewModesForDisplay(int $pageId): array
    {
        $allModes = $this->getViewModes($pageId);
        $allowedModes = $this->getAllowedModes($pageId);
        $result = [];

        foreach ($allModes as $mode => $config) {
            $label = $config['label'];
            if (str_starts_with($label, 'LLL:')) {
                $label = $GLOBALS['LANG']->sL($label) ?: $mode;
            }

            $result[$mode] = [
                'id' => $mode,
                'label' => $label,
                'icon' => $config['icon'],
                'description' => $config['description'] ?? '',
                'allowed' => in_array($mode, $allowedModes, true),
            ];
        }

        return $result;
    }

    /**
     * Get the current backend user.
     */
    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
