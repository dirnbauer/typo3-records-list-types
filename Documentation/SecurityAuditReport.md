# Security Audit Report (OWASP / CWE)

> Run date: 2026-04-18
> Skill: security-audit
> Extension: records_list_types @ TYPO3 v14
> Frameworks: OWASP Top 10 (2021), CWE Top 25 (2025)

## Summary

Independent review in the OWASP/CWE frame. Findings are consistent with
the TYPO3-specific SecurityReport: no high-severity issues. Two
informational observations are logged for the CI pipeline and dependency
hygiene.

## OWASP Top 10 (2021) checklist

| ID | Category | Status | Evidence |
|----|----------|--------|----------|
| A01 | Broken Access Control | PASS | `TableAccessService::canRenderTable()` enforces `tables_select` and TSconfig `hideTables`; controller trusts Core's backend auth stack. |
| A02 | Cryptographic Failures | N/A | Extension stores no secrets, no tokens, no PII. |
| A03 | Injection | PASS | All SQL via `QueryBuilder` with `createNamedParameter()`. No `executeQuery(` with interpolation. No `shell_exec`/`system`/`eval`. |
| A04 | Insecure Design | PASS | Single-responsibility services, allow-list input validation, `TrustedHtml` value object to keep trusted/untrusted HTML separate by type. |
| A05 | Security Misconfiguration | PASS | No debug output, no `display_errors`, no hardcoded credentials, no wildcard ACLs. |
| A06 | Vulnerable Components | OBSERVATION | `composer audit` is not yet part of CI. See Findings #1. |
| A07 | Auth & Session | PASS | Delegates to TYPO3 Core backend auth; no custom session handling. |
| A08 | Software/Data Integrity | PASS | No deserialisation of untrusted input (`unserialize` not used). JSON parsing via `json_decode(..., true)`. |
| A09 | Security Logging | PASS | Failures route to PSR `LoggerInterface`; user-facing messages are generic. |
| A10 | SSRF | N/A | No outbound HTTP from the extension. |

## CWE Top 25 (2025) sweep

Grepped `Classes/` for the high-risk sinks:

- `unserialize`, `eval`, `system`, `exec`, `shell_exec`, `passthru`,
  `proc_open`, `popen` — 0 hits.
- `mt_rand`, `rand(`, `md5(`, `sha1(` used for security purposes — 0 hits.
- `file_get_contents` against external URLs — 0 hits.
- `innerHTML` from untrusted input in JS modules — 0 hits.

## GitHub Actions review (`.github/workflows/ci.yml`)

- Third-party actions pinned: `actions/checkout@v4`,
  `shivammathur/setup-php@v2`, `actions/upload-artifact@v4`,
  `stefanzweifel/git-auto-commit-action@v5`. Safe.
- `contents: write` scoped only to the PHP-CS-Fixer job.
- `ref: ${{ github.head_ref || github.ref_name }}` passes through the
  action's `ref` input, **not** a `run:` script — no injection vector.
- No `${{ github.event.* }}` or `${{ inputs.* }}` interpolation inside
  `run:` blocks.

## Findings

### #1 — Observation: add `composer audit` to CI

**Risk:** Medium
**CVSSv3.1:** N/A (process gap, not vulnerability)
**Detail:** CI does not currently run `composer audit` to fail fast on
known advisories in the dependency tree.
**Remediation in this pass:** A `security` job runs `composer audit` on
every push/PR.

### #2 — Observation: Dependabot not configured

**Risk:** Low
**Detail:** No `.github/dependabot.yml`. The extension depends on PHPStan,
PHP-CS-Fixer, PHPUnit and testing-framework, all of which ship security
and quality updates regularly.
**Remediation in this pass:** Added `.github/dependabot.yml` with weekly
updates for `composer` and `github-actions`.

## Verification

- Grep sweep described in the CWE section re-run after changes — still 0
  hits on sinks.
- `composer audit` ships in the new CI job; expect zero advisories on a
  fresh lockfile.
- Dependabot config validated by `gh dependabot` on push.
