<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Constants;

/**
 * Tests for extension constants.
 */
final class ConstantsTest extends TestCase
{
    #[Test]
    public function extensionKeyIsCorrect(): void
    {
        self::assertSame('records_list_types', Constants::EXTENSION_KEY);
    }

    #[Test]
    public function moduleRouteIsCorrect(): void
    {
        self::assertSame('records', Constants::MODULE_ROUTE);
    }

    #[Test]
    public function moduleIdentifiersContainExpectedValues(): void
    {
        self::assertContains('records', Constants::MODULE_IDENTIFIERS);
        self::assertContains('web_list', Constants::MODULE_IDENTIFIERS);
    }

    #[Test]
    public function defaultViewModeIsList(): void
    {
        self::assertSame('list', Constants::DEFAULT_VIEW_MODE);
    }

    #[Test]
    public function builtinViewModesContainAllExpectedModes(): void
    {
        self::assertContains('list', Constants::BUILTIN_VIEW_MODES);
        self::assertContains('grid', Constants::BUILTIN_VIEW_MODES);
        self::assertContains('compact', Constants::BUILTIN_VIEW_MODES);
        self::assertContains('teaser', Constants::BUILTIN_VIEW_MODES);
        self::assertCount(4, Constants::BUILTIN_VIEW_MODES);
    }

    #[Test]
    public function userConfigKeyIsCorrect(): void
    {
        self::assertSame('records_view_mode', Constants::USER_CONFIG_KEY);
    }
}
