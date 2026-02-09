# Security Audit Report

**Extension:** `records_list_types`  
**Vendor:** Webconsulting  
**Date:** 2026-02-09  
**Auditor:** AI Security Review (security-audit skill)  
**TYPO3 Compatibility:** v13.x / v14.x  
**Scope:** All PHP classes, JavaScript modules, Fluid templates, configuration files

---

## Executive Summary

The `records_list_types` extension demonstrates **excellent security posture** for a TYPO3 backend extension. All database queries use parameterized QueryBuilder, input validation is applied consistently, Fluid templates use auto-escaping, and JavaScript follows secure patterns with `DOMParser` and `URL`/`URLSearchParams`. The extension operates exclusively in the authenticated backend context, limiting the attack surface.

All findings from the previous audit (2025-02-09) have been resolved.

**Overall Risk: LOW**

| Category | Rating | Notes |
|----------|--------|-------|
| SQL Injection | PASS | All queries use `createNamedParameter()` with proper types |
| XSS Prevention | PASS | Fluid auto-escaping; `f:format.raw()` used only for trusted TYPO3-generated HTML |
| CSRF Protection | PASS | TYPO3 backend AJAX routes include automatic CSRF protection |
| Input Validation | PASS | Mode, sort field, and sort direction validated against allowlists |
| Authentication | PASS | All operations require authenticated backend user |
| Error Handling | PASS | Generic error messages; no internal details exposed |
| JavaScript Security | PASS | `DOMParser` used; `URL`/`URLSearchParams` for URL construction |
| Code Hardening | PASS | All classes `final`; path validation on file includes |

---

## Detailed Findings

### 1. SQL Injection Prevention - PASS

**Status:** Secure

All database queries in `RecordGridDataProvider` use TYPO3's QueryBuilder with prepared statements:

- `createNamedParameter($pageId, ParameterType::INTEGER)` for integer parameters
- `escapeLikeWildcards($searchTerm)` for LIKE queries
- `isValidSortField()` validates sort fields against TCA columns before use in `orderBy()`
- No raw SQL concatenation anywhere in the codebase

**Files reviewed:**
- `Classes/Service/RecordGridDataProvider.php` - All queries safe
- `Classes/Service/GridConfigurationService.php` - Uses BackendUtility (safe)

### 2. XSS Prevention - PASS

**Status:** Secure

Fluid templates use auto-escaping by default (`{variable}` is always escaped). The `f:format.raw()` ViewHelper is used in several places, but exclusively for trusted content:

- **GridView.html:** Renders TYPO3-generated button HTML (`actionButtons.newRecordButton`, etc.) — produced by TYPO3's ButtonBar/ComponentFactory API with pre-escaped content.
- **RecordActions.html:** Renders `{actionHtml}` from `GridViewRecordActionsListener` — HTML originates from TYPO3's own `ModifyRecordListRecordActionsEvent`.
- **RecordActionsViewHelper:** Has `$escapeOutput = false` — necessary for rendering HTML action buttons from TYPO3's internal event system.

**Card.html:** `<a href="mailto:{fieldData.raw}">` — `{fieldData.raw}` is auto-escaped by Fluid in attribute context, preventing `javascript:` injection.

**Description field:** `strip_tags()` is applied in `RecordGridDataProvider::enrichRecord()`, preventing HTML injection in descriptions.

### 3. CSRF Protection - PASS

**Status:** Secure

- TYPO3 backend AJAX routes (registered in `Configuration/Backend/AjaxRoutes.php`) automatically include CSRF token validation in v13/v14.
- The `record_process` AJAX endpoint used by JavaScript is a core TYPO3 endpoint with built-in CSRF protection via URL-embedded tokens.
- JavaScript includes `credentials: 'same-origin'` on all fetch requests.

### 4. Input Validation - PASS

**Status:** Secure

| Input | Validation | Location |
|-------|-----------|----------|
| `displayMode` | Checked against `getAllowedModes()` | `ViewModeResolver::getActiveViewMode()` |
| `mode` (AJAX) | Checked via `isValidMode()` | `ViewModeController::setViewModeAction()` |
| `sortField` | Validated against TCA columns | `RecordGridDataProvider::isValidSortField()` |
| `sortDirection` | Hardcoded to 'ASC' or 'DESC' | `RecordGridDataProvider::createQueryBuilder()` |
| `pageId` | Cast to `(int)` | Multiple locations |
| `returnUrl` | `GeneralUtility::sanitizeLocalUrl()` | `RecordListController::mainAction()` |
| `searchTerm` | Escaped via `escapeLikeWildcards()` | `RecordGridDataProvider::applySearchFilter()` |

### 5. Authentication & Authorization - PASS

**Status:** Secure

- All operations require an authenticated backend user (`$GLOBALS['BE_USER']`).
- `RecordGridDataProvider::getBackendUserAuthentication()` throws `\RuntimeException` if no user is available.
- AJAX routes are backend-only (automatically require backend authentication).
- Workspace restrictions are properly applied via `WorkspaceRestriction`.

### 6. Error Handling - PASS

**Status:** Secure

- `ViewModeController` catches all exceptions and returns generic messages (`'Failed to save preference. Please try again.'`), never exposing stack traces or internal details.
- `ThumbnailService` catches exceptions silently to avoid breaking the rendering pipeline.
- Logged exceptions include the exception object for debugging but don't expose details to the client.

### 7. JavaScript Security - PASS

**Status:** Secure (all previous findings resolved)

- **`replaceIcon()`** now uses `DOMParser` for safe HTML parsing instead of `innerHTML`.
- **`showInfo()` and `showHistory()`** now use `URL` constructor with `searchParams.set()` for safe URL building.
- **Delete confirmation** uses TYPO3 Modal API (text-based, not innerHTML), with native `confirm()` fallback.
- All `fetch()` calls include `credentials: 'same-origin'` and proper headers.

### 8. Code Hardening - PASS

**Status:** Secure (all previous advisories resolved)

- All classes are declared `final` (including `RecordActionsViewHelper` and `RecordListController`).
- `MiddlewareDiagnosticService` validates file paths with `realpath()` and `str_starts_with()` before `include`.
- Constructor-promoted properties use `readonly` modifier throughout.

---

## Remaining Advisories (Informational)

### ADV-1: PHPDoc type annotation mismatch in MiddlewareDiagnosticService (Informational)

**File:** `Classes/Service/MiddlewareDiagnosticService.php`, line 39

```php
/** @var array<string, bool> Cache for diagnostic results */
private array $diagnosticCache = [];
```

The cache actually stores `string[]` arrays (middleware class names), not `bool` values. The annotation should be `array<string, mixed>` or `array<string, string[]>`.

**Risk:** None (documentation only). **Effort:** Trivial.

### ADV-2: Hardcoded fallback URL in view-switcher.js (Informational)

**File:** `Resources/Public/JavaScript/view-switcher.js`, lines 22-23

```javascript
const baseUrl = document.querySelector('base')?.href || '/';
return baseUrl + 'typo3/ajax/records-list-types/set-view-mode';
```

The fallback URL is constructed without a CSRF token and would fail TYPO3's CSRF validation. The primary path (TYPO3 AJAX URL registry) works correctly. This fallback is unreachable in practice since TYPO3 always registers AJAX URLs.

**Risk:** None (unreachable code path). **Effort:** Trivial.

### ADV-3: Static query cache in GridViewQueryListener (Informational)

**File:** `Classes/EventListener/GridViewQueryListener.php`, line 24

```php
private static array $queryCache = [];
```

Static caches persist across the PHP process lifetime. In TYPO3's PHP-FPM model each request is isolated, so this is not a practical concern. In long-lived worker processes (e.g., RoadRunner, FrankenPHP), this could accumulate memory.

**Risk:** None in standard deployments. **Effort:** Low.

---

## Resolved Findings (from 2025-02-09 audit)

| ID | Finding | Status | Resolution |
|----|---------|--------|------------|
| JS-1 | `innerHTML` in `replaceIcon()` | RESOLVED | Replaced with `DOMParser` |
| JS-2 | URL string concatenation in `showInfo()`/`showHistory()` | RESOLVED | Replaced with `URL`/`URLSearchParams` |
| CH-1 | `RecordActionsViewHelper` not `final` | RESOLVED | Added `final` modifier |
| CH-2 | `include` without path validation | RESOLVED | Added `realpath()` + `str_starts_with()` check |
| CH-3 | `RecordListController` not `final` | RESOLVED | Added `final` modifier |

---

## OWASP Top 10 (2021) Assessment

| Rank | Category | Status | Notes |
|------|----------|--------|-------|
| A01 | Broken Access Control | N/A | Backend-only; TYPO3 handles access control |
| A02 | Cryptographic Failures | N/A | No cryptographic operations |
| A03 | Injection | PASS | All queries parameterized; no OS commands |
| A04 | Insecure Design | PASS | Allowlist validation; defense-in-depth |
| A05 | Security Misconfiguration | PASS | No debug output; generic error messages |
| A06 | Vulnerable Components | N/A | Only depends on TYPO3 core |
| A07 | Auth Failures | PASS | Requires authenticated backend user |
| A08 | Data Integrity Failures | PASS | No deserialization of user input |
| A09 | Logging Failures | PASS | Exceptions logged; no sensitive data in logs |
| A10 | SSRF | N/A | No outbound HTTP requests |

---

## Checklist (per security-audit skill)

- [x] No raw SQL — all queries use QueryBuilder with named parameters
- [x] No `f:format.raw()` on user input — only on trusted TYPO3-generated HTML
- [x] CSRF tokens — TYPO3 backend routes include automatic protection
- [x] Input validation — all user inputs validated/sanitized
- [x] Backend-only — no frontend exposure
- [x] Error handling — generic messages, no stack traces leaked
- [x] Authentication required — all operations check for `$BE_USER`
- [x] Workspace support — `WorkspaceRestriction` applied to all queries
- [x] `sanitizeLocalUrl()` used for return URLs
- [x] `strip_tags()` applied to description fields
- [x] Sort fields validated against TCA schema
- [x] View modes validated against allowlist
- [x] JavaScript uses `DOMParser` instead of `innerHTML`
- [x] JavaScript uses `URL`/`URLSearchParams` for URL construction
- [x] All classes declared `final`
- [x] File include paths validated with `realpath()` + `str_starts_with()`

---

*Report generated following OWASP Top 10 (2021) and TYPO3 Security Hardening guidelines (security-audit v1.0.0)*
