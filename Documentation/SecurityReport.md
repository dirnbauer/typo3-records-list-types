# Security Audit Report

**Extension:** `records_list_types`  
**Vendor:** Webconsulting  
**Date:** 2025-02-09  
**Auditor:** AI Security Review (typo3-security skill)  
**TYPO3 Compatibility:** v13.x / v14.x  
**Scope:** All PHP classes, JavaScript modules, Fluid templates, configuration files

---

## Executive Summary

The `records_list_types` extension demonstrates **good overall security posture** for a TYPO3 backend extension. All database queries use parameterized QueryBuilder, input validation is applied consistently, and Fluid templates use auto-escaping by default. The extension operates exclusively in the authenticated backend context, limiting the attack surface.

**Overall Risk: LOW**

| Category | Rating | Notes |
|----------|--------|-------|
| SQL Injection | PASS | All queries use `createNamedParameter()` with proper types |
| XSS Prevention | PASS (with notes) | Fluid auto-escaping; `f:format.raw()` used only for trusted TYPO3-generated HTML |
| CSRF Protection | PASS | TYPO3 backend AJAX routes include automatic CSRF protection |
| Input Validation | PASS | Mode, sort field, and sort direction are validated against allowlists |
| Authentication | PASS | All operations require authenticated backend user |
| Error Handling | PASS | Generic error messages; no internal details exposed |
| JavaScript Security | NEEDS IMPROVEMENT | `innerHTML` usage, URL string concatenation |
| Code Hardening | ADVISORY | Minor hardening opportunities |

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

### 2. XSS Prevention - PASS (with notes)

**Status:** Secure with acceptable patterns

Fluid templates use auto-escaping by default (`{variable}` is always escaped). The `f:format.raw()` ViewHelper is used in several places, but exclusively for trusted content:

- **GridView.html lines 66-87:** Renders TYPO3-generated button HTML (`actionButtons.newRecordButton`, etc.) — these are produced by TYPO3's ButtonBar API and contain pre-escaped content.
- **RecordActions.html line 51:** Renders `{actionHtml}` from `GridViewRecordActionsListener` — this HTML originates from TYPO3's own `ModifyRecordListRecordActionsEvent` and is trusted backend content.
- **RecordActionsViewHelper:** Has `$escapeOutput = false` — necessary for rendering HTML action buttons. The HTML source is TYPO3's internal event system.

**Card.html line 241:** `<a href="mailto:{fieldData.raw}">` — `{fieldData.raw}` is auto-escaped by Fluid in attribute context, preventing `javascript:` injection. No action needed.

**Description field:** `strip_tags()` is applied in `RecordGridDataProvider::enrichRecord()` line 484, preventing HTML injection in descriptions.

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

### 7. JavaScript Security - NEEDS IMPROVEMENT

**Finding JS-1: `innerHTML` usage in `replaceIcon()` (Low Risk)**

**File:** `Resources/Public/JavaScript/GridViewActions.js`, lines 991-995

```javascript
temp.innerHTML = iconMarkup;
const newIcon = temp.firstElementChild;
```

The `iconMarkup` comes from TYPO3's `Icons.getIcon()` module, which is a trusted source. However, using `innerHTML` is a known XSS vector if the source were ever compromised.

**Recommendation:** Replace with `DOMParser` for safer HTML parsing.

**Finding JS-2: URL construction via string concatenation (Low Risk)**

**File:** `Resources/Public/JavaScript/GridViewActions.js`, lines 1044, 1079

```javascript
const infoUrl = moduleUrl + '&table=' + table + '&uid=' + uid + '&returnUrl=' + returnUrl;
const historyUrl = moduleUrl + '&element=' + element + '&returnUrl=' + returnUrl;
```

Values come from `data-*` attributes (auto-escaped by Fluid) and TYPO3 settings, so the practical risk is low. However, using `URL` + `URLSearchParams` would be more robust.

**Recommendation:** Use `URL` constructor with `searchParams.set()` for URL building.

**Finding JS-3: Delete confirmation allows title in prompt (Informational)**

**File:** `Resources/Public/JavaScript/GridViewActions.js`, line 824

```javascript
`Are you sure you want to delete "${title}"?`
```

The `title` is inserted into the TYPO3 Modal via its text API (not innerHTML), so this is safe. No action needed.

### 8. Code Hardening - ADVISORY

**Finding CH-1: `RecordActionsViewHelper` not declared `final`**

**File:** `Classes/ViewHelpers/RecordActionsViewHelper.php`, line 25

All other classes in the extension are declared `final`. This ViewHelper should also be `final` to prevent unintended subclassing.

**Finding CH-2: `MiddlewareDiagnosticService` uses `include` for PHP files**

**File:** `Classes/Service/MiddlewareDiagnosticService.php`, line 144

```php
$middlewares = include $middlewareFile;
```

The file path is constructed from `$package->getPackagePath()`, which is a trusted TYPO3 API. The risk is negligible but adding a path validation check would be defense-in-depth.

**Finding CH-3: `RecordListController` not declared `final`**

**File:** `Classes/Controller/RecordListController.php`, line 39

This is expected since it's an XClass extending `CoreRecordListController`. Declaring it `final` would prevent further XClassing by other extensions, which may be desirable for security.

---

## Recommendations Summary

### Must Fix (0 items)
None — no critical or high-risk findings.

### Should Fix (2 items)

| ID | Finding | Risk | Effort |
|----|---------|------|--------|
| JS-1 | Replace `innerHTML` with `DOMParser` in `replaceIcon()` | Low | Low |
| JS-2 | Use `URL`/`URLSearchParams` for URL construction in `showInfo()` and `showHistory()` | Low | Low |

### Nice to Have (3 items)

| ID | Finding | Risk | Effort |
|----|---------|------|--------|
| CH-1 | Add `final` to `RecordActionsViewHelper` | Informational | Trivial |
| CH-2 | Add path validation before `include` in `MiddlewareDiagnosticService` | Informational | Low |
| CH-3 | Add `final` to `RecordListController` to prevent further XClassing | Informational | Trivial |

---

## Checklist (per typo3-security skill)

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

---

*Report generated following TYPO3 Security Hardening guidelines (typo3-security v2.0.0)*
