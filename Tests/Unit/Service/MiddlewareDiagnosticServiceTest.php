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
        self::assertSame([], $diagnosis['riskyMiddlewares']);
        self::assertNull($subject->getWarningMessage($this->createRequest()));
        self::assertStringContainsString('displayMode=list', $diagnosis['forceListViewUrl']);
    }

    #[Test]
    public function diagnoseWarnsWhenCustomMiddlewareRunsAfterPageContextInitialization(): void
    {
        $executionOrder = $this->defaultExecutionOrder();
        array_splice($executionOrder, 11, 0, 'vendor/example-html-rewriter');

        $subject = $this->createSubject($executionOrder);
        $diagnosis = $subject->diagnose($this->createRequest());

        self::assertTrue($diagnosis['hasRisk']);
        self::assertContains('vendor/example-html-rewriter', $diagnosis['riskyMiddlewares']);
        self::assertStringContainsString(
            'vendor/example-html-rewriter',
            implode(' ', $diagnosis['warnings']),
        );

        $details = $subject->getDetailedDiagnostics($this->createRequest());
        self::assertContains('vendor/example-html-rewriter', $details['riskyMiddlewares']);
    }

    #[Test]
    public function diagnoseIgnoresCustomMiddlewareBeforePageContextInitialization(): void
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
     */
    private function createResolvedStack(array $executionOrder): ArrayObject
    {
        $resolvedStack = [];
        foreach (array_reverse($executionOrder) as $middlewareIdentifier) {
            $resolvedStack[$middlewareIdentifier] = 'Tests\\Middleware\\' . md5($middlewareIdentifier);
        }

        return new ArrayObject($resolvedStack);
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
}
