<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

/**
 * Reads record-list filter state from query/body parameters and module data.
 */
final readonly class RecordFilterStateService
{
    public const string SHOW_PARAMETER = 'filters';
    public const string VALUES_PARAMETER = 'recordFilters';

    public function shouldShow(ServerRequestInterface $request): bool
    {
        $params = $this->getMergedParameters($request);

        if (array_key_exists(self::SHOW_PARAMETER, $params)) {
            return $this->isTruthy($params[self::SHOW_PARAMETER] ?? null);
        }

        if (isset($params[self::VALUES_PARAMETER]) && is_array($params[self::VALUES_PARAMETER])) {
            return true;
        }

        $moduleData = $request->getAttribute('moduleData');
        if ($moduleData instanceof ModuleData) {
            return $this->isTruthy($moduleData->get(self::SHOW_PARAMETER, false));
        }

        return false;
    }

    public function persistVisibilityPreferenceFromRequest(ServerRequestInterface $request): void
    {
        $params = $this->getMergedParameters($request);
        if (!array_key_exists(self::SHOW_PARAMETER, $params)) {
            return;
        }

        $moduleData = $request->getAttribute('moduleData');
        if (!$moduleData instanceof ModuleData) {
            return;
        }

        $moduleData->set(self::SHOW_PARAMETER, $this->isTruthy($params[self::SHOW_PARAMETER] ?? null));

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication) {
            $backendUser->pushModuleData($moduleData->getModuleIdentifier(), $moduleData->toArray());
        }
    }

    public function getSelectedTable(ServerRequestInterface $request): string
    {
        $params = $this->getMergedParameters($request);
        $table = $params['table'] ?? null;
        return is_scalar($table) ? trim((string) $table) : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getActiveValuesForTable(ServerRequestInterface $request, string $table): array
    {
        $params = $this->getMergedParameters($request);
        $allValues = $params[self::VALUES_PARAMETER] ?? [];
        if (!is_array($allValues)) {
            return [];
        }

        $tableValues = $allValues[$table] ?? [];
        return is_array($tableValues) ? ArrayUtility::stringKeyArray($tableValues) : [];
    }

    public function hasActiveValuesForTable(ServerRequestInterface $request, string $table): bool
    {
        foreach ($this->getActiveValuesForTable($request, $table) as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $nestedValue) {
                if (is_scalar($nestedValue) && trim((string) $nestedValue) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     * @return array<int, array<string, mixed>>
     */
    public function attachValues(array $filters, ServerRequestInterface $request, string $table): array
    {
        $values = $this->getActiveValuesForTable($request, $table);

        foreach ($filters as &$filter) {
            $id = is_string($filter['id'] ?? null) ? $filter['id'] : '';
            $value = $values[$id] ?? null;
            $filter['value'] = is_scalar($value) ? (string) $value : '';
            if (is_array($value)) {
                $from = $value['from'] ?? '';
                $to = $value['to'] ?? '';
                $filter['fromValue'] = is_scalar($from) ? (string) $from : '';
                $filter['toValue'] = is_scalar($to) ? (string) $to : '';
            } else {
                $filter['fromValue'] = '';
                $filter['toValue'] = '';
            }
            $options = is_array($filter['options'] ?? null) ? $this->normalizeOptions($filter['options']) : [];
            if ($options !== []) {
                $filter['selectedOption'] = $this->findSelectedOption($options, $filter['value']);
                if (is_scalar($filter['selectedOption']['value'] ?? null)) {
                    $filter['value'] = (string) $filter['selectedOption']['value'];
                }
            }
        }
        unset($filter);

        return $filters;
    }

    /**
     * @param array<mixed, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $normalized[] = ArrayUtility::stringKeyArray($option);
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private function findSelectedOption(array $options, string $value): array
    {
        $fallback = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionValue = $option['value'] ?? null;
            if ($optionValue === '') {
                $fallback = $option;
            }
            if (is_scalar($optionValue) && (string) $optionValue === $value) {
                return $option;
            }
            if (is_scalar($optionValue) && $this->optionValueContainsUid((string) $optionValue, $value)) {
                return $option;
            }
        }

        return $fallback;
    }

    private function optionValueContainsUid(string $optionValue, string $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        return in_array((string) (int) $value, explode(',', $optionValue), true);
    }

    private function isTruthy(mixed $value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        return !in_array((string) $value, ['0', 'false', 'off', ''], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMergedParameters(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];

        return ArrayUtility::stringKeyArray(array_replace_recursive($queryParams, $bodyParams));
    }
}
