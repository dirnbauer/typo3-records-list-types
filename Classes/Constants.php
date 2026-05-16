<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes;

/**
 * Extension-wide constants for records_list_types.
 *
 * Centralizes magic strings and configuration values to improve maintainability.
 */
final class Constants
{
    /** Extension key. */
    public const EXTENSION_KEY = 'records_list_types';

    /** Backend module route identifier. */
    public const MODULE_ROUTE = 'records';

    /** Module identifiers for route matching. */
    public const MODULE_IDENTIFIERS = ['records', 'web_list'];

    /** User configuration key for storing view mode preference. */
    public const USER_CONFIG_KEY = 'records_view_mode';

    /** Default view mode when none is configured. */
    public const DEFAULT_VIEW_MODE = 'list';

    /** Available built-in view modes. */
    public const BUILTIN_VIEW_MODES = ['list', 'grid', 'compact', 'teaser'];

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct() {}
}
