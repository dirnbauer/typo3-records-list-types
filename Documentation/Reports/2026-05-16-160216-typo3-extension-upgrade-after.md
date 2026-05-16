# TYPO3 Extension Upgrade Report After

> Run date: 2026-05-16 16:02:16 Europe/Vienna
> Skill: typo3-extension-upgrade

Changes applied:

- Added v14-only `ext_emconf.php` with TYPO3 `14.3.0-14.99.99`.
- Added TYPO3 14.3 composer metadata:
  `extra.typo3/cms.version` and empty `Package.providesPackages`.
- Raised PHPStan to `level: max`.
- Updated README and documentation reports to match the v14.3 LTS state.

Verification: PHPStan max, unit, functional, CGL, composer, and aggregate
CI checks are green.

