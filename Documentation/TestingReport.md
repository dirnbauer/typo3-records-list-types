# Testing Report

> Run date: 2026-04-18
> Skill: typo3-testing
> Extension: records_list_types @ TYPO3 v14
> Supersedes the 2026-02-09 snapshot.

## Current state

- **Unit suite**: 90 tests / 158 assertions.
- **Functional suite**: 72 tests / 155 assertions (pdo_sqlite driver).
- **Architecture rules** (phpat): evaluated by PHPStan via
  `vendor/phpat/phpat/extension.neon` — events can't depend on
  services/controllers, services can't depend on controllers, view
  helpers are isolated, constants have no dependencies.
- **PHPStan**: level 9 + strict rules + phpat + saschaegerer/phpstan-typo3.
- **CI**: PHP 8.3 + 8.4 matrix, MySQL 8 service for functional tests,
  plus a dedicated `composer audit` job.

## Gaps identified

| # | Gap | Remediation |
|---|-----|-------------|
| 1 | No unified test-runner script. CI and local runs use ad-hoc commands. | Added `Build/Scripts/runTests.sh` with `-s` / `-p` flags covering unit, functional, architecture, phpstan, cgl, composer, ci. |
| 2 | `RecordGridDataProvider` has no direct tests. Exercised indirectly via the controller functional tests. | **Accepted**. The service is tightly coupled to TYPO3 Core's `BackendUtility::workspaceOL()` and the FAL pipeline; unit tests would be thin wrappers around Core mocks. |
| 3 | `RecordListController` (XClass) has no direct tests. | **Accepted**. It inherits from Core and its behaviour is validated end-to-end by the functional tests of the services it coordinates. |
| 4 | Mutation testing (Infection) not wired up. | **Deferred**. Worth revisiting once line coverage exceeds ~80%. |
| 5 | `Tests/Architecture/` was registered as a PHPUnit testsuite but the classes are phpat rules (`->rule()->shouldNotDependOn(...)`) — PHPUnit could not execute them and emitted `failOnWarning` errors. | Dropped the suite from `phpunit.xml.dist`. phpat continues to run under PHPStan. |
| 6 | `<coverage><report>` block in `phpunit.xml.dist` triggered "No code coverage driver available" warnings on local runs without xdebug/pcov. | Removed the block. CI still passes `--coverage-clover` via CLI. |

## Actions in this pass

- Added `Build/Scripts/runTests.sh` executable script. CI can keep its
  existing `vendor/bin/...` invocations; new contributors get a single
  documented entry point.
- Retired `ext_emconf.php` — it was triggering `Deprecations: 1` on four
  functional tests.

## Verification

```bash
Build/Scripts/runTests.sh -s unit          # 90 tests, 158 assertions
Build/Scripts/runTests.sh -s phpstan       # 0 errors (includes phpat rules)
Build/Scripts/runTests.sh -s cgl           # 0 diff
Build/Scripts/runTests.sh -s composer      # validate + audit clean
typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
# 72 tests, 155 assertions
```
