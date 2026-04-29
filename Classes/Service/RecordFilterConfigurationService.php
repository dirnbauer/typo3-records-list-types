<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves configurable record filters from Page TSconfig and TCA metadata.
 */
final readonly class RecordFilterConfigurationService implements SingletonInterface
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private ConnectionPool $connectionPool,
    ) {}

    public function isEnabled(int $pageId): bool
    {
        $config = $this->getFilterTsConfig($pageId);
        return $this->parseBoolean($config['enabled'] ?? '1');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFiltersForTable(string $table, int $pageId): array
    {
        if (!$this->isEnabled($pageId) || !$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        $config = $this->getFilterTsConfig($pageId);
        $tableConfig = $this->getTableConfig($config, $table);
        if ($tableConfig !== [] && !$this->parseBoolean($tableConfig['enabled'] ?? '1')) {
            return [];
        }

        $filterIds = $this->resolveFilterIds($config, $tableConfig);
        $filters = [];
        foreach ($filterIds as $filterId) {
            $filter = $this->buildFilter($table, $filterId, $config, $tableConfig);
            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    public function getWarningsForTable(string $table, int $pageId): array
    {
        if (!$this->isEnabled($pageId) || !$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        $config = $this->getFilterTsConfig($pageId);
        $tableConfig = $this->getTableConfig($config, $table);
        if ($tableConfig !== [] && !$this->parseBoolean($tableConfig['enabled'] ?? '1')) {
            return [];
        }

        $warnings = [];
        foreach ($this->resolveFilterIds($config, $tableConfig) as $filterId) {
            $filterConfig = $this->getFilterConfig($filterId, $config, $tableConfig);
            if (!$this->parseBoolean($filterConfig['enabled'] ?? '1')) {
                continue;
            }
            $type = is_string($filterConfig['type'] ?? null) ? $filterConfig['type'] : $this->defaultTypeForId($filterId);
            if ($type !== 'llm') {
                continue;
            }
            $warning = $this->getLlmAvailabilityWarning($filterConfig);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTca(string $table): array
    {
        /** @var array<string, mixed> $allTca */
        $allTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $tca = $allTca[$table] ?? [];
        return is_array($tca) ? $tca : [];
    }

    /**
     * @return list<string>
     */
    public function resolveFields(string $table, string $fields): array
    {
        $result = [];
        foreach (GeneralUtility::trimExplode(',', $fields, true) as $field) {
            $resolved = $this->resolveFieldAlias($table, $field);
            if ($resolved !== '' && $this->fieldExists($table, $resolved)) {
                $result[] = $resolved;
            }
        }

        return array_values(array_unique($result));
    }

    public function fieldExists(string $table, string $field): bool
    {
        if (in_array($field, ['uid', 'pid'], true)) {
            return true;
        }
        if ($this->schemaFieldExists($table, $field)) {
            return true;
        }
        $tca = $this->getTca($table);
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        if (isset($columns[$field])) {
            return true;
        }
        $ctrl = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        return in_array($field, array_filter([
            $ctrl['label'] ?? null,
            $ctrl['crdate'] ?? null,
            $ctrl['tstamp'] ?? null,
            $ctrl['sortby'] ?? null,
            $enableColumns['disabled'] ?? null,
        ], static fn(mixed $value): bool => is_string($value)), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldConfig(string $table, string $field): array
    {
        $tca = $this->getTca($table);
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        $column = $columns[$field] ?? [];
        return is_array($column) ? $column : [];
    }

    public function getFieldLabel(string $table, string $field): string
    {
        if ($field === 'uid') {
            return 'UID';
        }
        if ($field === 'pid') {
            return 'Page';
        }

        $schemaLabel = $this->getSchemaFieldLabel($table, $field);
        if ($schemaLabel !== '') {
            return $this->translateLabel($schemaLabel, $field);
        }

        $column = $this->getFieldConfig($table, $field);
        $label = is_string($column['label'] ?? null) ? $column['label'] : '';
        if ($label !== '') {
            return $this->translateLabel($label, $field);
        }

        $tca = $this->getTca($table);
        $ctrl = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        if ($field === ($enableColumns['disabled'] ?? null)) {
            $translated = $this->getLanguageService()?->sL('core.general:LGL.hidden') ?? '';
            return $translated !== '' ? $translated : 'Hidden';
        }
        if ($field === ($ctrl['crdate'] ?? null)) {
            $translated = $this->getLanguageService()?->sL('core.general:LGL.creationDate') ?? '';
            return $translated !== '' ? $translated : 'Created';
        }
        if ($field === ($ctrl['tstamp'] ?? null)) {
            $translated = $this->getLanguageService()?->sL('core.general:LGL.timestamp') ?? '';
            return $translated !== '' ? $translated : 'Modified';
        }

        return $field;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getCategoryOptions(): array
    {
        if (!$this->tcaSchemaFactory->has('sys_category')) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('sys_category')
            ->orderBy('title')
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        $options = [];
        foreach ($rows as $row) {
            $uid = $row['uid'] ?? null;
            $title = $row['title'] ?? '';
            if (!is_numeric($uid)) {
                continue;
            }
            $options[] = [
                'value' => (string) (int) $uid,
                'label' => is_scalar($title) ? (string) $title : (string) (int) $uid,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function getFilterTsConfig(int $pageId): array
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $filters = $tsConfig['mod.']['web_list.']['filters.'] ?? [];
        return is_array($filters) ? $filters : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function getTableConfig(array $config, string $table): array
    {
        $tables = is_array($config['table.'] ?? null) ? $config['table.'] : [];
        $tableConfig = $tables[$table . '.'] ?? [];
        return is_array($tableConfig) ? $tableConfig : [];
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $tableConfig
     * @return list<string>
     */
    private function resolveFilterIds(array $globalConfig, array $tableConfig): array
    {
        $configured = $tableConfig['fields'] ?? $globalConfig['fields'] ?? null;
        if (is_scalar($configured)) {
            $fields = GeneralUtility::trimExplode(',', (string) $configured, true);
            if (in_array('none', $fields, true)) {
                return [];
            }
            if ($fields !== []) {
                return $fields;
            }
        }

        $defaults = $tableConfig['autoDefaults'] ?? $globalConfig['autoDefaults'] ?? 'title';
        return is_scalar($defaults) ? GeneralUtility::trimExplode(',', (string) $defaults, true) : ['title'];
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $tableConfig
     * @return array<string, mixed>|null
     */
    private function buildFilter(string $table, string $filterId, array $globalConfig, array $tableConfig): ?array
    {
        $filterConfig = $this->getFilterConfig($filterId, $globalConfig, $tableConfig);
        if (!$this->parseBoolean($filterConfig['enabled'] ?? '1')) {
            return null;
        }

        $type = is_string($filterConfig['type'] ?? null) ? $filterConfig['type'] : $this->defaultTypeForId($filterId);
        return match ($type) {
            'text', 'search' => $this->buildTextFilter($table, $filterId, $filterConfig),
            'boolean' => $this->buildBooleanFilter($table, $filterId, $filterConfig),
            'dateRange', 'date-range' => $this->buildDateRangeFilter($table, $filterId, $filterConfig),
            'select' => $this->buildSelectFilter($table, $filterId, $filterConfig),
            'category', 'categories' => $this->buildCategoryFilter($table, $filterId, $filterConfig),
            'llm' => $this->buildLlmFilter($table, $filterId, $filterConfig),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $tableConfig
     * @return array<string, mixed>
     */
    private function getFilterConfig(string $filterId, array $globalConfig, array $tableConfig): array
    {
        $globalPreset = [];
        $presets = is_array($globalConfig['presets.'] ?? null) ? $globalConfig['presets.'] : [];
        if (is_array($presets[$filterId . '.'] ?? null)) {
            $globalPreset = $presets[$filterId . '.'];
        }
        $local = is_array($tableConfig[$filterId . '.'] ?? null) ? $tableConfig[$filterId . '.'] : [];
        return array_replace_recursive($globalPreset, $local);
    }

    /**
     * @param array<string, mixed> $filterConfig
     * @return array<string, mixed>|null
     */
    private function buildTextFilter(string $table, string $filterId, array $filterConfig): ?array
    {
        $fieldsRaw = is_scalar($filterConfig['fields'] ?? null) ? (string) $filterConfig['fields'] : '';
        $fieldRaw = is_scalar($filterConfig['field'] ?? null) ? (string) $filterConfig['field'] : '';
        $fields = $fieldsRaw !== ''
            ? $this->resolveFields($table, $fieldsRaw)
            : $this->resolveFields($table, $fieldRaw !== '' ? $fieldRaw : 'label');
        if ($fields === []) {
            return null;
        }

        return [
            'id' => $filterId,
            'type' => 'text',
            'label' => $this->resolveFilterLabel($filterConfig, $this->getFieldLabel($table, $fields[0])),
            'fields' => $fields,
            'placeholder' => is_string($filterConfig['placeholder'] ?? null) ? $filterConfig['placeholder'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $filterConfig
     * @return array<string, mixed>|null
     */
    private function buildBooleanFilter(string $table, string $filterId, array $filterConfig): ?array
    {
        $field = $this->resolveConfiguredField($table, $filterConfig, $filterId === 'hidden' ? 'hidden' : '');
        if ($field === '') {
            return null;
        }

        return [
            'id' => $filterId,
            'type' => 'boolean',
            'label' => $this->resolveFilterLabel($filterConfig, $this->getFieldLabel($table, $field)),
            'field' => $field,
            'options' => [
                ['value' => '', 'label' => $this->resolveOptionLabel($filterConfig, 'anyLabel', $this->translate('filter.option.any', 'Any'))],
                ['value' => '0', 'label' => $this->resolveOptionLabel($filterConfig, 'falseLabel', $this->translate('filter.option.visible', 'Visible'))],
                ['value' => '1', 'label' => $this->resolveOptionLabel($filterConfig, 'trueLabel', $this->translate('filter.option.hidden', 'Hidden'))],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filterConfig
     * @return array<string, mixed>|null
     */
    private function buildDateRangeFilter(string $table, string $filterId, array $filterConfig): ?array
    {
        $field = $this->resolveConfiguredField($table, $filterConfig, 'date');
        if ($field === '') {
            return null;
        }

        return [
            'id' => $filterId,
            'type' => 'dateRange',
            'label' => $this->resolveFilterLabel($filterConfig, $this->getFieldLabel($table, $field)),
            'field' => $field,
        ];
    }

    /**
     * @param array<string, mixed> $filterConfig
     * @return array<string, mixed>|null
     */
    private function buildSelectFilter(string $table, string $filterId, array $filterConfig): ?array
    {
        $field = $this->resolveConfiguredField($table, $filterConfig, '');
        if ($field === '') {
            return null;
        }
        $options = $this->getSelectOptions($table, $field);
        if ($options === []) {
            return null;
        }

        array_unshift($options, ['value' => '', 'label' => $this->translate('filter.option.any', 'Any')]);
        return [
            'id' => $filterId,
            'type' => 'select',
            'label' => $this->resolveFilterLabel($filterConfig, $this->getFieldLabel($table, $field)),
            'field' => $field,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $filterConfig
     * @return array<string, mixed>|null
     */
    private function buildCategoryFilter(string $table, string $filterId, array $filterConfig): ?array
    {
        $field = $this->resolveConfiguredField($table, $filterConfig, 'category');
        if ($field === '') {
            return null;
        }
        $options = $this->getCategoryOptions();
        if ($options === []) {
            return null;
        }

        array_unshift($options, ['value' => '', 'label' => $this->translate('filter.option.any', 'Any')]);
        return [
            'id' => $filterId,
            'type' => 'category',
            'label' => $this->resolveFilterLabel($filterConfig, $this->getFieldLabel($table, $field)),
            'field' => $field,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $filterConfig
     * @return array<string, mixed>|null
     */
    private function buildLlmFilter(string $table, string $filterId, array $filterConfig): ?array
    {
        if ($this->getLlmAvailabilityWarning($filterConfig) !== null) {
            return null;
        }

        $configurationIdentifier = $this->getLlmConfigurationIdentifier($filterConfig);
        $fields = is_scalar($filterConfig['fields'] ?? null)
            ? $this->resolveFields($table, (string) $filterConfig['fields'])
            : $this->resolveFields($table, 'label');
        if ($fields === []) {
            return null;
        }

        return [
            'id' => $filterId,
            'type' => 'llm',
            'label' => $this->resolveFilterLabel($filterConfig, $this->translate('filter.llm.label', 'Ask records')),
            'fields' => $fields,
            'configurationIdentifier' => $configurationIdentifier,
            'candidateLimit' => $this->positiveInt($filterConfig['candidateLimit'] ?? 80, 80),
            'resultLimit' => $this->positiveInt($filterConfig['resultLimit'] ?? 25, 25),
            'placeholder' => is_string($filterConfig['placeholder'] ?? null) ? $filterConfig['placeholder'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $filterConfig
     */
    private function getLlmConfigurationIdentifier(array $filterConfig): string
    {
        foreach (['configurationIdentifier', 'configuration'] as $key) {
            $value = $filterConfig[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $identifier = trim((string) $value);
            if ($identifier !== '') {
                return $identifier;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $filterConfig
     */
    private function getLlmAvailabilityWarning(array $filterConfig): ?string
    {
        if (!interface_exists('Netresearch\\NrLlm\\Service\\LlmServiceManagerInterface')) {
            return $this->translate(
                'filter.llm.warning.extensionMissing',
                'LLM filter is configured, but EXT:nr_llm is not installed.',
            );
        }

        $configurationIdentifier = $this->getLlmConfigurationIdentifier($filterConfig);
        if ($configurationIdentifier === '') {
            return $this->translate(
                'filter.llm.warning.identifierMissing',
                'LLM filter is configured, but no nr_llm configurationIdentifier is set.',
            );
        }

        $configuration = $this->getLlmConfiguration($configurationIdentifier);
        if (!is_object($configuration)) {
            return sprintf(
                $this->translate(
                    'filter.llm.warning.configurationMissing',
                    'LLM filter is configured, but no nr_llm configuration with identifier "%s" was found.',
                ),
                $configurationIdentifier,
            );
        }
        if (method_exists($configuration, 'isActive') && !$configuration->isActive()) {
            return sprintf(
                $this->translate(
                    'filter.llm.warning.configurationInactive',
                    'LLM filter is configured, but the nr_llm configuration "%s" is inactive.',
                ),
                $configurationIdentifier,
            );
        }
        if (!$this->hasAvailableLlmProvider()) {
            return $this->translate(
                'filter.llm.warning.providerMissing',
                'LLM filter is configured, but nr_llm has no available provider.',
            );
        }

        return null;
    }

    private function getLlmConfiguration(string $identifier): ?object
    {
        $repositoryClass = 'Netresearch\\NrLlm\\Domain\\Repository\\LlmConfigurationRepository';
        if (!class_exists($repositoryClass)) {
            return null;
        }

        $container = GeneralUtility::getContainer();
        if (!$container->has($repositoryClass)) {
            return null;
        }

        $repository = $container->get($repositoryClass);
        if (!is_object($repository) || !method_exists($repository, 'findOneByIdentifier')) {
            return null;
        }

        try {
            $configuration = $repository->findOneByIdentifier($identifier);
        } catch (Throwable) {
            return null;
        }

        return is_object($configuration) ? $configuration : null;
    }

    private function hasAvailableLlmProvider(): bool
    {
        $interface = 'Netresearch\\NrLlm\\Service\\LlmServiceManagerInterface';
        $container = GeneralUtility::getContainer();
        if (!$container->has($interface)) {
            return false;
        }

        $service = $container->get($interface);
        if (!is_object($service) || !method_exists($service, 'hasAvailableProvider')) {
            return false;
        }

        try {
            return (bool) $service->hasAvailableProvider();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $filterConfig
     */
    private function resolveConfiguredField(string $table, array $filterConfig, string $fallbackAlias): string
    {
        $fieldRaw = is_scalar($filterConfig['field'] ?? null) ? (string) $filterConfig['field'] : '';
        $field = $fieldRaw !== '' ? $this->resolveFieldAlias($table, $fieldRaw) : $this->resolveFieldAlias($table, $fallbackAlias);
        return $field !== '' && $this->fieldExists($table, $field) ? $field : '';
    }

    private function resolveFieldAlias(string $table, string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }
        $tca = $this->getTca($table);
        $ctrl = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];

        if (in_array($field, ['label', 'title'], true)) {
            $label = $ctrl['label'] ?? '';
            return is_string($label) && $label !== '' ? $label : 'uid';
        }
        if ($field === 'hidden') {
            $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
            $disabled = $enableColumns['disabled'] ?? '';
            return is_string($disabled) ? $disabled : '';
        }
        if (in_array($field, ['date', 'datetime'], true)) {
            foreach (['datetime', 'date', 'starttime'] as $candidate) {
                if (isset($columns[$candidate])) {
                    return $candidate;
                }
            }
            $crdate = $ctrl['crdate'] ?? '';
            return is_string($crdate) ? $crdate : '';
        }
        if (in_array($field, ['category', 'categories'], true)) {
            return $this->resolveCategoryField($table);
        }
        if ($field === 'teaser') {
            foreach (['teaser', 'abstract', 'description', 'bodytext', 'short'] as $candidate) {
                if (isset($columns[$candidate])) {
                    return $candidate;
                }
            }
            return '';
        }

        return $field;
    }

    private function defaultTypeForId(string $filterId): string
    {
        return match ($filterId) {
            'hidden' => 'boolean',
            'date', 'datetime', 'dateRange', 'timeRange' => 'dateRange',
            'category', 'categories' => 'category',
            'llm', 'ai' => 'llm',
            default => 'text',
        };
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (!is_scalar($value)) {
            return false;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', ''], true);
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    /**
     * @param array<string, mixed> $filterConfig
     */
    private function resolveFilterLabel(array $filterConfig, string $fallback): string
    {
        $label = is_string($filterConfig['label'] ?? null) ? $filterConfig['label'] : '';
        return $label !== '' ? $this->translateLabel($label, $fallback) : $fallback;
    }

    /**
     * @param array<string, mixed> $filterConfig
     */
    private function resolveOptionLabel(array $filterConfig, string $key, string $fallback): string
    {
        $label = is_string($filterConfig[$key] ?? null) ? $filterConfig[$key] : '';
        return $label !== '' ? $this->translateLabel($label, $fallback) : $fallback;
    }

    private function translateLabel(string $label, string $fallback): string
    {
        if (str_starts_with($label, 'LLL:') || str_contains($label, ':')) {
            $translated = $this->getLanguageService()?->sL($label) ?? '';
            return $translated !== '' ? $translated : $fallback;
        }
        return $label;
    }

    private function translate(string $key, string $fallback): string
    {
        $translated = $this->getLanguageService()?->sL('LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:' . $key) ?? '';
        return $translated !== '' ? $translated : $fallback;
    }

    private function getLanguageService(): ?LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        return $lang instanceof LanguageService ? $lang : null;
    }

    private function schemaFieldExists(string $table, string $field): bool
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return false;
        }

        try {
            return $this->tcaSchemaFactory->get($table)->hasField($field);
        } catch (Throwable) {
            return false;
        }
    }

    private function getSchemaFieldLabel(string $table, string $field): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return '';
        }

        try {
            $schema = $this->tcaSchemaFactory->get($table);
            return $schema->hasField($field) ? $schema->getField($field)->getLabel() : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function resolveCategoryField(string $table): string
    {
        $field = $this->resolveCategoryFieldFromSchema($table);
        return $field !== '' ? $field : $this->resolveCategoryFieldFromTca($table);
    }

    private function resolveCategoryFieldFromSchema(string $table): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return '';
        }

        try {
            $schema = $this->tcaSchemaFactory->get($table);
        } catch (Throwable) {
            return '';
        }

        $fallbackFields = [];
        foreach ($schema->getFields() as $fieldName => $field) {
            if (!$field->isType(TableColumnType::CATEGORY)) {
                continue;
            }
            if (!$this->isManyToManyCategoryConfiguration($table, (string) $fieldName, $field->getConfiguration())) {
                continue;
            }
            if ($fieldName === 'categories') {
                return 'categories';
            }
            if ($fieldName === 'category') {
                array_unshift($fallbackFields, 'category');
                continue;
            }
            $fallbackFields[] = (string) $fieldName;
        }

        return $fallbackFields[0] ?? '';
    }

    private function resolveCategoryFieldFromTca(string $table): string
    {
        $tca = $this->getTca($table);
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        $fallbackFields = [];
        foreach ($columns as $fieldName => $column) {
            if (!is_array($column)) {
                continue;
            }
            $config = is_array($column['config'] ?? null) ? $column['config'] : [];
            if (!$this->isManyToManyCategoryConfiguration($table, (string) $fieldName, $config)) {
                continue;
            }
            if ($fieldName === 'categories') {
                return 'categories';
            }
            if ($fieldName === 'category') {
                array_unshift($fallbackFields, 'category');
                continue;
            }
            $fallbackFields[] = (string) $fieldName;
        }

        return $fallbackFields[0] ?? '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isManyToManyCategoryConfiguration(string $table, string $fieldName, array $config): bool
    {
        if ($table === 'sys_category') {
            return false;
        }

        $type = is_string($config['type'] ?? null) ? $config['type'] : '';
        $foreignTable = is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : '';
        $mmTable = is_string($config['MM'] ?? null) ? $config['MM'] : '';
        $relationship = is_string($config['relationship'] ?? null) ? $config['relationship'] : '';

        if ($type === 'category') {
            return $relationship === ''
                || $relationship === 'manyToMany'
                || $mmTable === 'sys_category_record_mm';
        }

        return $foreignTable === 'sys_category'
            && $mmTable === 'sys_category_record_mm'
            && $fieldName !== '';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function getSelectOptions(string $table, string $field): array
    {
        $column = $this->getFieldConfig($table, $field);
        $config = is_array($column['config'] ?? null) ? $column['config'] : [];
        $items = is_array($config['items'] ?? null) ? $config['items'] : [];
        $options = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = $item['value'] ?? $item[1] ?? null;
            $label = $item['label'] ?? $item[0] ?? null;
            if (!is_scalar($value) || !is_scalar($label)) {
                continue;
            }
            $options[] = [
                'value' => (string) $value,
                'label' => $this->translateLabel((string) $label, (string) $value),
            ];
        }

        return $options;
    }
}
