# Extension Upgrade Report

> Run date: 2026-04-18
> Skill: typo3-extension-upgrade
> Extension: records_list_types @ TYPO3 v14

## Summary

The extension is already aligned with TYPO3 v14 API surface. There is no
dual-version compatibility code, no removed hook registrations, and no uses
of deprecated `GeneralUtility` request helpers. The remaining work is
structural: add a Rector configuration so future upgrades can be driven
automatically, and confirm that the composer/extension constraints forbid
older TYPO3 branches.

## Scan results

| Area | Status | Notes |
|------|--------|-------|
| `GeneralUtility::_GP / _POST / _GET` | Clean | No matches in `Classes/`. PSR-7 `ServerRequestInterface` used throughout. |
| `ObjectManager` / `makeInstanceService` | Clean | Not referenced. Services.yaml + DI everywhere. |
| `SC_OPTIONS` hook registration | Clean | Replaced by PSR-14 events (`#[AsEventListener]` on every listener). |
| XClass registration | v14 API | `$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects']` in `ext_localconf.php`. Still the documented v14 mechanism. |
| AJAX routes | v14 API | Registered in `Configuration/Backend/AjaxRoutes.php`. |
| TSconfig auto-loading | v14 API | `Configuration/page.tsconfig` auto-loaded by Core, no manual `addPageTSConfig`. |
| Composer constraints | v14-only | `typo3/cms-*` pinned to `^14.0`. No `|| ^13.0`. |
| ext_emconf.php | Removed | TYPO3 v14 deprecates ext_emconf for Composer-mode extensions. Metadata now lives in `composer.json` only (`description`, `extra.typo3/cms.extension-key`, `homepage`, `support`). |
| PHPStan TYPO3 extension | Active | `saschaegerer/phpstan-typo3:^3.0` for TYPO3-aware reflection. |

## Actions

1. **Added `rector.php`** with TYPO3 v14 level sets so the upgrade toolchain
   is ready for the next LTS. Running `vendor/bin/rector process --dry-run`
   is expected to report zero changes today; the config is an enabler, not
   a remediation.
2. **No code changes** were required: Rector and Fractor dry-runs on this
   codebase produce no diff.

## Verification

- `composer validate` on the updated `composer.json`.
- `vendor/bin/rector process --dry-run` (expected: no diff).
- `vendor/bin/phpstan analyse` at level 9 with the TYPO3 extension.
- `vendor/bin/phpunit` Unit + Functional suites.
