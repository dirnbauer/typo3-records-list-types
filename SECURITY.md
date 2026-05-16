# Security Policy

## Supported versions

| Version | Status |
|---------|--------|
| 1.x on TYPO3 v14 | Active — security fixes |
| anything else | Not supported |

## Reporting a vulnerability

Please **do not open a public GitHub issue** for security findings.

Email the maintainer at **office@webconsulting.at** with:

- a description of the issue,
- reproduction steps or a minimal proof of concept,
- the affected version of the extension and TYPO3,
- any proposed mitigation.

You will receive an acknowledgement within three working days. A fix,
advisory, and CVE coordination (where applicable) will follow as quickly
as the impact allows.

## Scope

This policy covers the `webconsulting/records-list-types` extension
itself. Vulnerabilities in TYPO3 Core should be reported to the
[TYPO3 Security Team](https://typo3.org/help/security-advisories/).

## Scope summary of current hardening

- All SQL through parameterised `QueryBuilder`, no concatenated values.
- XSS surfaces gated behind the `TrustedHtml` value object.
- Backend AJAX endpoints are CSRF-protected by Core's router.
- All input (view mode, sort field, direction, page id) validated against
  allow-lists before use.
