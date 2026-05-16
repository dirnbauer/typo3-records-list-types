# TYPO3 Testing Report After

> Run date: 2026-05-16 16:02:16 Europe/Vienna
> Skill: typo3-testing

Changes applied:

- Raised PHPStan to level max and fixed all resulting type findings.
- Added composer metadata to remove the `ext_emconf.php` functional
  deprecation.
- Updated `Build/Scripts/runTests.sh` wording to level max.

Verification:

- `Build/Scripts/runTests.sh -s ci` green.
- `typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional`
  green: 72 tests / 155 assertions.

