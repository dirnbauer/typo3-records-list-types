<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;

/**
 * Tests for the RegisterViewModesEvent PSR-14 event.
 */
final class RegisterViewModesEventTest extends TestCase
{
    #[Test]
    public function constructorSetsInitialViewModes(): void
    {
        $modes = [
            'list' => ['label' => 'List', 'icon' => 'actions-viewmode-list', 'description' => 'Standard'],
        ];

        $event = new RegisterViewModesEvent($modes);

        self::assertSame($modes, $event->getViewModes());
    }

    #[Test]
    public function constructorAcceptsEmptyArray(): void
    {
        $event = new RegisterViewModesEvent([]);

        self::assertSame([], $event->getViewModes());
    }

    #[Test]
    public function addViewModeRegistersNewMode(): void
    {
        $event = new RegisterViewModesEvent([]);

        $event->addViewMode('kanban', [
            'label' => 'Kanban Board',
            'icon' => 'actions-view-table-columns',
            'description' => 'Kanban board view',
        ]);

        $modes = $event->getViewModes();
        self::assertArrayHasKey('kanban', $modes);
        self::assertSame('Kanban Board', $modes['kanban']['label']);
        self::assertSame('actions-view-table-columns', $modes['kanban']['icon']);
        self::assertSame('Kanban board view', $modes['kanban']['description']);
    }

    #[Test]
    public function addViewModeDefaultsDescriptionToEmptyString(): void
    {
        $event = new RegisterViewModesEvent([]);

        $event->addViewMode('timeline', [
            'label' => 'Timeline',
            'icon' => 'actions-clock',
        ]);

        $modes = $event->getViewModes();
        self::assertSame('', $modes['timeline']['description']);
    }

    #[Test]
    public function addViewModeThrowsExceptionWithoutLabel(): void
    {
        $event = new RegisterViewModesEvent([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1735650000);

        $event->addViewMode('broken', [
            'icon' => 'some-icon',
        ]);
    }

    #[Test]
    public function addViewModeThrowsExceptionWithoutIcon(): void
    {
        $event = new RegisterViewModesEvent([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1735650000);

        $event->addViewMode('broken', [
            'label' => 'Some Label',
        ]);
    }

    #[Test]
    public function addViewModeThrowsExceptionWithEmptyConfig(): void
    {
        $event = new RegisterViewModesEvent([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1735650000);

        $event->addViewMode('broken', []);
    }

    #[Test]
    public function addViewModeOverridesExistingMode(): void
    {
        $event = new RegisterViewModesEvent([
            'grid' => ['label' => 'Old Grid', 'icon' => 'old-icon', 'description' => 'Old'],
        ]);

        $event->addViewMode('grid', [
            'label' => 'New Grid',
            'icon' => 'new-icon',
            'description' => 'New description',
        ]);

        $modes = $event->getViewModes();
        self::assertSame('New Grid', $modes['grid']['label']);
        self::assertSame('new-icon', $modes['grid']['icon']);
    }

    #[Test]
    public function removeViewModeRemovesExistingMode(): void
    {
        $event = new RegisterViewModesEvent([
            'list' => ['label' => 'List', 'icon' => 'list-icon', 'description' => ''],
            'grid' => ['label' => 'Grid', 'icon' => 'grid-icon', 'description' => ''],
        ]);

        $event->removeViewMode('grid');

        $modes = $event->getViewModes();
        self::assertArrayNotHasKey('grid', $modes);
        self::assertArrayHasKey('list', $modes);
    }

    #[Test]
    public function removeViewModeDoesNotFailForNonExistentMode(): void
    {
        $event = new RegisterViewModesEvent([]);

        // Should not throw
        $event->removeViewMode('nonexistent');

        self::assertSame([], $event->getViewModes());
    }

    #[Test]
    public function hasViewModeReturnsTrueForExistingMode(): void
    {
        $event = new RegisterViewModesEvent([
            'list' => ['label' => 'List', 'icon' => 'list-icon', 'description' => ''],
        ]);

        self::assertTrue($event->hasViewMode('list'));
    }

    #[Test]
    public function hasViewModeReturnsFalseForNonExistentMode(): void
    {
        $event = new RegisterViewModesEvent([]);

        self::assertFalse($event->hasViewMode('nonexistent'));
    }

    #[Test]
    public function modifyViewModeMergesConfig(): void
    {
        $event = new RegisterViewModesEvent([
            'grid' => ['label' => 'Grid', 'icon' => 'old-icon', 'description' => 'Original'],
        ]);

        $event->modifyViewMode('grid', ['icon' => 'new-icon']);

        $modes = $event->getViewModes();
        self::assertSame('Grid', $modes['grid']['label']); // unchanged
        self::assertSame('new-icon', $modes['grid']['icon']); // updated
        self::assertSame('Original', $modes['grid']['description']); // unchanged
    }

    #[Test]
    public function modifyViewModeDoesNothingForNonExistentMode(): void
    {
        $event = new RegisterViewModesEvent([]);

        $event->modifyViewMode('nonexistent', ['label' => 'New Label']);

        self::assertSame([], $event->getViewModes());
    }

    #[Test]
    public function multipleOperationsWorkCorrectly(): void
    {
        $event = new RegisterViewModesEvent([
            'list' => ['label' => 'List', 'icon' => 'list-icon', 'description' => ''],
        ]);

        $event->addViewMode('grid', ['label' => 'Grid', 'icon' => 'grid-icon']);
        $event->addViewMode('compact', ['label' => 'Compact', 'icon' => 'compact-icon']);
        $event->removeViewMode('list');
        $event->modifyViewMode('grid', ['description' => 'Updated']);

        $modes = $event->getViewModes();
        self::assertCount(2, $modes);
        self::assertFalse($event->hasViewMode('list'));
        self::assertTrue($event->hasViewMode('grid'));
        self::assertTrue($event->hasViewMode('compact'));
        self::assertSame('Updated', $modes['grid']['description']);
    }
}
