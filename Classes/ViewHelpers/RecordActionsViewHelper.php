<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\ViewHelpers;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Webconsulting\RecordsListTypes\EventListener\GridViewRecordActionsListener;

/**
 * RecordActionsViewHelper - Renders record actions in Grid View cards.
 *
 * This ViewHelper retrieves cached record actions from the
 * GridViewRecordActionsListener and renders them as HTML.
 *
 * Usage:
 * <gridview:recordActions table="tx_news_domain_model_news" uid="{record.uid}" />
 *
 * Or to get actions as array:
 * <f:for each="{gridview:recordActions(table: 'pages', uid: record.uid, asArray: true)}" as="action">
 *     {action -> f:format.raw()}
 * </f:for>
 */
final class RecordActionsViewHelper extends AbstractViewHelper
{
    /** Do not escape output - actions contain HTML. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'table',
            'string',
            'The database table name',
            true,
        );
        $this->registerArgument(
            'uid',
            'int',
            'The record UID',
            true,
        );
        $this->registerArgument(
            'group',
            'string',
            'Action group to retrieve: "primary", "secondary", or "all"',
            false,
            'all',
        );
        $this->registerArgument(
            'asArray',
            'bool',
            'Return actions as array instead of rendered HTML',
            false,
            false,
        );
        $this->registerArgument(
            'separator',
            'string',
            'Separator between actions when rendering as HTML',
            false,
            '',
        );
    }

    /**
     * @return string|array<int, string>
     */
    public function render(): string|array
    {
        $tableRaw = $this->arguments['table'] ?? '';
        $table = is_string($tableRaw) ? $tableRaw : '';
        $uidRaw = $this->arguments['uid'] ?? 0;
        $uid = is_numeric($uidRaw) ? (int) $uidRaw : 0;
        $groupRaw = $this->arguments['group'] ?? 'all';
        $group = is_string($groupRaw) ? $groupRaw : 'all';
        $asArrayRaw = $this->arguments['asArray'] ?? false;
        $asArray = $asArrayRaw === true;
        $separatorRaw = $this->arguments['separator'] ?? '';
        $separator = is_string($separatorRaw) ? $separatorRaw : '';

        // Get the actions listener (singleton)
        $listener = GeneralUtility::makeInstance(GridViewRecordActionsListener::class);

        // Get actions based on requested group
        $actions = match ($group) {
            'primary' => $listener->getPrimaryActionsHtml($table, $uid),
            'all' => $listener->getAllActionsHtml($table, $uid),
            default => $listener->getAllActionsHtml($table, $uid),
        };

        if ($asArray) {
            return $actions;
        }

        return implode($separator, $actions);
    }
}
