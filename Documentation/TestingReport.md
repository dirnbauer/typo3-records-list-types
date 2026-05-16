# Testing Report

> Run date: 2026-04-18 (round 2)
> Skill: typo3-testing
> Extension: records_list_types @ TYPO3 v14

Supersedes the round-1 snapshot. Suite size and coverage are unchanged
since round 1 (no regressions after the workspace consolidation and
Rector modernization).

## Current state

- **Unit**: 90 tests / 158 assertions.
- **Functional**: 72 tests / 155 assertions (pdo_sqlite driver).
- **Architecture**: phpat rules evaluated under PHPStan
  (`vendor/phpat/phpat/extension.neon` included in `phpstan.neon`).
- **PHPStan**: level 9 + strict rules + phpat +
  `saschaegerer/phpstan-typo3:^3.0`. Level 10 rejected this iteration
  (131 generic-array findings).
- **CI**: PHP 8.3 + 8.4 matrix, MySQL 8 service for functional tests,
  dedicated `composer audit` job.
- **Test runner**: `Build/Scripts/runTests.sh` (unit / functional /
  architecture alias / phpstan / cgl / composer / ci).

## Gap checklist

| Checkpoint | Status | Note |
|------------|--------|------|
| `Build/Scripts/runTests.sh` exists + executable | Pass | Added round 1. |
| phpat architecture rules | Pass | `Tests/Architecture/ArchitectureTest.php` — 5 rules. |
| PHPStan level ≥ 9 | Pass | Level 9. Level 10 is deferred. |
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

- No suite additions required — the existing tests continue to cover
  the services that matter for the v14 / workspace surface.
- Verified that the Rector modernization (readonly classes, arrow-fn
  return types) did not break any test — `Build/Scripts/runTests.sh -s unit`
  and the functional suite remain green on PHP 8.3.30.

## Verification

```bash
Build/Scripts/runTests.sh -s unit          # 90 tests, 158 assertions
Build/Scripts/runTests.sh -s phpstan       # 0 errors (includes phpat rules)
Build/Scripts/runTests.sh -s cgl           # 0 diff
Build/Scripts/runTests.sh -s composer      # validate + audit clean
typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
# 72 tests, 155 assertions
```
