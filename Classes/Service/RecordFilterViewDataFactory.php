<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Builds Fluid-ready view data for record filters.
 */
final readonly class RecordFilterViewDataFactory
{
    public function __construct(
        private RecordFilterConfigurationService $configurationService,
        private RecordFilterStateService $stateService,
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createForTable(
        string $table,
        int $pageId,
        string $viewMode,
        ServerRequestInterface $request,
    ): array {
        $filters = $this->configurationService->getFiltersForTable($table, $pageId);
        $filters = $this->stateService->attachValues($filters, $request, $table);
        $selectedTable = $this->stateService->getSelectedTable($request);

        return [
            'visible' => $this->stateService->shouldShow($request) && $selectedTable === $table,
            'tableName' => $table,
            'items' => $filters,
            'warnings' => $this->configurationService->getWarningsForTable($table, $pageId),
            'hiddenFields' => $this->buildHiddenFields($request, $pageId, $table, $viewMode),
            'formActionUrl' => $this->buildRouteUrl(['id' => $pageId, 'displayMode' => $viewMode, 'table' => $table]),
            'resetUrl' => $this->buildResetUrl($request, $pageId, $table, $viewMode),
        ];
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function buildHiddenFields(ServerRequestInterface $request, int $pageId, string $table, string $viewMode): array
    {
        $params = $this->stateService->getMergedParameters($request);
        $preserved = [
            'id' => $pageId,
            'displayMode' => $viewMode,
            'table' => $table,
            RecordFilterStateService::SHOW_PARAMETER => '1',
        ];
        foreach (['searchTerm', 'search_levels', 'sort', 'sortingMode'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $preserved[$key] = $params[$key];
            }
        }

        return $this->flattenParameters($preserved);
    }

    private function buildResetUrl(ServerRequestInterface $request, int $pageId, string $table, string $viewMode): string
    {
        $params = $this->stateService->getMergedParameters($request);
        $routeParams = [
            'id' => $pageId,
            'displayMode' => $viewMode,
            'table' => $table,
            RecordFilterStateService::SHOW_PARAMETER => '1',
        ];
        foreach (['searchTerm', 'search_levels', 'sort', 'sortingMode'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $routeParams[$key] = $params[$key];
            }
        }

        return $this->buildRouteUrl($routeParams);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function buildRouteUrl(array $parameters): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute('records', $parameters);
        } catch (Exception) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array{name: string, value: string}>
     */
    private function flattenParameters(array $parameters, string $prefix = ''): array
    {
        $fields = [];
        foreach ($parameters as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . (string) $key . ']';
            if (is_array($value)) {
                $nestedParameters = [];
                foreach ($value as $nestedKey => $nestedValue) {
                    $nestedParameters[(string) $nestedKey] = $nestedValue;
                }
                $fields = array_merge($fields, $this->flattenParameters($nestedParameters, $name));
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $fields[] = [
                'name' => $name,
                'value' => (string) $value,
            ];
        }

        return $fields;
    }
}
