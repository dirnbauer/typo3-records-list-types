# Testing Report

> Run date: 2026-05-24 (round 4)
> Skill: typo3-testing
> Extension: records_list_types @ TYPO3 v14

Supersedes the round-3 snapshot. The toolchain now runs on the TYPO3
14.3.1 lock set with PHPUnit 12, PHPStan 2.1.55, PHP-CS-Fixer 3.95.2,
and CI coverage reporting on the PHP 8.5 jobs.

## Current state

- **Unit**: 132 tests / 340 assertions.
- **Functional**: 79 tests / 185 assertions (pdo_sqlite driver, 1 skipped).
- **Architecture**: phpat rules evaluated under PHPStan
  (`vendor/phpat/phpat/extension.neon` included in `phpstan.neon`).
- **PHPStan**: level max + strict rules + phpat +
  `saschaegerer/phpstan-typo3:^3.0`, analysed against the PHP 8.3
  lower bound.
- **CI**: PHP 8.3 + 8.4 + 8.5 matrix, MySQL 8 service for functional
  tests, a PHPUnit-latest PHP 8.5 compatibility lane, dedicated
  `composer audit` job, and PHP 8.5 coverage artifacts.
- **Test runner**: `Build/Scripts/runTests.sh` (unit / functional /
  unit-coverage / functional-coverage / architecture alias / phpstan /
  cgl / composer / ci).

## Gap checklist

| Checkpoint | Status | Note |
|------------|--------|------|
| `Build/Scripts/runTests.sh` exists + executable | Pass | Added round 1. |
| phpat architecture rules | Pass | `Tests/Architecture/ArchitectureTest.php` — 5 rules. |
| PHPStan level max | Pass | `phpstan.neon` uses `level: max`. |
| runTests.sh supports `-s` and `-p` | Pass | Both flags wired; PHP 8.5 is documented for matrix use. |
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

- Updated dev tooling to PHPUnit 12.5.26, PHPStan 2.1.55,
  PHP-CS-Fixer 3.95.2, and `typo3/testing-framework:^9.5`.
- Pinned PHPStan's analysed PHP version to 8.3 so the PHP 8.5 CI runtime
  cannot mask lower-bound compatibility regressions.
- Extended CI unit and functional matrices to PHP 8.5.
- Added an unlocked PHPUnit-latest lane on PHP 8.5 so the Composer
  constraint allowing PHPUnit 13 is continuously verified.
- Added `unit-coverage` and `functional-coverage` runner suites with
  Clover, HTML, and text coverage output.
- Added an explicit coverage-driver guard for local coverage runs.
- Replaced PHPUnit 12 mock objects without expectations with stubs.

## Verification

```bash
Build/Scripts/runTests.sh -s unit          # 132 tests, 340 assertions
Build/Scripts/runTests.sh -s phpstan       # 0 errors (includes phpat rules)
Build/Scripts/runTests.sh -s cgl           # 0 diff
Build/Scripts/runTests.sh -s composer      # validate + audit clean
typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
# 79 tests, 185 assertions, 1 skipped
Build/Scripts/runTests.sh -s ci            # composer + cgl + phpstan + unit
```
