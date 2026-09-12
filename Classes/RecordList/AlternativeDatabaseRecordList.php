<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\RecordList;

use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;

/**
 * Exposes Core's table selection and header actions to alternative renderers.
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
}
