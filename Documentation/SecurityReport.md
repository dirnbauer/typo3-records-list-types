# TYPO3 Security Hardening Report

> Run date: 2026-04-18
> Skill: typo3-security
> Extension: records_list_types @ TYPO3 v14
> Supersedes the 2026-02-09 security audit snapshot.

## Scope

The extension is a TYPO3 v14 backend-only module that XClasses the Core
`RecordListController` to add Grid, Compact, Teaser and custom view types.
No frontend surface, no public endpoint. All operations run behind the
Core backend authentication and authorisation stack.

## Overall risk

**LOW.** No regression since the 2026-02-09 audit. Hardening remains
defensive-in-depth.

| Category | Result |
|----------|--------|
| SQL injection | Pass — all queries go through `QueryBuilder` with `createNamedParameter(..., ParameterType::INTEGER\|STRING)`. No concatenated SQL, no `executeQuery()` with string fragments. |
| XSS | Pass — Fluid auto-escapes by default. Trusted backend HTML fragments cross the template boundary exclusively through TYPO3 Core's `f:sanitize.html` ViewHelper with a dedicated `records-list-types-backend-fragments` preset. |
| CSRF | Pass — AJAX endpoints in `Configuration/Backend/AjaxRoutes.php` receive the default backend CSRF guard. Controllers read `ServerRequestInterface`, never `$_POST` / `$_GET`. |
| Input validation | Pass — `ViewModeController::setViewModeAction()` validates `mode` against `ViewModeResolver::isValidMode()`; sort field names are allow-listed against TCA; page and uid arguments cast to `int`. |
| Authentication | Pass — controllers rely on Core's backend auth middleware. No operation is reachable from the frontend. |
| Authorisation | Pass — table visibility + `tables_select` + TSconfig `hideTables` all checked before data is read. |
| File handling | Pass — thumbnail resolution goes through TYPO3's FAL `ProcessedFile` API, never touches raw filesystem paths from user input. |
| Error handling | Pass — AJAX responses return a generic error string; full exception detail is written to `LoggerInterface` only. |
| JavaScript | Pass — no `innerHTML` from untrusted sources, URLs built via `URL` / `URLSearchParams`, HTML parsed via `DOMParser`. |
| Hardening | Pass — all classes `final`, readonly promoted properties, no `eval`, no `include` with user input. |

## v14-specific hardening confirmations

- **Frontend CSP feature flag** is not applicable — the extension does
  not render frontend content. Backend CSP is enforced by Core by default
  on v13+.
- **`security.backend.enforceReferrer`**: no extra listener required;
  the extension works under the strict referrer policy.
- **Password policy / MFA**: not altered by this extension. All backend
  users continue to use the installation-wide configuration.

## Mitigations accepted as-is

- Physical files under `fileadmin/` remain unversioned in workspaces
  (TYPO3 platform limitation). The extension displays live thumbnails in
  all workspace contexts; this matches Core behaviour. See
  `Documentation/WorkspacesReport.md` for the wider rationale.
- Static caches in `GridViewQueryListener` are per-request (PHP-FPM
  process), bounded at 100 entries. Acceptable for HTTP request lifetime.

## Verification

- Grep: `GeneralUtility::_GP|_POST|_GET` — 0 hits.
- Grep: `f:format.raw` — 0 hits (sanitisation runs through `f:sanitize.html`).
- Grep: `data-toggle|data-dismiss|data-ride` — 0 hits.
- `vendor/bin/phpstan analyse` — level 9 clean.
- Functional tests pass with workspace fixtures.
