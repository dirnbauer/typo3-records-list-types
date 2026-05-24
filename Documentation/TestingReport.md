# Testing Report

> Run date: 2026-05-16 (round 3)
> Skill: typo3-testing
> Extension: records_list_types @ TYPO3 v14

Supersedes the round-2 snapshot. The v14-only cleanup raises PHPStan to
`level: max` and keeps unit, functional, CGL, composer, and aggregate CI
checks green.

## Current state

- **Unit**: 120 tests / 326 assertions.
- **Functional**: 72 tests / 155 assertions (pdo_sqlite driver).
- **Architecture**: phpat rules evaluated under PHPStan
  (`vendor/phpat/phpat/extension.neon` included in `phpstan.neon`).
- **PHPStan**: level max + strict rules + phpat +
  `saschaegerer/phpstan-typo3:^3.0`.
- **CI**: PHP 8.3 + 8.4 matrix, MySQL 8 service for functional tests,
  dedicated `composer audit` job.
- **Test runner**: `Build/Scripts/runTests.sh` (unit / functional /
  architecture alias / phpstan / cgl / composer / ci).

## Gap checklist

| Checkpoint | Status | Note |
|------------|--------|------|
| `Build/Scripts/runTests.sh` exists + executable | Pass | Added round 1. |
| phpat architecture rules | Pass | `Tests/Architecture/ArchitectureTest.php` — 5 rules. |
| PHPStan level max | Pass | `phpstan.neon` uses `level: max`. |
| runTests.sh supports `-s` and `-p` | Pass | Both flags wired. |
| Captainhook pre-commit hooks | Not set up | Git hooks documented as a follow-up; `.git/hooks` not committed. |
| Mutation testing (Infection) | Not set up | Deferred — more valuable once coverage reaches ~80%. |
| E2E (Playwright) | Not set up | Deferred — a meaningful E2E suite needs a seeded TYPO3 v14 backend and is out of scope here. |

## Coverage blind spots

| Class | Why uncovered | Decision |
|-------|---------------|----------|
| `RecordGridDataProvider` | Tightly coupled to `BackendUtility::workspaceOL()`, FAL pipeline, and real TCA. Meaningful tests require a full functional bootstrap. | Accepted. Controller functional tests exercise it indirectly. |
| `RecordListController` | XClasses Core's controller — initialisation relies on the Core DI container, route attributes, and DocHeader lifecycle. | Accepted. Behaviour is observable via integration in a live TYPO3 install. |
| `Classes/ViewHelpers/RecordActionsViewHelper` | Extends `AbstractViewHelper`; needs Fluid bootstrap. | Accepted. |
| `Classes/Html/BackendFragmentSanitizerBuilder` | Subclasses `DefaultSanitizerBuilder`; tests need the Core `Behavior` scaffolding. | Accepted. Verified indirectly through the sanitize-html rendering in functional tests. |
| `MiddlewareDiagnosticService` | Reads `$GLOBALS` + `PackageManager`; would need fake packages under a controlled temp directory. | Accepted. |

## Actions in this pass

- Added typed TSconfig/request array boundary helpers so PHPStan max can
  validate the v14 workspace-aware code without a baseline.
- Added TYPO3 14.3 classic-mode compatibility metadata to `composer.json`,
  removing the functional-suite deprecation.
- Verified the workspace-aware search/filter path after active filters and
  module search terms were moved behind `BackendUtility::workspaceOL()` for
  alternative view modes.

## Verification

```bash
Build/Scripts/runTests.sh -s unit          # 120 tests, 326 assertions
Build/Scripts/runTests.sh -s phpstan       # 0 errors (includes phpat rules)
Build/Scripts/runTests.sh -s cgl           # 0 diff
Build/Scripts/runTests.sh -s composer      # validate + audit clean
typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
# 72 tests, 155 assertions
Build/Scripts/runTests.sh -s ci            # composer + cgl + phpstan + unit
```
