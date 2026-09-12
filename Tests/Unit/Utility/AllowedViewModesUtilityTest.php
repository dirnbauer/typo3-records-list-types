<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Utility\AllowedViewModesUtility;

final class AllowedViewModesUtilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AllowedViewModesUtility::resetDeprecationState();
    }

    #[Test]
    public function returnsEmptyListWhenNothingIsConfigured(): void
    {
        self::assertSame([], AllowedViewModesUtility::fromPageTsConfig([]));
        self::assertSame([], AllowedViewModesUtility::fromPageTsConfig(['mod.' => ['web_list.' => []]]));
    }

    #[Test]
    public function prefersViewModeAllowedOverTheLegacyKey(): void
    {
        $tsConfig = [
            'mod.' => [
                'web_list.' => [
                    'allowedViews' => 'list,grid',
                    'viewMode.' => ['allowed' => 'list, compact ,teaser'],
                ],
            ],
        ];

        $deprecations = $this->collectDeprecations(static fn(): array => AllowedViewModesUtility::fromPageTsConfig($tsConfig));

        self::assertSame(['list', 'compact', 'teaser'], $deprecations['result']);
        self::assertSame([], $deprecations['messages']);
    }

    #[Test]
    public function fallsBackToTheLegacyKeyAndLogsTheDeprecationOnce(): void
    {
        $tsConfig = ['mod.' => ['web_list.' => ['allowedViews' => 'list,grid']]];

        $deprecations = $this->collectDeprecations(static function () use ($tsConfig): array {
            $first = AllowedViewModesUtility::fromPageTsConfig($tsConfig);
            $second = AllowedViewModesUtility::fromPageTsConfig($tsConfig);
            return [$first, $second];
        });

        self::assertSame([['list', 'grid'], ['list', 'grid']], $deprecations['result']);
        self::assertCount(1, $deprecations['messages']);
        self::assertStringContainsString('mod.web_list.allowedViews', $deprecations['messages'][0]);
        self::assertStringContainsString('mod.web_list.viewMode.allowed', $deprecations['messages'][0]);
    }

    /**
     * @param callable(): mixed $callback
     * @return array{result: mixed, messages: list<string>}
     */
    private function collectDeprecations(callable $callback): array
    {
        $messages = [];
        set_error_handler(static function (int $errorNumber, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        return ['result' => $result, 'messages' => $messages];
    }
}
