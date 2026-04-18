# Security Audit Report (OWASP / CWE)

> Run date: 2026-04-18 (round 2)
> Skill: security-audit
> Extension: records_list_types @ TYPO3 v14
> Frameworks: OWASP Top 10 (2021), CWE Top 25 (2025), CVSS v4.0

## Summary

Second independent sweep in the OWASP/CWE frame. Baseline is the round-1
report plus the round-1 remediation (composer audit CI job, Dependabot,
LICENSE/SECURITY.md). No new findings; the Rector modernization did not
introduce any regressions.

## OWASP Top 10 (2021) checklist

| ID | Category | Status | Evidence |
|----|----------|--------|----------|
| A01 | Broken Access Control | PASS | Table visibility + `tables_select` + TSconfig `hideTables` guard every read path; controller trusts Core's backend auth stack. |
| A02 | Cryptographic Failures | N/A | No secrets, no tokens, no PII stored. |
| A03 | Injection | PASS | 7 `QueryBuilder::createNamedParameter()` call sites, 0 concatenated SQL, 0 `shell_exec`/`system`/`eval`. |
| A04 | Insecure Design | PASS | Single-responsibility services, allow-list input validation, `TrustedHtml` + `f:sanitize.html` to keep trusted/untrusted HTML separate by type. |
| A05 | Security Misconfiguration | PASS | No debug output, no `display_errors`, no hardcoded credentials, no wildcard ACLs. |
| A06 | Vulnerable Components | PASS | `composer audit` job runs on every push/PR — currently 0 advisories. Dependabot tracks composer + github-actions weekly. |
| A07 | Auth & Session | PASS | Delegates to TYPO3 Core backend auth; no custom session handling. |
| A08 | Software/Data Integrity | PASS | `unserialize` not used anywhere. JSON via `json_decode(..., true)`. One `include` in `MiddlewareDiagnosticService` is guarded by `realpath()` + `str_starts_with()` against the declared package path. |
| A09 | Security Logging | PASS | Failures route to PSR `LoggerInterface`; user-facing messages are generic. |
| A10 | SSRF | N/A | No outbound HTTP from the extension. |

## CWE Top 25 (2025) sweep

Grepped `Classes/` for the high-risk sinks:

- `unserialize`, `eval`, `system`, `exec`, `shell_exec`, `passthru`,
  `proc_open`, `popen`, `assert(...)` — 0 hits.
- `mt_rand`, `rand(`, `md5(`, `sha1(`, `mcrypt` — 0 hits.
- `loadXML`, `simplexml_*` — 0 hits (no XML parsing at all).
- `file_get_contents`, `fopen` against user input — 0 hits.
- `include $var` — 1 hit in `MiddlewareDiagnosticService`, path validated
  through `realpath()` + `str_starts_with($realPackagePath)`.
- `innerHTML =` in JavaScript modules — 0 hits.

## GitHub Actions review (`.github/workflows/ci.yml`)

- Third-party actions pinned to major: `actions/checkout@v4`,
  `shivammathur/setup-php@v2`, `actions/upload-artifact@v4`,
  `stefanzweifel/git-auto-commit-action@v5`. Safe against repo takeover
  of a single tag as long as the maintainers keep semver discipline.
  SHA-pinning would be stricter; left as a follow-up.
- `contents: write` scoped only to the PHP-CS-Fixer job, which needs to
  commit auto-fixes back to the PR branch.
- `ref: ${{ github.head_ref || github.ref_name }}` is passed to the
  action's `ref` input (not a `run:` script) — no injection vector.
- No `${{ github.event.* }}` or `${{ inputs.* }}` interpolation inside
  any `run:` block.

## Supply-chain state

- `composer audit --locked --abandoned=report` — no advisories, no
  abandoned packages.
- `.github/dependabot.yml` tracks `composer` and `github-actions`
  weekly. Dev-tooling (PHPStan, PHPUnit, PHP-CS-Fixer, Rector, phpat,
  testing-framework, phpstan-typo3) grouped to avoid PR floods.

## Findings

None. The round-1 observations (#1 add `composer audit`, #2 Dependabot)
remain resolved in the committed CI config.

## Verification

- CWE sink grep sweep — 0 hits.
- `composer audit --locked` — 0 advisories.
- `composer validate --strict` — valid.
- `vendor/bin/phpstan analyse` — level 9, 0 errors.
- `vendor/bin/phpunit` — 90 unit + 72 functional tests pass.
