# TYPO3 Security Hardening Report

> Run date: 2026-04-18 (round 2)
> Skill: typo3-security
> Extension: records_list_types @ TYPO3 v14

Supersedes the round-1 snapshot. Second sweep ran after the Rector
modernization pass (`#[\ReadOnly]` classes, typed constants, arrow-fn
types) — no regression.

## Scope

Backend-only TYPO3 v14 extension that XClasses the Core
`RecordListController` to add Grid, Compact, Teaser and custom view
types. No frontend surface, no public endpoint. All operations sit
behind Core's backend auth middleware.

## Overall risk

**LOW.** Clean across every check the skill prescribes.

| Category | Result |
|----------|--------|
| SQL injection | Pass — 7 `createNamedParameter(..., ParameterType::INTEGER\|STRING)` call sites, 0 concatenated SQL, 0 `executeQuery()` with string fragments. |
| XSS | Pass — Fluid auto-escapes by default. No `f:format.raw` anywhere. Trusted backend HTML goes through Core's `f:sanitize.html` with the `records-list-types-backend-fragments` preset. The lone `mailto:` link in `Card.html` sits in an attribute context (auto-escaped by Fluid) and the value is `strip_tags()`-cleaned upstream. |
| CSRF | Pass — backend AJAX routes (`records_list_types_set_view_mode`, `records_list_types_get_view_mode`) inherit Core's default auth + CSRF guard. Controllers read `ServerRequestInterface`, never `$_POST` / `$_GET`. |
| Input validation | Pass — `ViewModeController::setViewModeAction()` validates `mode` via `ViewModeResolver::isValidMode()`; sort field names are allow-listed against TCA; page/uid arguments cast to `int`; the single user-controlled `returnUrl` is passed through `GeneralUtility::sanitizeLocalUrl()` before use. |
| Authentication | Pass — every request flows through Core's backend auth. |
| Authorisation | Pass — table visibility + `tables_select` + TSconfig `hideTables` are checked before data is read. |
| File handling | Pass — thumbnail resolution uses TYPO3's FAL `ProcessedFile` API; no raw filesystem paths from user input. |
| Error handling | Pass — AJAX responses return a generic error string; full exception detail is written to `LoggerInterface` only. |
| JavaScript | Pass — 0 `innerHTML =` assignments in `Resources/Public/JavaScript/`, 0 `eval()` / `document.write`. URLs built via `URL` / `URLSearchParams`, HTML parsed via `DOMParser`. Orphaned `view-switcher.js` deleted in the conformance commit. |
| Response headers | Pass (in scope) — Core's `JsonResponse` sets `Content-Type: application/json`. `X-Content-Type-Options: nosniff`, `X-Frame-Options`, etc. are server-level and therefore outside the extension's scope. |
| Hardening | Pass — every class `final`, services now `final readonly` where Rector could prove it safe, no `eval`, no `include` with user input. |

## v14-specific hardening confirmations

- Frontend CSP feature flags are not applicable — the extension does
  not render frontend content. Backend CSP is enforced by Core by
  default on v13+.
- `security.backend.enforceReferrer` compatibility verified: the
  extension works under the strict referrer policy.
- Password policy / MFA are not altered by the extension; installations
  keep the site-wide configuration.

## Mitigations accepted as-is

- Physical files under `fileadmin/` remain unversioned in workspaces
  (TYPO3 platform limitation). The extension always renders the live
  binary in workspace contexts. See `Documentation/WorkspacesReport.md`.
- Static caches in `GridViewQueryListener` are per-request (PHP-FPM
  process), bounded at 100 entries.

## Verification

- Grep: `GeneralUtility::_GP|_POST|_GET` — 0 hits.
- Grep: `f:format.raw` — 0 hits.
- Grep: `innerHTML\s*=` in `Resources/Public/JavaScript/` — 0 hits.
- Grep: `data-toggle|data-dismiss|data-ride` — 0 hits.
- `vendor/bin/phpstan analyse` — level 9 clean.
- `vendor/bin/phpunit` — 90 unit + 72 functional tests pass.
