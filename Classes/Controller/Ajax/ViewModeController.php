<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller\Ajax;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;

/**
 * ViewModeController - AJAX endpoint for persisting view mode preferences.
 *
 * Handles AJAX requests to store the user's view mode preference
 * in their backend user configuration.
 */
final class ViewModeController
{
    public function __construct(
        private readonly ViewModeResolver $viewModeResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Set the user's view mode preference.
     *
     * Expected POST body:
     * {
     *     "mode": "grid" | "list"
     * }
     *
     */
    public function setViewModeAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Parse request body
            $body = $request->getParsedBody();

            // Handle both JSON and form-encoded requests
            if (!is_array($body) || $body === []) {
                $content = $request->getBody()->getContents();
                $body = json_decode($content, true) ?? [];
            }

            /** @var array<string, mixed> $body */
            $mode = isset($body['mode']) && is_string($body['mode']) ? $body['mode'] : null;

            // Validate mode
            if ($mode === null || !$this->viewModeResolver->isValidMode($mode)) {
                $validModes = array_keys($this->viewModeResolver->getViewModes());
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid mode. Must be one of: ' . implode(', ', $validModes),
                ], 400);
            }

            // Store the preference
            $this->viewModeResolver->setUserPreference($mode);

            return new JsonResponse([
                'success' => true,
                'mode' => $mode,
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
            $pageId = (int) ($queryParams['pageId'] ?? 0);

            $currentMode = $this->viewModeResolver->getActiveViewMode($request, $pageId);
            $userPreference = $this->viewModeResolver->getUserPreference();
            $forcedMode = $this->viewModeResolver->getForcedViewMode();

            return new JsonResponse([
                'success' => true,
                'currentMode' => $currentMode,
                'userPreference' => $userPreference,
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
}
