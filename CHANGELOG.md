# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Changed

- Require TYPO3 14.3.6+ within v14 and refresh the lock file on PHP 8.3.
- Reuse Core table visibility, table ordering, header buttons and bulk actions through a small `AlternativeDatabaseRecordList` adapter; remove reflection and duplicate record-list initialization.
- Delegate copy, cut, delete and move requests to Core JavaScript APIs; remove the nonexistent clipboard AJAX endpoint and duplicate modal/request code.
- Replace stale maintenance reports and duplicated README content with the current RST manual and a reproducible local development guide.

### Fixed

- Alternative views enforce the Core page-access guard and table visibility rules, including explicitly requested tables, wildcard hiding and per-table overrides.
- Preserve scalar Page TSconfig options for Core table actions, including download and column-selector visibility.
- Fix keyboard reordering: stop duplicate grab/drop handling and initialize the drag context before calculating compatible positions.
- Preference tests authenticate a real backend user instead of skipping persistence checks.

### Removed

- Unused `GridViewQueryListener` and `GridViewRecordActionsListener` caches and the `RecordActionsViewHelper` that only read the unpopulated cache. Custom templates should use the documented record payload and `RecordActionDropdown` partial; Core query events remain supported.
- Unused `RecordGridDataProvider::getRecordsForTable()` and `getRecordCount()` query paths, including the obsolete `ctrl.searchFields` parser. Record queries run through Core `DatabaseRecordList`; row enrichment remains available through `buildRecordDataFromRow()`.
- Heuristic middleware warnings and their diagnostic service. They did not establish whether rendering failed; normal Core error handling and the view selector remain available.
- Coverage exclusions for nonexistent model files and removed helpers.

## 1.0.7 - 2026-07-25

### Fixed

- Record icons in the compact and grid views were hard-sized with `width`/`height` (12px and 14px), overriding TYPO3's `--icon-size` contract. Because `.icon`'s `line-height` still came from the core `icon-size-small` value, the artwork was squeezed inside a taller line box. Content-block icons are authored on a 16-unit viewBox, so 12px rendered them at 0.75× with sub-pixel strokes. Both views now size icons through `--icon-size` and render at the native 16px; row height is unchanged.
- Compact-view tree connectors were pinned at `left: 15px`, but `.compactview-row__icon` is a centred flex child of the 34px icon column, putting the icon's center at 17px. The connectors were 2px off the icon they were meant to meet. They now derive from a `--cv-tree-x` token.

### Changed

- Every icon size in `compact-view.css`, `grid-view.css` and `base.css` is now expressed as `--icon-size` instead of `width`/`height`, so `.icon`'s box and line box can no longer drift apart. Chrome glyphs (sort indicator, badges, toggles, row actions, sorting dropdown) keep their existing 12/14px sizes — only the mechanism changed.

## 1.0.6 - 2026-07-06

### Changed

- Refreshed the TYPO3 v14 extension icon and updated audited dependencies.

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
