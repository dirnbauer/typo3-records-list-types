<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Unit\Service;

use ArrayObject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use Webconsulting\RecordsListTypes\Service\MiddlewareDiagnosticService;

final class MiddlewareDiagnosticServiceTest extends TestCase
{
    #[Test]
    public function diagnoseDoesNotWarnForCoreBackendMiddlewareOrder(): void
    {
        $subject = $this->createSubject($this->defaultExecutionOrder());

        $diagnosis = $subject->diagnose($this->createRequest());

        self::assertFalse($diagnosis['hasRisk']);
        self::assertSame([], $diagnosis['warnings']);
        self::assertNull($subject->getWarningMessage($this->createRequest()));
        self::assertStringContainsString('displayMode=list', $diagnosis['forceListViewUrl']);
    }

    #[Test]
    public function diagnoseDoesNotWarnForThirdPartyMiddlewareAfterPageContextInitialization(): void
    {
        $executionOrder = $this->defaultExecutionOrder();
        array_splice($executionOrder, 11, 0, 'webconsulting/visual-editor-enhancements/backend-assets');

        $subject = $this->createSubject($executionOrder);
        $diagnosis = $subject->diagnose($this->createRequest());

        self::assertFalse($diagnosis['hasRisk']);
        self::assertSame([], $diagnosis['warnings']);
        self::assertContains(
            'webconsulting/visual-editor-enhancements/backend-assets',
            $diagnosis['executionOrder'],
        );
        self::assertNull($subject->getWarningMessage($this->createRequest()));

        $details = $subject->getDetailedDiagnostics($this->createRequest());
        self::assertIsArray($details['resolvedBackendExecutionOrder']);
        self::assertContains(
            'webconsulting/visual-editor-enhancements/backend-assets',
            $details['resolvedBackendExecutionOrder'],
        );
    }

    #[Test]
    public function diagnoseDoesNotWarnWhenResponsePropagationWrapsPageContext(): void
    {
        $subject = $this->createSubject($this->runtimeExecutionOrder());

        $diagnosis = $subject->diagnose($this->createRequest());

        self::assertFalse($diagnosis['hasRisk']);
        self::assertSame([], $diagnosis['warnings']);
    }

    #[Test]
    public function diagnoseIgnoresThirdPartyMiddlewareBeforePageContextInitialization(): void
    {
        $executionOrder = $this->defaultExecutionOrder();
        array_splice($executionOrder, 9, 0, 'vendor/example-request-logger');

        $subject = $this->createSubject($executionOrder);

        self::assertFalse($subject->diagnose($this->createRequest())['hasRisk']);
    }

    #[Test]
    public function diagnoseWarnsWhenRequiredCoreMiddlewareIsMissing(): void
    {
        $executionOrder = array_values(array_filter(
            $this->defaultExecutionOrder(),
            static fn(string $middleware): bool => $middleware !== 'typo3/cms-backend/page-context',
        ));

        $subject = $this->createSubject($executionOrder);
        $diagnosis = $subject->diagnose($this->createRequest());

        self::assertTrue($diagnosis['hasRisk']);
        self::assertStringContainsString(
            'typo3/cms-backend/page-context',
            implode(' ', $diagnosis['warnings']),
        );
    }

    #[Test]
    public function shouldForceListViewReturnsTrueWhenRequiredAttributesAreMissing(): void
    {
        $subject = $this->createSubject($this->defaultExecutionOrder());
        $request = $this->createRequest(attributes: [
            'normalizedParams' => null,
            'applicationType' => 1,
        ]);

        self::assertTrue($subject->shouldForceListView($request));
    }

    #[Test]
    public function getWarningMessageDescribesMissingRequestContext(): void
    {
        $subject = $this->createSubject($this->defaultExecutionOrder());
        $request = $this->createRequest(attributes: [
            'normalizedParams' => null,
        ]);

        self::assertSame(
            'System Warning: Required backend request context for Grid View is incomplete. '
                . 'If the display is corrupted, switch to List View and verify the backend '
                . 'middleware stack in System > Configuration.',
            $subject->getWarningMessage($request),
        );
    }

    /**
     * @param string[] $executionOrder
     */
    private function createSubject(array $executionOrder): MiddlewareDiagnosticService
    {
        return new MiddlewareDiagnosticService($this->createResolvedStack($executionOrder));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $queryParams
     */
    private function createRequest(array $attributes = [], array $queryParams = ['id' => 42, 'displayMode' => 'grid']): ServerRequest
    {
        $request = (new ServerRequest(new Uri('https://example.test/typo3/module/web/list'), 'GET'))
            ->withQueryParams($queryParams);

        $baseAttributes = [
            'normalizedParams' => 'normalized',
            'applicationType' => 1,
        ];

        foreach (array_merge($baseAttributes, $attributes) as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    /**
     * @param string[] $executionOrder
     * @return ArrayObject<string, string>
     */
    private function createResolvedStack(array $executionOrder): ArrayObject
    {
        $resolvedStack = [];
        foreach (array_reverse($executionOrder) as $middlewareIdentifier) {
            $resolvedStack[$middlewareIdentifier] = $this->middlewareClassName($middlewareIdentifier);
        }

        return new ArrayObject($resolvedStack);
    }

    private function middlewareClassName(string $middlewareIdentifier): string
    {
        return 'Tests\\Middleware\\' . md5($middlewareIdentifier);
    }

    /**
     * @return string[]
     */
    private function defaultExecutionOrder(): array
    {
        return [
            'typo3/cms-core/normalized-params-attribute',
            'typo3/cms-backend/locked-backend',
            'typo3/cms-backend/https-redirector',
            'typo3/cms-backend/csp-report',
            'typo3/cms-backend/backend-routing',
            'typo3/cms-core/request-token-middleware',
            'typo3/cms-backend/authentication',
            'typo3/cms-backend/backend-module-validator',
            'typo3/cms-backend/sudo-mode-interceptor',
            'typo3/cms-backend/site-resolver',
            'typo3/cms-backend/page-context',
            'typo3/cms-backend/csp-headers',
            'typo3/cms-backend/js-label-importmap-resolver',
            'typo3/cms-backend/response-headers',
            'typo3/cms-core/response-propagation',
        ];
    }

    /**
     * Mirrors TYPO3's resolved backend stack in this project where response
     * propagation wraps page-context. This is a valid PSR-15 stack shape:
     * response propagation calls the inner handler, and page-context still sets
     * the attribute before the controller is reached.
     *
     * @return string[]
     */
    private function runtimeExecutionOrder(): array
    {
        return [
            'typo3/cms-core/normalized-params-attribute',
            'typo3/cms-backend/locked-backend',
            'typo3/cms-backend/https-redirector',
            'typo3/cms-backend/csp-report',
            'webconsulting/workos-auth/backend',
            'wapplersystems/multisite-token-authenticator',
            'typo3/cms-backend/backend-routing',
            'typo3/cms-core/request-token-middleware',
            'typo3/cms-reactions/resolver',
            'typo3/cms-backend/authentication',
            'hn-mcp-server/backend-user-configuration',
            'typo3/cms-backend/backend-module-validator',
            'hn-mcp-server/routes',
            'typo3/cms-backend/sudo-mode-interceptor',
            'typo3/cms-backend/site-resolver',
            'typo3/cms-backend/csp-headers',
            'typo3/cms-backend/js-label-importmap-resolver',
            'typo3/cms-backend/response-headers',
            'webconsulting/visual-editor-enhancements/backend-assets',
            'typo3/cms-core/response-propagation',
            'typo3/cms-backend/page-context',
        ];
    }
}
