# TYPO3 Conformance Report

> Run date: 2026-04-18
> Skill: typo3-conformance
> Extension: records_list_types @ TYPO3 v14
> Scoring reference: 0-100 with architecture/guidelines/PHP/testing/practices weights

Supersedes the 2026-02-09 snapshot. Test infrastructure, PHPStan config,
and PHP-CS-Fixer rules have all been added since the original audit.

## Overall Score

| Category | Score | Weight | Notes |
|----------|------:|-------:|-------|
| Architecture | 18 | 20 | Services.yaml with autowire+autoconfigure, PSR-14 events via `#[AsEventListener]`, PHPat architecture rules enforced. The XClass controller must use `GeneralUtility::makeInstance()` because it inherits the Core constructor signature. |
| Coding guidelines | 20 | 20 | `declare(strict_types=1)` present in every `Classes/*.php` file. PSR-12 enforced by PHP-CS-Fixer PER-CS2.0 preset. |
| PHP quality | 19 | 20 | PHPStan level 9 with strict rules + PHPat + `saschaegerer/phpstan-typo3:^3.0`. No `@phpstan-ignore` markers in code. No baseline file. |
| Testing | 16 | 20 | 162 tests (Unit + Functional). Coverage still missing on `RecordGridDataProvider`, `RecordListController`, and ViewHelpers. |
| Practices | 18 | 20 | GitHub Actions CI (PHP 8.3 + 8.4, MySQL 8), PHP-CS-Fixer auto-fix, PHPStan on every push, plus a dedicated `composer audit` job. |
| **Subtotal** | **91** | 100 | |
| Excellence bonus | +3 | up to 22 | Rector config staged; `f:sanitize.html` backend-fragment preset for XSS hardening; final classes everywhere. |
| **Final** | **94** | | **Excellent — TER-ready** |

## Findings

### Green

- `declare(strict_types=1)` in every `Classes/**/*.php`, `Tests/**/*.php`
  and `Configuration/**/*.php`.
- `ext_emconf.php` removed — metadata consolidated in `composer.json`
  (TYPO3 v14 deprecates the legacy file for Composer-mode installations).
- No Bootstrap 4 legacy markup (`data-toggle`, `data-dismiss`, `data-ride`)
  in `Resources/Private/**`.
- No deprecated request helpers, no `ObjectManager`, no `SC_OPTIONS`
  hook registrations, no FlexForm XML in code.
- All classes `final`; event listeners use `#[AsEventListener]`.

### Yellow (accepted)

- `GeneralUtility::makeInstance()` inside
  `Classes/Controller/RecordListController.php`. The XClass inherits the
  Core constructor signature, so `makeInstance()` is the documented
  escape hatch.
- `$GLOBALS['TCA']`, `$GLOBALS['BE_USER']`, `$GLOBALS['LANG']`,
  `$GLOBALS['TYPO3_REQUEST']` reads in a few services. Every access is
  type-guarded; TYPO3 Core exposes these as public globals.

### Addressed in this pass

- Added `.editorconfig` at repo root for cross-editor consistency.
- Added `LICENSE` (GPL-2.0-or-later) to match the `composer.json`
  license declaration.
- Added `SECURITY.md` with vulnerability-reporting guidance.

## Verification

- `vendor/bin/phpstan analyse` — level 9, 0 errors on PHP 8.3.
- `vendor/bin/php-cs-fixer fix --dry-run --diff` — 0 diffs.
- `vendor/bin/phpunit` — Unit (90 tests / 158 assertions) green.
- `typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml` — Functional (72 tests / 155 assertions) green.
- `composer validate --strict` and `composer audit --locked` both clean.
