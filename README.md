# Records List Types

Grid, compact, teaser and custom Fluid views for the TYPO3 v14 backend
**Records** module. Every view is built from the module's own parts, so
editors keep the actions, states and shortcuts of the List View; only the
arrangement of the records changes.

## What it is

- **List view** stays the Core table. **Grid** shows cards with thumbnails,
  fields and translations, **Compact** is the record table in a denser form
  with the record ID and missing translations, **Teaser** shows wide cards
  with dates and teaser text.
- **Core's controls in every view**: the record icon opens the context menu,
  each record carries Core's control panel (edit, visibility, move up/down,
  delete, info, history, clipboard and the actions other extensions add),
  plus the selection bar, pagination, localization wizard, workspace states,
  record locks and the page identity under the title.
- **Reordering** by drag and drop, from the keyboard on a card's handle, or
  with Core's "Move up" and "Move down".
- **Custom views** are Page TSconfig plus a Fluid template, or the
  `RegisterViewModesEvent` PSR-14 event. `Table/Section` and the `Record/*`
  partials give a custom template the same frame and record parts.
- **Record filters** for text, date range, visibility, select and category
  fields, configured per table, also above the List View.
- **Light and dark mode** from TYPO3's design tokens only; keyboard operable
  and labelled for screen readers (WCAG 2.2 AA).
- Labels ship as XLIFF 2.0 (English source, German reviewed, French, Spanish
  and Italian machine drafts).

## Requirements

- TYPO3 14.3.7 or later (v14 series)
- PHP 8.4 or 8.5
- Composer mode

## Install

```bash
composer require webconsulting/records-list-types:^1.3
vendor/bin/typo3 extension:setup -e records_list_types
vendor/bin/typo3 cache:flush
```

If Composer cannot find the package, add the VCS source first:

```bash
composer config repositories.records-list-types vcs https://github.com/dirnbauer/typo3-records-list-types.git
```

## Configure

The extension ships `Configuration/page.tsconfig`; override it in your site's
Page TSconfig:

```typoscript
mod.web_list.viewMode {
    default = grid
    allowed = list,grid,compact,teaser
    table.tt_content = compact
    types.grid.itemsPerPage = 100
}

mod.web_list.gridView.table.tt_content {
    titleField = header
    descriptionField = bodytext
    imageField = image
    preview = 1
}
```

Explicit view selection wins over table preferences, table defaults, the user's
global preference and the page default. `mod.web_list.allowedViews` from
pre-1.0 releases still works but is deprecated. Filters are documented in the
[filter reference](Documentation/Configuration/Filters.rst).

## Use

Open **Content → Records** and choose a view from the **View** dropdown in the
module header. Single-table views paginate; multi-table views show a preview
with an *Expand table* link. Use **View → Show filters** to open the filter
panel and the sorting mode buttons to switch between manual order and sorting
by column.

## Develop

```bash
composer install
Build/Scripts/runTests.sh -s ci                                   # composer, lint, cgl, phpstan, unit
typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional
```

PHPStan runs at level 8 with strict rules and PHPat, PHP-CS-Fixer uses the
TYPO3 coding standards, and CI runs every suite on PHP 8.4 and 8.5, with
functional tests on MariaDB 10.11. Labels live in
`Resources/Private/Language/locallang.xlf`; reference them as
`records_list_types.messages:key` in PHP and Fluid. The unit suite checks that
every referenced key exists, that templates carry no hard-coded English, and
that the stylesheets use TYPO3's design tokens only.

## Docs

The RST manual in [Documentation](Documentation/Index.rst) is the canonical
reference: [configuration](Documentation/Configuration/Index.rst),
[custom view types](Documentation/Developer/CustomViewTypes.rst),
[extension points](Documentation/Developer/Extending.rst),
[architecture](Documentation/Developer/Architecture.rst),
[editor guide](Documentation/Usage/Index.rst),
[workspaces](Documentation/Developer/Workspaces.rst) and
[known limitations](Documentation/KnownProblems/Index.rst). Changes are
recorded in [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later · [Webconsulting](https://github.com/dirnbauer/typo3-records-list-types)
