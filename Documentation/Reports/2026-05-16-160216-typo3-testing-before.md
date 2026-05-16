# TYPO3 Testing Report Before

> Run date: 2026-05-16 16:02:16 Europe/Vienna
> Skill: typo3-testing

Findings before changes:

- Unit suite passed: 118 tests / 214 assertions.
- PHPStan passed at level 9.
- Functional suite failed without DB configuration; SQLite env was required.
- After adding `ext_emconf.php`, functional tests reported a TYPO3 14.3
  metadata deprecation until composer metadata was added.

