# Records List Types

Grid, compact, teaser and custom Fluid views for the TYPO3 backend **Records**
module. Editors pick a view per table, filter records, reorder by drag and drop
and use the native record actions; search, translations, workspaces and
pagination share one record pipeline across all views.

## What it is

- **List view** stays the Core table; **Grid**, **Compact** and **Teaser** are
  alternatives with thumbnails, dense rows or teaser cards.
- **Custom views** are registered with Page TSconfig plus a Fluid template, or
  through the `RegisterViewModesEvent` PSR-14 event.
- **Record filters** for text, date range, visibility, select and category
  fields, configured per table.
- Labels ship as XLIFF 2.0 (English source, German reviewed, French, Spanish
  and Italian machine drafts) and follow one terminology across all views.

## Requirements

- TYPO3 14.3.6 or later (v14 series)
- PHP 8.3, 8.4 or 8.5
- Composer mode

## Install

```bash
composer require webconsulting/records-list-types:^1.1
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
panel, the sorting mode toggle to switch between manual ordering and sorting by
column, and the drag handle (mouse or keyboard: Space, arrow keys, Escape) to
reorder records.

## Develop

```bash
composer install
Build/Scripts/runTests.sh -s ci                                   # composer, lint, cgl, phpstan, unit
typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional
```

PHPStan runs at level 8 with strict rules and PHPat, PHP-CS-Fixer uses the
TYPO3 coding standards, and CI covers PHP 8.3 and 8.4 (8.5 as an allowed
failure) with functional tests on MariaDB 10.11. Labels live in
`Resources/Private/Language/locallang.xlf`; reference them as
`records_list_types.messages:key` in PHP and Fluid. The unit suite checks that
every referenced key exists and that templates carry no hard-coded English.

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
