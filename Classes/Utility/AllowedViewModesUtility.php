<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Utility;

/**
 * Resolves the allowed view modes from Page TSconfig.
 *
 * `mod.web_list.viewMode.allowed` is the supported option. The pre-1.0 key
 * `mod.web_list.allowedViews` is still honoured as a fallback but logs a
 * deprecation once per request; it will be removed in version 2.0.
 */
final class AllowedViewModesUtility
{
    private const string PREFERRED_PATH = 'mod.web_list.viewMode.allowed';
    private const string LEGACY_PATH = 'mod.web_list.allowedViews';

    private static bool $deprecationTriggered = false;

    /**
     * @param array<mixed> $tsConfig Full Page TSconfig array
     * @return list<string> Configured view mode identifiers; empty when nothing is configured
     */
    public static function fromPageTsConfig(array $tsConfig): array
    {
        $value = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'viewMode.', 'allowed']);
        if ($value === null) {
            $value = ArrayUtility::valuePath($tsConfig, ['mod.', 'web_list.', 'allowedViews']);
            if ($value !== null) {
                self::triggerDeprecation();
            }
        }

        return ArrayUtility::commaSeparatedList($value);
    }

    /**
     * Allows the deprecation to be logged again, for tests only.
     */
    public static function resetDeprecationState(): void
    {
        self::$deprecationTriggered = false;
    }

    private static function triggerDeprecation(): void
    {
        if (self::$deprecationTriggered) {
            return;
        }
        self::$deprecationTriggered = true;

        trigger_error(
            sprintf(
                'Page TSconfig "%s" is deprecated since records_list_types 1.1.0 and will be removed in 2.0. Use "%s" instead.',
                self::LEGACY_PATH,
                self::PREFERRED_PATH,
            ),
            E_USER_DEPRECATED,
        );
    }
}
