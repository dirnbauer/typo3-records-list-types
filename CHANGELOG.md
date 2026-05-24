# Changelog

All notable changes to this project are documented in this file.

## 1.0.0 - 2026-05-24

First stable release for TYPO3 v14.3 LTS.

### Added

- Alternative Records module view modes: Grid, Compact, Teaser, and custom TSconfig-driven views.
- Workspace-aware record overlays, filters, sorting, pagination, language indicators, and record actions.
- PHP 8.3, 8.4, and 8.5 CI coverage for unit and functional tests.
- PHPUnit 12 lock with a PHPUnit-latest compatibility lane for PHPUnit 13.
- Clover and HTML coverage reports on the PHP 8.5 CI jobs.

### Changed

- Updated development tooling to PHPStan 2.1.55, PHP-CS-Fixer 3.95.2, PHPUnit 12.5.26, and TYPO3 14.3.1.
- Pinned PHPStan analysis to the PHP 8.3 lower bound while testing newer PHP runtimes in CI.

### Security

- Backend fragment HTML is sanitized with the dedicated `records-list-types-backend-fragments` sanitizer preset.
- PHPUnit now fails on notices, PHPUnit notices, deprecations, risky tests, and warnings.
