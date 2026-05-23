<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\RecordsListTypes\Service\RecordDisplayValueFormatter;

final class RecordDisplayValueFormatterTest extends TestCase
{
    #[Test]
    public function formatFieldValueStripsHtmlAndNormalizesWhitespaceForText(): void
    {
        $value = '<p>Hello <strong>world</strong></p>' . "\n" . '<p>Again</p>';

        self::assertSame(
            'Hello world Again',
            $this->createSubject()->formatFieldValue($value, 'text', 'bodytext', []),
        );
    }

    #[Test]
    public function formatFieldValueFormatsTimestampAsBackendDateTime(): void
    {
        self::assertSame(
            '01.01.2026 00:00',
            $this->createSubject()->formatFieldValue(1_767_225_600, 'datetime', 'crdate', []),
        );
    }

    #[Test]
    public function formatFieldValueResolvesTranslatedSelectItemLabel(): void
    {
        $tcaColumns = [
            'status' => [
                'config' => [
                    'items' => [
                        [
                            'label' => 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:status.published',
                            'value' => 'published',
                        ],
                    ],
                ],
            ],
        ];

        $formatted = $this->createSubject()->formatFieldValue(
            'published',
            'select',
            'status',
            $tcaColumns,
            static fn(string $label): string => $label === 'LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:status.published' ? 'Published' : '',
        );

        self::assertSame('Published', $formatted);
    }

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
