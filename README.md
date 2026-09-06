# Records List Types

Grid, Compact, Teaser and custom Fluid views for the TYPO3 backend Records module.
Editors can switch views per table, filter records, select columns and use native
TYPO3 record actions. Search, translations, workspaces and pagination share the
same record pipeline across alternative views.

Requires **TYPO3 14.3.6 or later in v14** and **PHP 8.3–8.5**.

## Install

Run in your TYPO3 project:

```bash
composer require webconsulting/records-list-types:^1.0
vendor/bin/typo3 extension:setup -e records_list_types
vendor/bin/typo3 cache:flush
```

If the package is unavailable from your Composer repositories, add its VCS source:

```bash
composer config repositories.records-list-types vcs https://github.com/dirnbauer/typo3-records-list-types.git
```

Open **Content → Records** and use the view dropdown in the module header.

## Configure

The extension loads its Page TSconfig automatically. For example:

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

Explicit view selection takes precedence over table preferences, table defaults,
the user's global preference and the page default. Single-table views paginate;
multi-table views show a preview with an “Expand table” link. Setting a view's
`itemsPerPage = 0` disables pagination. The grid adapts its columns to the available
width.

Use **View → Show filters** in a single-table view for configured text, date,
visibility, select and category filters. See the [filter reference](Documentation/Configuration/Filters.rst)
for presets, overrides and workspace behavior.

## Extend

Register a custom view using Page TSconfig and a Fluid template, or use
`RegisterViewModesEvent`. Start from `GenericView.html` and the documented view
payload. Core query events apply through `DatabaseRecordList`; the alternative
views build their own record actions from Core permissions and URLs.

- [Configuration](Documentation/Configuration/Index.rst)
- [Custom view types](Documentation/Developer/CustomViewTypes.rst)
- [Extension points](Documentation/Developer/Extending.rst)
- [Architecture](Documentation/Developer/Architecture.rst)
- [Editor guide](Documentation/Usage/Index.rst)
- [Workspace behavior](Documentation/Developer/Workspaces.rst)
- [Known limitations](Documentation/KnownProblems/Index.rst)

The RST manual in [Documentation](Documentation/Index.rst) is the canonical
reference. Changes and removed APIs are recorded in [CHANGELOG.md](CHANGELOG.md).

## Develop and test

Install the extension's development dependencies in the repository root:

```bash
composer install
Build/Scripts/runTests.sh -s ci
typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional
```

The `ci` suite runs Composer validation and security audit, PHP-CS-Fixer,
PHPStan at maximum level with strict rules and PHPat, and the unit tests.
Functional tests boot TYPO3 with the extension installed. CI covers PHP 8.3,
8.4 and 8.5, with an additional lane for the latest allowed PHPUnit.

For the local backend and DDEV commands, see the
[development setup](Documentation/Developer/Development.rst).

## Limitations

- Drag-and-drop moves records within the currently displayed page of results.
- Workspace thumbnails use the current physical file; TYPO3 does not version file
  contents. Upload a new file when a draft needs a different image.
- Keyboard drag-and-drop is implemented; dedicated screen-reader coverage is
  limited. Report accessibility problems through the issue tracker.

GPL-2.0-or-later · [Webconsulting](https://github.com/dirnbauer/typo3-records-list-types)
