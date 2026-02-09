<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Controller\Ajax;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\RecordsListTypes\Controller\Ajax\ViewModeController;
use Webconsulting\RecordsListTypes\Service\ViewModeResolver;

/**
 * Tests for the ViewModeController AJAX endpoints.
 *
 * Note: ViewModeResolver is declared final and uses GeneralUtility::makeInstance()
 * internally, so only error-handling paths can be unit tested. Happy-path tests
 * require functional testing with the full TYPO3 DI container.
 */
final class ViewModeControllerTest extends TestCase
{
    #[Test]
    public function setViewModeActionCatchesExceptionAndReturns500(): void
    {
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())->method('error');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')
            ->willThrowException(new \RuntimeException('Test error'));

        $controller = new ViewModeController(
            new ViewModeResolver(),
            $loggerMock,
        );

        $response = $controller->setViewModeAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsString('Failed to save', $body['error']);
    }

    #[Test]
    public function getViewModeActionCatchesExceptionAndReturns500(): void
    {
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())->method('error');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')
            ->willThrowException(new \RuntimeException('Request error'));

        $controller = new ViewModeController(
            new ViewModeResolver(),
            $loggerMock,
        );

        $response = $controller->getViewModeAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Failed to retrieve', $body['error']);
    }

    #[Test]
    public function controllerAcceptsRequiredDependencies(): void
    {
        $controller = new ViewModeController(
            new ViewModeResolver(),
            $this->createMock(LoggerInterface::class),
        );

        self::assertInstanceOf(ViewModeController::class, $controller);
    }
}
