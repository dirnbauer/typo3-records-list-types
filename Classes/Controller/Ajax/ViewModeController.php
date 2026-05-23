<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller\Ajax;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

/**
 * ViewModeController - AJAX endpoint for persisting view mode preferences.
 *
 * Handles AJAX requests to store the user's view mode preference
 * in their backend user configuration.
 */
final readonly class ViewModeController
{
    public function __construct(
        private ViewModeResolver $viewModeResolver,
        private LoggerInterface $logger,
    ) {}

    /**
     * Set the user's view mode preference.
     *
     * Expected POST body:
     * {
     *     "mode": "grid" | "list",
     *     "pageId": 123,
     *     "table": "tt_content"
     * }
     *
     */
    public function setViewModeAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = $this->getBodyParameters($request);
            $mode = ArrayUtility::stringValue($body['mode'] ?? null);
            $pageId = ArrayUtility::intValue($body['pageId'] ?? null);
            $tableName = ArrayUtility::stringValue($body['table'] ?? null);

            // Validate mode
            if ($mode === '' || !$this->viewModeResolver->isValidMode($mode, $pageId)) {
                $validModes = array_keys($this->viewModeResolver->getViewModes($pageId));
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid mode. Must be one of: ' . implode(', ', $validModes),
                ], 400);
            }

            // Store the preference
            $this->viewModeResolver->setUserPreference($mode, $pageId, $tableName);

            return new JsonResponse([
                'success' => true,
                'mode' => $mode,
                'table' => $tableName,
                'message' => 'View mode preference saved.',
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save view mode preference', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to save preference. Please try again.',
            ], 500);
        }
    }

    /**
     * Get the user's current view mode preference.
     *
     */
    public function getViewModeAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $queryParams = $request->getQueryParams();
            $pageId = ArrayUtility::intValue($queryParams['pageId'] ?? null);
            $tableName = ArrayUtility::stringValue($queryParams['table'] ?? null);

            $currentMode = $this->viewModeResolver->getActiveViewMode($request, $pageId, $tableName);
            $userPreference = $this->viewModeResolver->getUserPreference($tableName);
            $forcedMode = $this->viewModeResolver->getForcedViewMode();

            return new JsonResponse([
                'success' => true,
                'currentMode' => $currentMode,
                'userPreference' => $userPreference,
                'table' => $tableName,
                'forcedMode' => $forcedMode,
                'isForced' => $forcedMode !== null,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get view mode preference', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to retrieve preference. Please try again.',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getBodyParameters(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            return ArrayUtility::stringKeyArray($body);
        }

        $decodedBody = json_decode($request->getBody()->getContents(), true);
        return is_array($decodedBody) ? ArrayUtility::stringKeyArray($decodedBody) : [];
    }
}
