# TYPO3 Conformance Report

> Run date: 2026-04-18 (round 2)
> Skill: typo3-conformance
> Extension: records_list_types @ TYPO3 v14

Supersedes the round-1 snapshot (2026-04-18 morning). Test runs,
workspace API consolidation and the Rector modernization pass have all
landed since.

## Overall Score

| Category | Score | Weight | Notes |
|----------|------:|-------:|-------|
| Architecture | 18 | 20 | Services.yaml with autowire+autoconfigure, PSR-14 events via `#[AsEventListener]`, PHPat architecture rules enforced. The XClass controller must use `GeneralUtility::makeInstance()` because it inherits the Core constructor signature. |
| Coding guidelines | 20 | 20 | `declare(strict_types=1)` in every `Classes/**/*.php`. PHP 8.3 typed constants (`private const string`) everywhere Rector reached. PSR-12 via PHP-CS-Fixer PER-CS 2.0. |
| PHP quality | 19 | 20 | PHPStan level 9 + strict rules + PHPat + `saschaegerer/phpstan-typo3:^3.0`. Level 10 surfaces 131 generic-array findings — deferred. No `@phpstan-ignore` markers in source. |
| Testing | 16 | 20 | 162 tests (90 unit + 72 functional). `RecordGridDataProvider`, `RecordListController` and ViewHelpers still uncovered. |
| Practices | 18 | 20 | GitHub Actions CI (PHP 8.3 + 8.4, MySQL 8), PHP-CS-Fixer auto-fix, PHPStan, dedicated `composer audit` job. Rector config committed. |
| **Subtotal** | **91** | 100 | |
| Excellence bonus | +4 | up to 22 | `#[\ReadOnly]` on services where Rector could prove it safe; `#[\Override]` on inherited methods; PHPat layer rules; TrustedHtml/sanitizer pattern for XSS hardening. |
| **Final** | **95** | | **Excellent — TER-ready** |

Delta since round 1: **94 → 95**.

## Scan results

### Green

- `grep -rL 'strict_types' Classes/` — 0 hits.
- `grep -rEn '\(\s*Type\s+\$x\s*=\s*null' Classes/` — 0 hits (no PHP 8.4
  implicit-nullable deprecation).
- `Resources/Private/**/*.html` — no Bootstrap 4 markup (`data-toggle`,
  `data-dismiss`, `data-ride`).
- No deprecated request helpers (`GeneralUtility::_GP` etc.), no
  `ObjectManager`, no `SC_OPTIONS` hook registrations, no FlexForm XML.
- All classes `final`; event listeners use `#[AsEventListener]`; Rector
  applied `#[\ReadOnly]` wherever promoted properties allowed it.

### Yellow (accepted)

- **8 `$GLOBALS` reads** across `Classes/` — all of them type-guarded
  reads of `$GLOBALS['TCA']`, `$GLOBALS['BE_USER']`, `$GLOBALS['LANG']`
  or `$GLOBALS['TYPO3_REQUEST']`. TYPO3 Core exposes these as the public
  globals; no alternative DI surface exists.
- **36 `GeneralUtility::makeInstance()` calls** — almost all inside
  `Classes/Controller/RecordListController.php`, which XClasses the
  Core `RecordListController` and inherits its constructor. This is the
  documented escape hatch.
- **5 `TcaSchemaFactory::has()` checks** before `->get()`. Not the
  "cache has/get" anti-pattern — this is the documented v14 way to
  guard against unknown tables (`get()` throws on missing schema).

### Addressed in this pass

- Deleted **`Resources/Public/JavaScript/view-switcher.js`** — orphaned
  file, not referenced by any Fluid template, PHP class, or
  `Configuration/JavaScriptModules.php` import. The live JS module is
  `GridViewActions.js`, registered via the `@webconsulting/records-list-types/`
  namespace.

## Verification

- `vendor/bin/phpstan analyse` — level 9, 0 errors.
- `vendor/bin/php-cs-fixer fix --dry-run --diff` — 0 diff.
- `vendor/bin/phpunit --testsuite Unit` — 90 tests / 158 assertions.
- `typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml`
  — 72 tests / 155 assertions.
- `composer validate --strict` + `composer audit --locked` — both clean.
