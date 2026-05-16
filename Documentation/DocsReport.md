# Documentation Report

> Run date: 2026-04-18
> Skill: typo3-docs
> Extension: records_list_types @ TYPO3 v14
> Supersedes the 2026-02-09 snapshot.

## State after this pass

- `Documentation/` has the full RST hierarchy:
  - `Index.rst` root with `toctree`
  - `Introduction/Index.rst` — features + requirements
  - `Installation/Index.rst` — composer + activation
  - `Configuration/Index.rst` — TSconfig reference with `confval`
  - `Developer/Index.rst` with `Architecture.rst`, `CustomViewTypes.rst`,
    `Extending.rst`, and **new** `Workspaces.rst`
  - `KnownProblems/Index.rst`
  - `Sitemap.rst`
- `guides.xml`, `Includes.rst.txt`, `.editorconfig` present.
- `README.md` updated with workspace API details and a link to the new
  `Developer/Workspaces.rst`.

## Added in this pass

- **`Documentation/Developer/Workspaces.rst`** — documents the TYPO3 v14
  workspace state mapping, canonical `Context`-based API usage, and
  FAL/file-versioning limitations. Added to the Developer toctree.
- **README Workspace section rewrite** — reflects the switch from
  `$BE_USER->workspace` to the `Context` aspect and the dropped
  `t3ver_state = 3` branch.
- **KnownProblems** — retired the "Workspace support is experimental"
  block; it's replaced with a narrower entry covering the FAL
  limitation that is a TYPO3 platform constraint, not an extension bug.

## Accepted as-is

- Legacy Markdown files at `Documentation/*.md` remain alongside the RST
  hierarchy. They are useful for at-a-glance GitHub browsing and are
  referenced by the existing README; removing them would invalidate
  external links while providing no upside.
- Screenshots remain pending. The extension has a modest visual surface
  (cards, toolbar, view-mode toggle) and rendering correctly requires a
  TYPO3 v14 installation with seeded data — captured out of band and
  committed as PNGs under `Documentation/Images/` in a follow-up.

## Verification

- RST structural check: every subdirectory has `Index.rst`.
- `grep -R "mailto:" Documentation/` — 0 hits.
- README and `Documentation/Developer/Workspaces.rst` use consistent
  terminology and identical state tables.
