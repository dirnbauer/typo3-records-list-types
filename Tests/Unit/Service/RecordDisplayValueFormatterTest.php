<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Service\RecordDisplayValueFormatter;

final class RecordDisplayValueFormatterTest extends TestCase
{
    #[Test]
    public function shouldInvertBooleanDisplayReadsConfigLevelFlag(): void
    {
        $tcaColumns = [
            'hidden' => [
                'config' => [
                    'type' => 'check',
                    'invertStateDisplay' => true,
                ],
            ],
        ];

        self::assertTrue($this->createSubject()->shouldInvertBooleanDisplay('hidden', $tcaColumns));
    }

    #[Test]
    public function shouldInvertBooleanDisplayReadsItemLevelFlag(): void
    {
        $tcaColumns = [
            'hidden' => [
                'config' => [
                    'type' => 'check',
                    'items' => [
                        [
                            'invertStateDisplay' => true,
                        ],
                    ],
                ],
            ],
        ];

        self::assertTrue($this->createSubject()->shouldInvertBooleanDisplay('hidden', $tcaColumns));
    }

    private function createSubject(): RecordDisplayValueFormatter
    {
        return new RecordDisplayValueFormatter();
    }
}
