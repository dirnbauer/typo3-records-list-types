<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\RecordsListTypes\Constants;
use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

/**
 * ViewModeResolver - Single source of truth for determining which view to render.
 *
 * Supports multiple view modes:
 * - list: Standard table view (TYPO3 default)
 * - grid: Card-based grid view with thumbnails, drag-and-drop, and language flags
 * - compact: Dense single-line table view with fixed columns
 * - teaser: News-style card view with title, date, and description
 * - Custom modes registered via RegisterViewModesEvent or TSconfig
 *
 * Resolution precedence:
 * 1. Request parameter (?displayMode=grid) - Highest priority, also saves preference
 * 2. Table user preference ($BE_USER->uc['records_view_mode_table'][<table>])
 * 3. Table Page TSconfig default (mod.web_list.viewMode.table.<table>)
 * 4. User preference ($BE_USER->uc['records_view_mode'])
 * 5. Page TSconfig default (mod.web_list.viewMode.default)
 * 6. Fallback: "list"
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
    private const array DEFAULT_VIEW_MODES = [
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

    private const string TABLE_USER_CONFIG_KEY = 'records_view_mode_table';

    /**
     * Cached view modes (includes custom modes from event + TSconfig)
     * @var array<string, array{label: string, icon: string, description: string}>|null
     */
    private ?array $viewModes = null;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Get the active view mode for the current request.
     *
     * @param ServerRequestInterface $request The current backend request
     * @param int $pageId The current page ID for TSconfig resolution
     * @param string $tableName Optional table name for per-table defaults
     * @return string The view mode identifier
     */
    public function getActiveViewMode(ServerRequestInterface $request, int $pageId, string $tableName = ''): string
    {
        $tableName = $this->normalizeTableName($tableName);
        $allowedModes = $this->getAllowedModes($pageId);

        // 1. Explicit request parameter (highest priority)
        $queryParams = $request->getQueryParams();
        $displayMode = ArrayUtility::stringValue($queryParams['displayMode'] ?? null);
        if ($displayMode !== '' && in_array($displayMode, $allowedModes, true)) {
            // Save preference when explicitly switching
            $this->setUserPreference($displayMode, $pageId, $tableName);
            return $displayMode;
        }

        // 2. Table-specific user preference
        $tablePreference = $this->getTableUserPreference($tableName);
        if ($tablePreference !== null && in_array($tablePreference, $allowedModes, true)) {
            return $tablePreference;
        }

        // 3. Table-specific Page TSconfig default
        $tableDefault = $this->getTsConfigTableDefault($pageId, $tableName);
        if ($tableDefault !== null && in_array($tableDefault, $allowedModes, true)) {
            return $tableDefault;
        }

        // 4. User preference (stored in backend user configuration)
        $userPreference = $this->getUserPreference();
        if ($userPreference !== null && in_array($userPreference, $allowedModes, true)) {
            return $userPreference;
        }

        // 5. Page TSconfig default
        $tsConfigDefault = $this->getTsConfigDefault($pageId);
        if ($tsConfigDefault !== null && in_array($tsConfigDefault, $allowedModes, true)) {
            return $tsConfigDefault;
        }

        // 6. Fallback to first allowed mode or default
        return $allowedModes[0] ?? Constants::DEFAULT_VIEW_MODE;
    }

    /**
     * Get all registered view modes.
     *
     * Modes are collected from:
     * 1. Built-in modes (list, grid, compact, teaser)
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
        $event = new RegisterViewModesEvent($modes);
        $this->eventDispatcher->dispatch($event);
        $modes = $event->getViewModes();

        // Also check TSconfig for custom modes (including root page = 0)
        $customModes = ArrayUtility::arrayPath(
            BackendUtility::getPagesTSconfig($pageId),
            ['mod.', 'web_list.', 'viewMode.', 'types.'],
        );
        if ($customModes !== []) {

            foreach ($customModes as $modeId => $config) {
                $modeId = rtrim((string) $modeId, '.');
                if (is_array($config) && !isset($modes[$modeId])) {
                    $labelVal = $config['label'] ?? $modeId;
                    $iconVal = $config['icon'] ?? 'actions-viewmode-list';
                    $descVal = $config['description'] ?? '';
                    $modes[$modeId] = [
                        'label' => is_string($labelVal) ? $labelVal : $modeId,
                        'icon' => is_string($iconVal) ? $iconVal : 'actions-viewmode-list',
                        'description' => is_string($descVal) ? $descVal : '',
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
        $allowedValue = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'allowed'])
            ?? ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'allowedViews']);
        $configured = ArrayUtility::commaSeparatedList($allowedValue);
        if ($configured === []) {
            $configured = array_keys($allModes);
        }

        // Filter to only valid modes
        return array_values(array_filter($configured, fn($mode): bool => isset($allModes[$mode])));
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
    public function getUserPreference(string $tableName = ''): ?string
    {
        $tableName = $this->normalizeTableName($tableName);
        if ($tableName !== '') {
            $tablePreference = $this->getTableUserPreference($tableName);
            if ($tablePreference !== null) {
                return $tablePreference;
            }
        }

        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        $preference = $backendUser->uc[Constants::USER_CONFIG_KEY] ?? null;
        return is_string($preference) ? $preference : null;
    }

    /**
     * Store the user's view mode preference.
     *
     * @param string $mode The mode identifier to store
     * @param int $pageId Optional page ID for validation against TSconfig modes
     * @param string $tableName Optional table name for table-specific preferences
     */
    public function setUserPreference(string $mode, int $pageId = 0, string $tableName = ''): void
    {
        if (!$this->isValidMode($mode, $pageId)) {
            $modes = $this->getViewModes($pageId);
            throw new InvalidArgumentException(
                sprintf('Invalid view mode "%s". Allowed: %s', $mode, implode(', ', array_keys($modes))),
                1735600000,
            );
        }

        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return; // Silently fail if no user
        }

        $tableName = $this->normalizeTableName($tableName);
        if ($tableName !== '') {
            $tablePreferences = $backendUser->uc[self::TABLE_USER_CONFIG_KEY] ?? [];
            if (!is_array($tablePreferences)) {
                $tablePreferences = [];
            }
            $tablePreferences[$tableName] = $mode;
            $backendUser->uc[self::TABLE_USER_CONFIG_KEY] = $tablePreferences;
        } else {
            $backendUser->uc[Constants::USER_CONFIG_KEY] = $mode;
        }
        $backendUser->writeUC();
    }

    /**
     * Get the default view mode from Page TSconfig.
     */
    private function getTsConfigDefault(int $pageId): ?string
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $default = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'default'])
            ?? ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'gridView.', 'default']);
        $default = ArrayUtility::stringValue($default);

        return $default !== '' ? $default : null;
    }

    private function getTsConfigTableDefault(int $pageId, string $tableName): ?string
    {
        if ($tableName === '') {
            return null;
        }

        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $default = ArrayUtility::stringValue(
            ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'table.', $tableName]),
        );

        return $default !== '' ? $default : null;
    }

    private function getTableUserPreference(string $tableName): ?string
    {
        if ($tableName === '') {
            return null;
        }

        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        $tablePreferences = $backendUser->uc[self::TABLE_USER_CONFIG_KEY] ?? null;
        if (!is_array($tablePreferences)) {
            return null;
        }

        $preference = $tablePreferences[$tableName] ?? null;
        return is_string($preference) ? $preference : null;
    }

    private function normalizeTableName(string $tableName): string
    {
        $tableName = trim($tableName);
        if ($tableName === '') {
            return '';
        }

        return preg_match('/^[a-zA-Z0-9_]+$/', $tableName) === 1 ? $tableName : '';
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
     * Check if a user is forced to a specific view (disabling the toggle).
     *
     * @return string|null The forced view mode, or null if not forced
     */
    public function getForcedViewMode(): ?string
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        // Check User TSconfig for forced view
        $forcedView = ArrayUtility::stringValue(
            ArrayUtility::valuePath($backendUser->getTSConfig(), ['options.', 'layout.', 'records.', 'forceView']),
        );

        if ($forcedView !== '' && $this->isValidMode($forcedView)) {
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
        return $this->getForcedViewMode() === null;
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

        $languageService = $this->getLanguageService();
        foreach ($allModes as $mode => $config) {
            $label = $config['label'];
            if ($languageService instanceof LanguageService && str_starts_with($label, 'LLL:')) {
                $translated = $languageService->sL($label);
                $label = $translated !== '' ? $translated : $mode;
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
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    /**
     * Get the language service.
     */
    private function getLanguageService(): ?LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        return $lang instanceof LanguageService ? $lang : null;
    }
}
