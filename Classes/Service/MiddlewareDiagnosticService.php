<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * MiddlewareDiagnosticService - Detects potential middleware interference.
 *
 * This service analyzes the middleware stack and request attributes to identify
 * configurations that might interfere with the Grid View rendering.
 *
 * Detection strategies:
 * 1. Static Analysis: Inspects middleware stack for non-core entries
 * 2. Runtime Check: Verifies required request attributes exist
 */
final class MiddlewareDiagnosticService implements SingletonInterface
{
    /** Required request attributes for proper Grid View functioning. */
    private const REQUIRED_ATTRIBUTES = [
        'normalizedParams',
        'applicationType',
    ];

    /** Core middleware packages that are known to be safe. */
    private const CORE_PACKAGES = [
        'typo3/cms-core',
        'typo3/cms-backend',
        'typo3/cms-frontend',
        'typo3/cms-install',
    ];

    /** @var array<string, bool> Cache for diagnostic results */
    private array $diagnosticCache = [];

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly DependencyOrderingService $dependencyOrderingService,
    ) {}

    /**
     * Check if there are potential middleware issues.
     *
     * @param ServerRequestInterface $request The current request
     * @return array{hasRisk: bool, warnings: string[], forceListViewUrl: string}
     */
    public function diagnose(ServerRequestInterface $request): array
    {
        $warnings = [];

        // 1. Check required request attributes (runtime check)
        $missingAttributes = $this->checkRequiredAttributes($request);
        if (!empty($missingAttributes)) {
            $warnings[] = sprintf(
                'Missing required request attributes: %s. This may indicate middleware interference.',
                implode(', ', $missingAttributes),
            );
        }

        // 2. Check for non-core middlewares (static analysis)
        $nonCoreMiddlewares = $this->detectNonCoreMiddlewares();
        if (!empty($nonCoreMiddlewares)) {
            // Only warn if there are many custom middlewares
            if (count($nonCoreMiddlewares) > 5) {
                $warnings[] = sprintf(
                    'Detected %d custom middleware(s) which may affect rendering.',
                    count($nonCoreMiddlewares),
                );
            }
        }

        // Build the force list view URL
        $queryParams = $request->getQueryParams();
        $queryParams['displayMode'] = 'list';
        $uri = $request->getUri()->withQuery(http_build_query($queryParams));

        return [
            'hasRisk' => !empty($warnings),
            'warnings' => $warnings,
            'forceListViewUrl' => (string) $uri,
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
     * Detect non-core middlewares in the backend stack.
     *
     * @return string[] List of non-core middleware class names
     */
    private function detectNonCoreMiddlewares(): array
    {
        if (isset($this->diagnosticCache['nonCoreMiddlewares'])) {
            return $this->diagnosticCache['nonCoreMiddlewares'];
        }

        $nonCore = [];

        try {
            // Get all active packages
            $activePackages = $this->packageManager->getActivePackages();

            foreach ($activePackages as $package) {
                $packageKey = $package->getPackageKey();
                $composerName = $package->getValueFromComposerManifest('name');

                // Skip core packages
                if (in_array($composerName, self::CORE_PACKAGES, true)) {
                    continue;
                }

                // Check if package has backend middlewares
                $middlewareFile = $package->getPackagePath() . 'Configuration/RequestMiddlewares.php';
                if (file_exists($middlewareFile)) {
                    $middlewares = include $middlewareFile;
                    if (isset($middlewares['backend']) && is_array($middlewares['backend'])) {
                        foreach (array_keys($middlewares['backend']) as $middlewareName) {
                            $nonCore[] = $packageKey . '/' . $middlewareName;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Silently fail - diagnostic is not critical
        }

        $this->diagnosticCache['nonCoreMiddlewares'] = $nonCore;

        return $nonCore;
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

        return !empty($criticalMissing);
    }

    /**
     * Get detailed diagnostic information for debugging.
     *
     * @param ServerRequestInterface $request The current request
     * @return array<string, mixed> Detailed diagnostic data
     */
    public function getDetailedDiagnostics(ServerRequestInterface $request): array
    {
        return [
            'requestAttributes' => array_keys($request->getAttributes()),
            'missingRequiredAttributes' => $this->checkRequiredAttributes($request),
            'nonCoreMiddlewares' => $this->detectNonCoreMiddlewares(),
            'diagnosis' => $this->diagnose($request),
        ];
    }

    /**
     * Clear the diagnostic cache.
     */
    public function clearCache(): void
    {
        $this->diagnosticCache = [];
    }
}
