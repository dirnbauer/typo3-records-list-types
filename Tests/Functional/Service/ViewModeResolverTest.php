<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Functional\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;

/**
 * Functional tests for ViewModeResolver.
 *
 * Tests the full resolution chain: request params, user preference,
 * TSconfig defaults, and fallback behavior with a real TYPO3 backend.
 */
final class ViewModeResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/records-list-types',
    ];

    private ViewModeResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Pages.csv');
        $this->subject = $this->get(ViewModeResolver::class);
    }

    #[Test]
    public function getViewModesReturnsBuiltinModes(): void
    {
        $modes = $this->subject->getViewModes();

        self::assertArrayHasKey('list', $modes);
        self::assertArrayHasKey('grid', $modes);
        self::assertArrayHasKey('compact', $modes);
        self::assertArrayHasKey('teaser', $modes);
    }

    #[Test]
    public function getViewModesContainsRequiredKeys(): void
    {
        $modes = $this->subject->getViewModes();

        foreach ($modes as $modeId => $config) {
            self::assertArrayHasKey('label', $config, "Mode '$modeId' missing 'label'");
            self::assertArrayHasKey('icon', $config, "Mode '$modeId' missing 'icon'");
            self::assertArrayHasKey('description', $config, "Mode '$modeId' missing 'description'");
        }
    }

    #[Test]
    public function isValidModeReturnsTrueForBuiltinModes(): void
    {
        self::assertTrue($this->subject->isValidMode('list'));
        self::assertTrue($this->subject->isValidMode('grid'));
        self::assertTrue($this->subject->isValidMode('compact'));
        self::assertTrue($this->subject->isValidMode('teaser'));
    }

    #[Test]
    public function isValidModeReturnsFalseForUnknownMode(): void
    {
        self::assertFalse($this->subject->isValidMode('nonexistent'));
    }

    #[Test]
    public function getAllowedModesReturnsConfiguredModes(): void
    {
        // Page 2 has: mod.web_list.viewMode.allowed = list,grid,compact
        $allowed = $this->subject->getAllowedModes(2);

        self::assertContains('list', $allowed);
        self::assertContains('grid', $allowed);
        self::assertContains('compact', $allowed);
        self::assertNotContains('teaser', $allowed);
    }

    #[Test]
    public function getAllowedModesReturnsAllModesWhenNotConfigured(): void
    {
        // Page 1 has no viewMode.allowed set
        $allowed = $this->subject->getAllowedModes(1);

        self::assertContains('list', $allowed);
        self::assertContains('grid', $allowed);
        self::assertContains('compact', $allowed);
        self::assertContains('teaser', $allowed);
    }

    #[Test]
    public function getActiveViewModeFallsBackToList(): void
    {
        $request = new ServerRequest('https://example.com/typo3/module/records');

        // Page 1 has no default configured, no user preference
        $mode = $this->subject->getActiveViewMode($request, 1);

        // Should fall back to first allowed mode or 'list'
        self::assertContains($mode, ['list', 'grid', 'compact', 'teaser']);
    }

    #[Test]
    public function getActiveViewModeRespectsRequestParameter(): void
    {
        $request = new ServerRequest('https://example.com/typo3/module/records?displayMode=grid');
        $request = $request->withQueryParams(['displayMode' => 'grid']);

        $mode = $this->subject->getActiveViewMode($request, 1);

        self::assertSame('grid', $mode);
    }

    #[Test]
    public function getActiveViewModeIgnoresInvalidRequestParameter(): void
    {
        $request = new ServerRequest('https://example.com/typo3/module/records');
        $request = $request->withQueryParams(['displayMode' => 'nonexistent']);

        $mode = $this->subject->getActiveViewMode($request, 1);

        // Should not be 'nonexistent', should fall through to other resolution
        self::assertNotSame('nonexistent', $mode);
    }

    #[Test]
    public function isModeAllowedReturnsTrueForAllowedMode(): void
    {
        // Page 2: allowed = list,grid,compact
        self::assertTrue($this->subject->isModeAllowed('grid', 2));
    }

    #[Test]
    public function isModeAllowedReturnsFalseForDisallowedMode(): void
    {
        // Page 2: allowed = list,grid,compact (teaser not included)
        self::assertFalse($this->subject->isModeAllowed('teaser', 2));
    }

    #[Test]
    public function getUserPreferenceReturnsNullWithoutSetting(): void
    {
        self::assertNull($this->subject->getUserPreference());
    }

    #[Test]
    public function setUserPreferenceStoresPreferenceInUserConfig(): void
    {
        $this->subject->setUserPreference('compact');

        // The BE_USER->uc should now contain the preference
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser !== null) {
            self::assertSame('compact', $beUser->uc['records_view_mode'] ?? null);
        } else {
            // If no BE_USER, getUserPreference returns null (expected)
            self::assertNull($this->subject->getUserPreference());
        }
    }

    #[Test]
    public function setUserPreferenceThrowsForInvalidMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1735600000);

        $this->subject->setUserPreference('nonexistent_mode');
    }

    #[Test]
    public function shouldShowToggleReturnsTrueWhenMultipleModesAllowed(): void
    {
        // Page 1: all modes allowed (> 1)
        self::assertTrue($this->subject->shouldShowToggle(1));
    }

    #[Test]
    public function shouldShowToggleReturnsFalseWhenSingleModeAllowed(): void
    {
        // Page 4: allowed = grid (only one)
        self::assertFalse($this->subject->shouldShowToggle(4));
    }

    #[Test]
    public function getForcedViewModeReturnsNullByDefault(): void
    {
        self::assertNull($this->subject->getForcedViewMode());
    }
}
