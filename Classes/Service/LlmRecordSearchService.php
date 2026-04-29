<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Doctrine\DBAL\ParameterType;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Optional adapter for nr-llm-backed record search.
 *
 * This service intentionally avoids compile-time references to nr-llm classes
 * so records_list_types can be installed without the optional dependency.
 */
final readonly class LlmRecordSearchService implements SingletonInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private RecordFilterConfigurationService $configurationService,
    ) {}

    /**
     * @param array<string, mixed> $filter
     * @return list<int>|null Null means "LLM unavailable / no-op"; list means apply uid constraint.
     */
    public function findMatchingUids(string $table, int $pageId, string $question, array $filter): ?array
    {
        $configurationIdentifier = $this->getConfigurationIdentifier($filter);
        $fields = is_array($filter['fields'] ?? null) ? $filter['fields'] : [];
        $fields = array_values(array_filter($fields, static fn(mixed $field): bool => is_string($field)));
        $candidateLimit = is_numeric($filter['candidateLimit'] ?? null) ? (int) $filter['candidateLimit'] : 80;
        $resultLimit = is_numeric($filter['resultLimit'] ?? null) ? (int) $filter['resultLimit'] : 25;

        if ($configurationIdentifier === '' || $fields === [] || trim($question) === '') {
            return null;
        }

        $llm = $this->getLlmService();
        if (!is_object($llm)) {
            return null;
        }

        try {
            if (method_exists($llm, 'hasAvailableProvider') && !$llm->hasAvailableProvider()) {
                return null;
            }

            $candidates = $this->fetchCandidates($table, $pageId, $fields, $candidateLimit);
            if ($candidates === []) {
                return [];
            }

            $messages = $this->buildMessages($table, $question, $candidates, $resultLimit);
            $configuration = $this->getLlmConfiguration($configurationIdentifier);
            if ($configuration === null || !method_exists($llm, 'chatWithConfiguration')) {
                return null;
            }
            $response = $llm->chatWithConfiguration($messages, $configuration);

            $content = $this->extractResponseContent($response);
            return $this->extractUids($content, array_column($candidates, 'uid'));
        } catch (Throwable) {
            return null;
        }
    }

    private function getLlmService(): ?object
    {
        $interface = 'Netresearch\\NrLlm\\Service\\LlmServiceManagerInterface';
        if (!interface_exists($interface)) {
            return null;
        }

        $container = GeneralUtility::getContainer();
        if (!$container->has($interface)) {
            return null;
        }

        $service = $container->get($interface);
        return is_object($service) ? $service : null;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function getConfigurationIdentifier(array $filter): string
    {
        foreach (['configurationIdentifier', 'configuration'] as $key) {
            $value = $filter[$key] ?? null;
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

        $configuration = $repository->findOneByIdentifier($identifier);
        return is_object($configuration) ? $configuration : null;
    }

    /**
     * @param list<string> $fields
     * @return list<array{uid: int, text: string}>
     */
    private function fetchCandidates(string $table, int $pageId, array $fields, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $workspaceId = GeneralUtility::makeInstance(Context::class)
            ->getPropertyFromAspect('workspace', 'id', 0);
        $workspaceId = is_numeric($workspaceId) ? (int) $workspaceId : 0;

        $selectFields = array_values(array_unique(['uid', 'pid', ...$fields]));
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        $rows = $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER),
                ),
            )
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        $candidates = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL($table, $row, -99, true);
            if (!is_array($row)) {
                continue;
            }
            $uid = $row['uid'] ?? null;
            if (!is_numeric($uid)) {
                continue;
            }

            $parts = [];
            foreach ($fields as $field) {
                if (!$this->configurationService->fieldExists($table, $field)) {
                    continue;
                }
                $value = $row[$field] ?? '';
                $text = is_scalar($value) ? trim(strip_tags((string) $value)) : '';
                if ($text === '') {
                    continue;
                }
                $label = $this->configurationService->getFieldLabel($table, $field);
                $parts[] = $label . ': ' . $text;
            }
            $text = trim(implode("\n", $parts));
            if ($text === '') {
                continue;
            }
            $candidates[] = [
                'uid' => (int) $uid,
                'text' => mb_substr($text, 0, 1800),
            ];
        }

        return $candidates;
    }

    /**
     * @param list<array{uid: int, text: string}> $candidates
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(string $table, string $question, array $candidates, int $resultLimit): array
    {
        $records = [];
        foreach ($candidates as $candidate) {
            $records[] = 'UID ' . $candidate['uid'] . "\n" . $candidate['text'];
        }

        return [
            [
                'role' => 'system',
                'content' => 'You filter TYPO3 backend records. Return only JSON with this shape: {"uids":[1,2,3]}. '
                    . 'Use only record UIDs from the provided records. Return at most ' . max(1, $resultLimit) . ' UIDs.',
            ],
            [
                'role' => 'user',
                'content' => "Table: {$table}\nQuestion: {$question}\n\nRecords:\n\n" . implode("\n\n---\n\n", $records),
            ],
        ];
    }

    private function extractResponseContent(mixed $response): string
    {
        if (is_object($response)) {
            $properties = get_object_vars($response);
            $content = $properties['content'] ?? null;
            if (is_scalar($content)) {
                return (string) $content;
            }
        }
        if (is_object($response) && method_exists($response, 'getText')) {
            $text = $response->getText();
            return is_scalar($text) ? (string) $text : '';
        }
        return '';
    }

    /**
     * @param list<int|string> $allowedUids
     * @return list<int>
     */
    private function extractUids(string $content, array $allowedUids): array
    {
        $allowed = array_map('intval', $allowedUids);
        $decoded = json_decode($content, true);
        $uids = [];
        if (is_array($decoded)) {
            $rawUids = is_array($decoded['uids'] ?? null) ? $decoded['uids'] : $decoded;
            foreach ($rawUids as $uid) {
                if (is_numeric($uid)) {
                    $uids[] = (int) $uid;
                }
            }
        }

        if ($uids === [] && preg_match_all('/\b\d+\b/', $content, $matches) > 0) {
            $uids = array_map('intval', $matches[0]);
        }

        $uids = array_values(array_unique($uids));
        return array_values(array_intersect($uids, $allowed));
    }
}
