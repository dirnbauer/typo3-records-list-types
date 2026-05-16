# Documentation Report

> Run date: 2026-04-18 (round 2)
> Skill: typo3-docs
> Extension: records_list_types @ TYPO3 v14

Supersedes the round-1 snapshot.

## Skill pre-commit checklist

| Item | Status | Note |
|------|--------|------|
| `Documentation/.editorconfig` | Pass | Present. |
| `Index.rst` in every subdirectory | Pass | `find Documentation -mindepth 1 -type d` → 0 misses. |
| Permalink anchor before every heading | Pass | All `.rst` files (except `Sitemap.rst`, auto-generated) open with a `.. _label:` anchor. |
| No `mailto:` links in RST | Pass | Only matches are prose references in the `*.md` report files. |
| README and Documentation synchronized | Pass | Round-2 README updates (workspace API, view-switcher removal, ext_emconf retirement) already match the RST content. |
| 4-space indent, LF endings, UTF-8 | Pass | Enforced by `Documentation/.editorconfig` + `.editorconfig` at repo root. |

## Page length observations

Two RST pages exceed the 250-line soft ceiling the skill recommends:

| Page | Lines | Verdict |
|------|------:|---------|
| `Documentation/Developer/CustomViewTypes.rst` | 591 | Accepted. It is the single source of truth for custom view-type registration, template variables, and worked examples. Splitting would fragment a linear tutorial; readers frequently Ctrl-F between sections. |
| `Documentation/Configuration/Index.rst` | 328 | Accepted. Each `confval` is short; breaking the TSconfig reference into multiple pages would force readers to re-establish context every time. |

Neither page degrades readability — both are structured with clear
subsection anchors that the left-hand TOC picks up.

## Changes in this pass

No structural edits. The existing RST tree already meets every
mandatory criterion from the skill (anchors, directory `Index.rst`,
editorconfig, 4-space indent).

## Outstanding (deferred)

- **Screenshots** — no PNGs under `Documentation/Images/`. Capturing
  them requires a seeded TYPO3 v14 backend with representative records
  in every view mode. Ownership of the screenshot workflow is out of
  scope for this loop.
- **docs.typo3.org registration** — Intercept webhook registration is
  a one-off admin task.

## Verification

- `find Documentation -mindepth 1 -type d ! -exec test -f '{}/Index.rst' ';' -print` — empty.
- `grep -rn 'mailto:' Documentation/*.rst Documentation/**/*.rst` — 0 hits.
- `wc -l Documentation/**/*.rst Documentation/*.rst` — only the two
  pages listed above exceed 250 lines.
