<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Event;

use InvalidArgumentException;

/**
 * PSR-14 Event to register custom view modes for the Records module.
 *
 * This event is dispatched when the ViewModeResolver collects available view modes.
 * Extensions can listen to this event to add their own custom view modes.
 *
 * ## Example: Register a custom "kanban" view
 *
 * ```php
 * <?php
 * namespace MyVendor\MyExtension\EventListener;
 *
 * use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;
 *
 * final class RegisterKanbanViewListener
 * {
 *     public function __invoke(RegisterViewModesEvent $event): void
 *     {
 *         $event->addViewMode('kanban', [
 *             'label' => 'LLL:EXT:my_extension/Resources/Private/Language/locallang.xlf:viewMode.kanban',
 *             'icon' => 'actions-view-table-columns',
 *             'description' => 'Kanban board view',
 *         ]);
 *     }
 * }
 * ```
 *
 * Then register the listener in your extension's Services.yaml:
 *
 * ```yaml
 * MyVendor\MyExtension\EventListener\RegisterKanbanViewListener:
 *   tags:
 *     - name: event.listener
 *       identifier: 'my-extension/register-kanban-view'
 * ```
 *
 * After registering a custom view mode, you need to:
 * 1. Add a template for it in your extension
 * 2. Handle rendering in a custom controller or via XClass
 * 3. Add it to the allowed views in TSconfig: mod.web_list.viewMode.allowed = list,grid,compact,teaser,kanban
 *
 * @see \Webconsulting\RecordsListTypes\Service\ViewModeResolver
 */
final class RegisterViewModesEvent
{
    /**
     * @param array<string, array{label: string, icon: string, description: string}> $viewModes
     */
    public function __construct(
        private array $viewModes,
    ) {}

    /**
     * Get all registered view modes.
     *
     * @return array<string, array{label: string, icon: string, description: string}>
     */
    public function getViewModes(): array
    {
        return $this->viewModes;
    }

    /**
     * Add a custom view mode.
     *
     * @param string $id Unique identifier for the view mode (e.g., 'kanban', 'timeline')
     * @param array{label: string, icon: string, description?: string} $config Configuration:
     *        - label: Display label (can be LLL: reference)
     *        - icon: TYPO3 icon identifier (e.g., 'actions-viewmode-list')
     *        - description: Optional description text
     */
    public function addViewMode(string $id, array $config): void
    {
        if (!isset($config['label']) || !isset($config['icon'])) {
            throw new InvalidArgumentException(
                sprintf('View mode "%s" must have "label" and "icon" defined', $id),
                1735650000,
            );
        }

        $this->viewModes[$id] = [
            'label' => $config['label'],
            'icon' => $config['icon'],
            'description' => $config['description'] ?? '',
        ];
    }

    /**
     * Remove a view mode (useful for disabling built-in modes).
     *
     * @param string $id The view mode identifier to remove
     */
    public function removeViewMode(string $id): void
    {
        unset($this->viewModes[$id]);
    }

    /**
     * Check if a view mode is registered.
     *
     * @param string $id The view mode identifier
     */
    public function hasViewMode(string $id): bool
    {
        return isset($this->viewModes[$id]);
    }

    /**
     * Override an existing view mode's configuration.
     *
     * @param string $id The view mode identifier
     * @param array{label?: string, icon?: string, description?: string} $config Partial config to merge
     */
    public function modifyViewMode(string $id, array $config): void
    {
        if (!isset($this->viewModes[$id])) {
            return;
        }

        $this->viewModes[$id] = array_merge($this->viewModes[$id], $config);
    }
}
