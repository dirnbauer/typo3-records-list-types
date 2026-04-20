<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use ArrayObject;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * MiddlewareDiagnosticService - Detects potential middleware interference.
 *
 * This service analyzes the middleware stack and request attributes to identify
 * configurations that might interfere with alternative view mode rendering.
 *
 * Detection strategies:
 * 1. Stack Analysis: Inspects TYPO3's resolved backend middleware execution order
 *    for custom middlewares in rendering-critical positions
 * 2. Runtime Check: Verifies required request attributes exist
 */
final class MiddlewareDiagnosticService implements SingletonInterface
{
    /** Required request attributes for proper Grid View functioning. */
    private const array REQUIRED_ATTRIBUTES = [
        'normalizedParams',
        'applicationType',
    ];

    /**
     * Middleware positions that define the backend rendering phase.
     *
     * Custom middlewares executed after page context initialization can still
     * mutate the final backend HTML and therefore affect Grid View rendering.
     */
    private const string PAGE_CONTEXT_MIDDLEWARE = 'typo3/cms-backend/page-context';
    private const string RESPONSE_PROPAGATION_MIDDLEWARE = 'typo3/cms-core/response-propagation';
    private const string NORMALIZED_PARAMS_MIDDLEWARE = 'typo3/cms-core/normalized-params-attribute';

    private const array REQUIRED_CORE_MIDDLEWARES = [
        self::NORMALIZED_PARAMS_MIDDLEWARE,
        self::PAGE_CONTEXT_MIDDLEWARE,
        self::RESPONSE_PROPAGATION_MIDDLEWARE,
    ];

    private const string TYPO3_MIDDLEWARE_PREFIX = 'typo3/';

    /** @var array{
     *     missingCoreMiddlewares: string[],
     *     invalidOrder: bool,
     *     riskyMiddlewares: string[],
     *     executionOrder: string[]
     * }|null
     */
    private ?array $stackAnalysisCache = null;

    /**
     * @param ArrayObject<string, string> $backendMiddlewares
     */
    public function __construct(
        #[Autowire(service: 'backend.middlewares')]
        private readonly ArrayObject $backendMiddlewares,
    ) {}

    /**
     * Check if there are potential middleware issues.
     *
     * @param ServerRequestInterface $request The current request
     * @return array{
     *     hasRisk: bool,
     *     warnings: string[],
     *     forceListViewUrl: string,
     *     riskyMiddlewares: string[],
     *     executionOrder: string[]
     * }
     */
    public function diagnose(ServerRequestInterface $request): array
    {
        $warnings = [];

        // 1. Check required request attributes (runtime check)
        $missingAttributes = $this->checkRequiredAttributes($request);
        if ($missingAttributes !== []) {
            $warnings[] = sprintf(
                'Missing required request attributes: %s. This may indicate middleware interference.',
                implode(', ', $missingAttributes),
            );
        }

        // 2. Check for custom middlewares in rendering-critical positions
        $stackAnalysis = $this->analyzeBackendMiddlewareStack();
        if ($stackAnalysis['missingCoreMiddlewares'] !== []) {
            $warnings[] = sprintf(
                'Required backend middleware(s) missing from resolved stack: %s.',
                implode(', ', $stackAnalysis['missingCoreMiddlewares']),
            );
        }
        if ($stackAnalysis['invalidOrder']) {
            $warnings[] = 'Resolved backend middleware order is invalid: page context must run before response propagation.';
        }
        if ($stackAnalysis['riskyMiddlewares'] !== []) {
            $warnings[] = sprintf(
                'Custom backend middleware(s) run in the rendering phase after page context initialization: %s.',
                implode(', ', $stackAnalysis['riskyMiddlewares']),
            );
        }

        // Build the force list view URL
        $queryParams = $request->getQueryParams();
        $queryParams['displayMode'] = 'list';
        $uri = $request->getUri()->withQuery(http_build_query($queryParams));

        return [
            'hasRisk' => $warnings !== [],
            'warnings' => $warnings,
            'forceListViewUrl' => (string) $uri,
            'riskyMiddlewares' => $stackAnalysis['riskyMiddlewares'],
            'executionOrder' => $stackAnalysis['executionOrder'],
        ];
    }

    /**
     * Check if all required request attributes are present.
     *
     * @param ServerRequestInterface $request The current request
     * @return string[] List of missing attribute names
     */
    private function checkRequiredAttributes(ServerRequestInterface $request): array
    {
        $missing = [];

        foreach (self::REQUIRED_ATTRIBUTES as $attribute) {
            if ($request->getAttribute($attribute) === null) {
                $missing[] = $attribute;
            }
        }

        return $missing;
    }

    /**
     * Analyze the resolved TYPO3 backend middleware execution order.
     *
     * @return array{
     *     missingCoreMiddlewares: string[],
     *     invalidOrder: bool,
     *     riskyMiddlewares: string[],
     *     executionOrder: string[]
     * }
     */
    private function analyzeBackendMiddlewareStack(): array
    {
        if ($this->stackAnalysisCache !== null) {
            return $this->stackAnalysisCache;
        }

        /** @var array<string, string> $resolvedStack */
        $resolvedStack = iterator_to_array($this->backendMiddlewares);
        $executionOrder = array_values(array_reverse(array_keys($resolvedStack)));

        $missingCoreMiddlewares = array_values(array_filter(
            self::REQUIRED_CORE_MIDDLEWARES,
            static fn(string $middleware): bool => !in_array($middleware, $executionOrder, true),
        ));

        $pageContextIndex = array_search(self::PAGE_CONTEXT_MIDDLEWARE, $executionOrder, true);
        $responsePropagationIndex = array_search(self::RESPONSE_PROPAGATION_MIDDLEWARE, $executionOrder, true);
        $invalidOrder = is_int($pageContextIndex)
            && is_int($responsePropagationIndex)
            && $pageContextIndex > $responsePropagationIndex;

        $riskyMiddlewares = [];
        if (is_int($pageContextIndex) && !$invalidOrder) {
            foreach ($executionOrder as $index => $middlewareIdentifier) {
                if ($index <= $pageContextIndex || !$this->isCustomMiddlewareIdentifier($middlewareIdentifier)) {
                    continue;
                }
                $riskyMiddlewares[] = $middlewareIdentifier;
            }
        }

        /** @var array{
         *     missingCoreMiddlewares: string[],
         *     invalidOrder: bool,
         *     riskyMiddlewares: string[],
         *     executionOrder: string[]
         * } $analysis
         */
        $analysis = [
            'missingCoreMiddlewares' => $missingCoreMiddlewares,
            'invalidOrder' => $invalidOrder,
            'riskyMiddlewares' => $riskyMiddlewares,
            'executionOrder' => $executionOrder,
        ];
        $this->stackAnalysisCache = $analysis;

        return $this->stackAnalysisCache;
    }

    /**
     * Get a user-friendly warning message.
     *
     * @param ServerRequestInterface $request The current request
     * @return string|null Warning message or null if no issues
     */
    public function getWarningMessage(ServerRequestInterface $request): ?string
    {
        $diagnosis = $this->diagnose($request);

        if (!$diagnosis['hasRisk']) {
            return null;
        }

        return 'System Warning: A custom middleware configuration has been detected that may '
            . 'interfere with the Grid View visualization. If the display is corrupted, please '
            . 'verify middleware stack configuration in System > Configuration.';
    }

    /**
     * Check if the Grid View should be forcibly disabled due to detected issues.
     *
     * This is a more aggressive check that only triggers for serious problems.
     *
     * @param ServerRequestInterface $request The current request
     * @return bool True if Grid View should be disabled
     */
    public function shouldForceListView(ServerRequestInterface $request): bool
    {
        $missingAttributes = $this->checkRequiredAttributes($request);

        // Force list view if critical attributes are missing
        $criticalMissing = array_intersect(
            $missingAttributes,
            ['normalizedParams', 'applicationType'],
        );

        return $criticalMissing !== [];
    }

    /**
     * Get detailed diagnostic information for debugging.
     *
     * @param ServerRequestInterface $request The current request
     * @return array<string, mixed> Detailed diagnostic data
     */
    public function getDetailedDiagnostics(ServerRequestInterface $request): array
    {
        $stackAnalysis = $this->analyzeBackendMiddlewareStack();

        return [
            'requestAttributes' => array_keys($request->getAttributes()),
            'missingRequiredAttributes' => $this->checkRequiredAttributes($request),
            'resolvedBackendExecutionOrder' => $stackAnalysis['executionOrder'],
            'missingCoreMiddlewares' => $stackAnalysis['missingCoreMiddlewares'],
            'riskyMiddlewares' => $stackAnalysis['riskyMiddlewares'],
            'diagnosis' => $this->diagnose($request),
        ];
    }

    /**
     * Clear the diagnostic cache.
     */
    public function clearCache(): void
    {
        $this->stackAnalysisCache = null;
    }

    private function isCustomMiddlewareIdentifier(string $middlewareIdentifier): bool
    {
        return !str_starts_with($middlewareIdentifier, self::TYPO3_MIDDLEWARE_PREFIX);
    }
}
