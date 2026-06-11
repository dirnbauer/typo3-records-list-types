# Changelog

All notable changes to this project are documented in this file.

## 1.0.5 - 2026-06-11

### Fixed

- The Records module crashed with an `ArgumentCountError` because the XCLASS'd `RecordListController` gained a wider DI constructor while the compiled container still built the core service with the core constructor arguments. The core controller service is now replaced by a container alias. **v1.0.4 is broken — upgrade straight to this release.**

## 1.0.4 - 2026-06-11

### Fixed

- Custom view `templateRootPath`/`partialRootPath`/`layoutRootPath` from TSconfig now take precedence over the built-in paths. Previously a custom view's partial sharing a name with a built-in one (e.g. `TranslationStrip`, `RecordActions`) silently resolved to the built-in file.
- Hidden rows in the compact view used a surface darker than the page in dark mode; the visibility state bar used the TYPO3 warning *text* color, which renders near-white in dark mode.

### Changed

- Hidden records share one visual language across Grid, Compact, and Teaser: amber-tinted background plus a 3px amber state bar, with text kept at full opacity for WCAG 2.2 AA contrast.
- Untranslated translation slots render as recessed muted rows with solid hairlines instead of dashed borders; teaser translation rows form one attached panel per parent card.
- Low-contrast subtle-gray text (placeholders, badges, UIDs) bumped to muted gray; opacity fades on hidden/deleted rows replaced with muted colors; workspace markers use inset shadows so rows stay aligned.

## 1.0.3 - 2026-06-05

### Added

- Column selector ("Spalten anzeigen") and collapse control for the page translations sub-list in alternative view modes (Grid, Compact, Teaser, custom).

### Changed

- Reduced grid view ID pill contrast to meet WCAG 2.2 AA minimum.
- Split record view enrichment out of `RecordListController` for maintainability.

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
