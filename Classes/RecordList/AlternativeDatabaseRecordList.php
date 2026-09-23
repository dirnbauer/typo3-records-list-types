<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\RecordList;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Domain\Persistence\RecordIdentityMap;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Exposes the parts of Core's record list that alternative views reuse: table
 * selection, heading actions, and per record the icon with its context menu
 * and the control panel. Rendering them here keeps every view in step with
 * the list view, including permissions, User TSconfig and the actions other
 * extensions add through ModifyRecordListRecordActionsEvent.
 */
final class AlternativeDatabaseRecordList extends DatabaseRecordList
{
    /**
     * @return list<string>
     */
    #[\Override]
    public function getTablesToRender(): array
    {
        return array_values(array_filter(parent::getTablesToRender(), is_string(...)));
    }

    /**
     * @param array<mixed> $currentIdList
     */
    #[\Override]
    public function renderMultiRecordSelectionActions(string $table, array $currentIdList): string
    {
        return parent::renderMultiRecordSelectionActions($table, $currentIdList);
    }

    /**
     * @return array<string, string>
     */
    public function getTableActions(string $table, int $recordCount, bool $singleTable): array
    {
        return [
            'newRecordButton' => $this->createActionButtonNewRecord($table)?->render() ?? '',
            'downloadButton' => $this->createActionButtonDownload($table, $recordCount)?->render() ?? '',
            'columnSelectorButton' => $this->createActionButtonColumnSelector($table)?->render() ?? '',
            'collapseButton' => $singleTable ? '' : ($this->createActionButtonCollapse($table)?->render() ?? ''),
        ];
    }

    /**
     * Core's control panel for one record: edit, visibility, delete, move up
     * and down, info, history, clipboard and every action added through
     * ModifyRecordListRecordActionsEvent.
     *
     * The overflow menu is turned into a popover. Cards clip their content,
     * and a Bootstrap dropdown opened inside one would be cut off; a popover
     * lives in the top layer and the backend positions it at its button.
     *
     * @param array<string, mixed> $row A row after the workspace overlay
     */
    public function renderRecordControls(string $table, array $row): string
    {
        return $this->convertDropdownsToPopovers($this->makeControl($this->createRecord($table, $row)));
    }

    /**
     * The record icon with its state overlays (hidden, scheduled, workspace
     * state) and, as in the list view, a button that opens the context menu.
     *
     * @param array<string, mixed> $row A row after the workspace overlay
     */
    public function renderRecordIcon(string $table, array $row): string
    {
        $icon = $this->iconFactory
            ->getIconForRecord($table, $row, IconSize::SMALL)
            ->setTitle(BackendUtility::getRecordIconAltText($row, $table, false))
            ->render('inline');
        if (!$this->clickMenuEnabled || $this->isRecordDeletePlaceholder($this->createRecord($table, $row))) {
            return $icon;
        }

        $uid = $row['uid'] ?? 0;

        return BackendUtility::wrapClickMenuOnIcon($icon, $table, is_numeric($uid) ? (int)$uid : 0, '', $row);
    }

    /**
     * The message Core shows when another user is editing the record right now.
     *
     * @param array<string, mixed> $row
     */
    public function getRecordLockMessage(string $table, array $row): string
    {
        $uid = $row['uid'] ?? 0;
        $lockInfo = BackendUtility::isRecordLocked($table, is_numeric($uid) ? (int)$uid : 0);
        if (!is_array($lockInfo)) {
            return '';
        }
        $message = $lockInfo['msg'] ?? '';

        return is_string($message) ? $message : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isDeletePlaceholder(string $table, array $row): bool
    {
        return $this->isRecordDeletePlaceholder($this->createRecord($table, $row));
    }

    /**
     * Records the neighbours of every listed record the way getTable() does
     * while it renders the list view. makeControl() needs them for the "Move
     * up" and "Move down" buttons, which are also the single-pointer
     * alternative to dragging a card.
     *
     * Only call this for records in ascending manual order: with a sort field
     * or a search the buttons would move records against the visible order,
     * and Core hides them in that case too.
     *
     * @param list<array<string, mixed>> $rows Default-language rows in the listed order
     */
    public function prepareManualSorting(string $table, array $rows): void
    {
        $this->currentTable = [];
        if (!$this->tcaSchemaFactory->has($table)
            || !$this->tcaSchemaFactory->get($table)->hasCapability(TcaSchemaCapability::SortByField)
            || $this->sortField !== ''
        ) {
            return;
        }

        $prevUid = 0;
        $prevPrevUid = 0;
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
            $pid = is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0;
            if ($uid <= 0) {
                continue;
            }
            if ($prevUid !== 0) {
                $this->currentTable['prev'][$uid] = $prevPrevUid;
                $this->currentTable['next'][$prevUid] = -$uid;
                $this->currentTable['prevUid'][$uid] = $prevUid;
            }
            $prevPrevUid = isset($this->currentTable['prev'][$uid]) ? -$prevUid : $pid;
            $prevUid = $uid;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createRecord(string $table, array $row): RecordInterface
    {
        $this->recordIdentityMap ??= GeneralUtility::makeInstance(RecordIdentityMap::class);

        return $this->recordFactory->createResolvedRecordFromDatabaseRow($table, $row, null, $this->recordIdentityMap);
    }

    private function convertDropdownsToPopovers(string $html): string
    {
        if (!str_contains($html, 'data-bs-toggle="dropdown"')) {
            return $html;
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
        );
        $body = $document->body;
        if (!$body instanceof HTMLElement) {
            return $html;
        }

        $converted = false;
        foreach ($body->querySelectorAll('a[data-bs-toggle="dropdown"][href^="#"]') as $toggle) {
            $menuId = substr($toggle->getAttribute('href') ?? '', 1);
            $menu = $menuId !== '' ? $document->getElementById($menuId) : null;
            if (!$menu instanceof Element || !$menu->classList->contains('dropdown-menu')) {
                continue;
            }

            $button = $document->createElement('button');
            $button->setAttribute('type', 'button');
            foreach ($toggle->attributes as $attribute) {
                if (!in_array($attribute->name, ['href', 'data-bs-toggle', 'data-bs-boundary', 'aria-expanded'], true)) {
                    $button->setAttribute($attribute->name, $attribute->value);
                }
            }
            $title = $toggle->getAttribute('title') ?? '';
            if ($title !== '' && !$button->hasAttribute('aria-label')) {
                $button->setAttribute('aria-label', $title);
            }
            $button->setAttribute('popovertarget', $menuId);
            while ($toggle->firstChild instanceof Node) {
                $button->appendChild($toggle->firstChild);
            }
            $toggle->replaceWith($button);
            $menu->setAttribute('popover', '');
            $converted = true;
        }

        return $converted ? $body->innerHTML : $html;
    }
}
