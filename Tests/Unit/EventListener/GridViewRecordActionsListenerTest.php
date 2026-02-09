<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\EventListener\GridViewRecordActionsListener;

/**
 * Tests for the GridViewRecordActionsListener.
 */
final class GridViewRecordActionsListenerTest extends TestCase
{
    private GridViewRecordActionsListener $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new GridViewRecordActionsListener();
    }

    #[Test]
    public function getLatestActionsReturnsNullInitially(): void
    {
        self::assertNull($this->subject->getLatestActions());
    }

    #[Test]
    public function getAllCachedActionsReturnsEmptyArrayInitially(): void
    {
        self::assertSame([], $this->subject->getAllCachedActions());
    }

    #[Test]
    public function storeRecordActionsStoresActions(): void
    {
        $actions = ['edit' => '<button>Edit</button>', 'delete' => '<button>Delete</button>'];

        $this->subject->storeRecordActions('tt_content', 42, $actions);

        $cached = $this->subject->getAllCachedActions();
        self::assertArrayHasKey('tt_content_42', $cached);
        self::assertSame($actions, $cached['tt_content_42']['actions']);
    }

    #[Test]
    public function storeRecordActionsIncludesTimestamp(): void
    {
        $this->subject->storeRecordActions('pages', 1, ['edit' => '<button>Edit</button>']);

        $cached = $this->subject->getAllCachedActions();
        self::assertArrayHasKey('timestamp', $cached['pages_1']);
        self::assertIsInt($cached['pages_1']['timestamp']);
    }

    #[Test]
    public function getPrimaryActionsHtmlReturnsPrimaryActionsOnly(): void
    {
        $actions = [
            'edit' => '<button>Edit</button>',
            'view' => '<button>View</button>',
            'hide' => '<button>Hide</button>',
            'delete' => '<button>Delete</button>',
            'copy' => '<button>Copy</button>',
        ];

        $this->subject->storeRecordActions('tt_content', 1, $actions);

        $primary = $this->subject->getPrimaryActionsHtml('tt_content', 1);

        self::assertCount(3, $primary);
        self::assertContains('<button>Edit</button>', $primary);
        self::assertContains('<button>View</button>', $primary);
        self::assertContains('<button>Hide</button>', $primary);
        self::assertNotContains('<button>Delete</button>', $primary);
        self::assertNotContains('<button>Copy</button>', $primary);
    }

    #[Test]
    public function getPrimaryActionsHtmlIncludesUnhideAction(): void
    {
        $actions = [
            'unhide' => '<button>Unhide</button>',
        ];

        $this->subject->storeRecordActions('tt_content', 5, $actions);

        $primary = $this->subject->getPrimaryActionsHtml('tt_content', 5);

        self::assertCount(1, $primary);
        self::assertContains('<button>Unhide</button>', $primary);
    }

    #[Test]
    public function getPrimaryActionsHtmlReturnsEmptyForNonCachedRecord(): void
    {
        self::assertSame([], $this->subject->getPrimaryActionsHtml('tt_content', 999));
    }

    #[Test]
    public function getAllActionsHtmlReturnsAllStringActions(): void
    {
        $actions = [
            'edit' => '<button>Edit</button>',
            'view' => '<button>View</button>',
            'delete' => '<button>Delete</button>',
        ];

        $this->subject->storeRecordActions('pages', 10, $actions);

        $all = $this->subject->getAllActionsHtml('pages', 10);

        self::assertCount(3, $all);
        self::assertContains('<button>Edit</button>', $all);
        self::assertContains('<button>View</button>', $all);
        self::assertContains('<button>Delete</button>', $all);
    }

    #[Test]
    public function getAllActionsHtmlFiltersNonStringValues(): void
    {
        $actions = [
            'edit' => '<button>Edit</button>',
            'complex' => ['not' => 'a string'],
            'numeric' => 42,
        ];

        $this->subject->storeRecordActions('tt_content', 7, $actions);

        $all = $this->subject->getAllActionsHtml('tt_content', 7);

        self::assertCount(1, $all);
        self::assertContains('<button>Edit</button>', $all);
    }

    #[Test]
    public function getAllActionsHtmlReturnsEmptyForNonCachedRecord(): void
    {
        self::assertSame([], $this->subject->getAllActionsHtml('tt_content', 999));
    }

    #[Test]
    public function clearCacheRemovesAllCachedActions(): void
    {
        $this->subject->storeRecordActions('tt_content', 1, ['edit' => '<button>Edit</button>']);
        $this->subject->storeRecordActions('pages', 2, ['edit' => '<button>Edit</button>']);

        self::assertNotEmpty($this->subject->getAllCachedActions());

        $this->subject->clearCache();

        self::assertSame([], $this->subject->getAllCachedActions());
        self::assertNull($this->subject->getLatestActions());
    }

    #[Test]
    public function storeRecordActionsOverridesExistingCacheEntry(): void
    {
        $this->subject->storeRecordActions('tt_content', 1, ['edit' => '<button>Old</button>']);
        $this->subject->storeRecordActions('tt_content', 1, ['edit' => '<button>New</button>']);

        $cached = $this->subject->getAllCachedActions();
        self::assertSame('<button>New</button>', $cached['tt_content_1']['actions']['edit']);
    }

    #[Test]
    public function multipleTablesAreCachedSeparately(): void
    {
        $this->subject->storeRecordActions('tt_content', 1, ['edit' => '<button>Content</button>']);
        $this->subject->storeRecordActions('pages', 1, ['edit' => '<button>Page</button>']);

        $contentActions = $this->subject->getAllActionsHtml('tt_content', 1);
        $pageActions = $this->subject->getAllActionsHtml('pages', 1);

        self::assertContains('<button>Content</button>', $contentActions);
        self::assertContains('<button>Page</button>', $pageActions);
    }
}
