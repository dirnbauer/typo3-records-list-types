# Records List Types for TYPO3 v14

A TYPO3 extension that transforms the backend **Records** module with multiple view modes -- Grid, Compact, Teaser -- providing rich visual alternatives to the traditional table-based List View. Browse records as cards with thumbnails, reorder them with drag-and-drop, and configure view modes and filters via TSconfig.

## Features at a Glance

- **Grid View** -- Card-based layout with thumbnails, drag-and-drop reordering, and field display
- **Compact View** -- Dense single-line rows with fixed columns and horizontal scrolling
- **Teaser View** -- News-style cards with title, date, and description excerpt
- **Custom Views** -- Add view types with TSconfig, Fluid templates, and optional PSR-14 registration hooks
- **Drag & Drop** -- Mouse and keyboard reordering with full WCAG 2.1 accessibility
- **Language Flags** -- Language flag icons displayed per record in grid cards
- **Workspace Support** -- Color-coded indicators for new, modified, moved, and deleted records
- **Dark Mode** -- Full compatibility with TYPO3's dark mode (light/dark themes)
- **Per-Table Config** -- Configure title, description, image, and display fields via TSconfig
- **Record Filters** -- Configure text, visibility, date, category, and select filters via TSconfig
- **User Preferences** -- View mode is persisted per backend user via AJAX
- **Sorting Controls** -- Manual drag ordering and field-based sorting with direction toggle
- **Pagination** -- Matches TYPO3 Core: multi-table mode shows limited records with "Expand table" button, single-table mode shows full pagination (record range, page input, first/prev/next/last)
- **Image Preview Hint** -- Subtle notice below thumbnails reminding editors that the image may not appear on the frontend for certain record types
- **Zero-PHP Extensibility** -- Add new view types with just TSconfig + Fluid template + CSS, no PHP classes needed
- **Search** -- TYPO3 record-list search with workspace-aware post-overlay matching in alternative views
- **Accessibility** -- WCAG 2.1 compliant keyboard navigation, ARIA labels, and screen reader support

## Use Cases

- **News / Blog** -- Teaser view with title, date, and excerpt; Grid view for thumbnail browsing
- **Products / Shop** -- Grid view with large product images; custom catalog view per shop page
- **Team / Staff** -- Grid view with portrait photos; compact address book view
- **Events** -- Timeline or teaser view restricted to the events page, sorted by date
- **Media Assets** -- Grid view as a photo gallery with 48 items per page
- **System Records** -- Compact view with 500 records per page for efficient data management
- **Multi-Site** -- Different default views per page tree using TSconfig conditions
- **Editorial Workflow** -- Custom views showing only status and assignee columns for editors

All views work with any table -- `tx_news`, `tt_content`, `fe_users`, `pages`, or your own custom records.

## Requirements

- TYPO3 v14.3 LTS+
- PHP 8.3+

## Installation

The package is installed from GitHub when it is not available via Packagist.
Add the VCS repository in your TYPO3 project's root `composer.json` first:

```bash
composer config repositories.records-list-types vcs https://github.com/dirnbauer/typo3-records-list-types.git
composer require webconsulting/records-list-types:dev-main
```

Set up the extension and clear TYPO3 caches:

```bash
./vendor/bin/typo3 extension:setup -e records_list_types
./vendor/bin/typo3 cache:flush
```

After installation, navigate to **Content > Records** in the TYPO3 backend. View mode toggle buttons appear in the module header -- click to switch between List, Grid, Compact, and Teaser views.

## Breaking Changes

- Custom Fluid templates that rely on the shared action JavaScript must wrap their rendered content in `<records-list-types-actions>...</records-list-types-actions>`. The built-in templates already do this. Custom templates copied before the Lit migration need the wrapper for drag-and-drop, record actions, sorting, pagination inputs, compact scroll shadows, and client-side search to initialize.

## View Modes

### Grid View

A card-based layout designed for visual browsing of records.

- **Card structure**: Header (icon, title, drag handle, actions), optional thumbnail image (16:9), field values body, and footer (UID, PID, language flag)
- **Thumbnails**: Automatically resolved from FAL image fields (configurable per table)
- **Field display**: Type-aware formatting -- booleans as badges, dates in monospace, relations with count indicators, links as clickable, text truncated with ellipsis
- **Two-column field layout**: Small fields display side-by-side; text/richtext fields span full width
- **Drag-and-drop reordering**: Both mouse and keyboard (Space to grab, arrows to move, Space to drop, Escape to cancel)
- **Record actions**: Inline visibility toggle, TYPO3 14 native contextual edit trigger, delete, plus dropdown for info, history, copy, and cut
- **State indicators**: Hidden records get amber headers; workspace records show blue (new), purple (changed), cyan (moved), or red (deleted) headers
- **Language flags**: Each card shows the record's language flag icon in the bottom-right corner
- **Responsive grid**: Auto-fills columns from 320px minimum, scales from 1 column on mobile to multiple on wide screens

### Compact View

A dense table layout for efficient data scanning with many records.

- **Fixed columns**: Icon, UID, and title are pinned on the left; status toggle, TYPO3 14 native contextual edit, and delete are pinned on the right
- **Scrollable middle**: Additional fields scroll horizontally between the fixed columns
- **Scroll shadows**: Visual indicators appear when content extends beyond the visible area
- **Sortable headers**: Click column headers to sort ascending/descending via TYPO3's native dropdown API
- **Zebra striping**: Alternating row colors for readability
- **Hidden record styling**: Muted background and dimmed title for hidden records

### Teaser View

A minimal card layout inspired by news/blog listings.

- **Clean design**: Title, date with calendar icon, and description excerpt (2-line clamp)
- **Status badges**: UID pill and hidden/visible indicator
- **Compact actions**: Visibility toggle, TYPO3 14 native contextual edit, and delete buttons
- **Hidden state**: Accent bar on hidden records
- **CSS `light-dark()`**: Native theme switching support

### Custom View Types

Adding a new view type requires **zero PHP** -- just TSconfig and an optional Fluid template:

```typoscript
mod.web_list.viewMode {
    allowed = list,grid,compact,teaser,timeline

    types.timeline {
        label = Timeline
        icon = actions-calendar
        template = TimelineView
        templateRootPath = EXT:my_sitepackage/Resources/Private/Backend/Templates/
        css = EXT:my_sitepackage/Resources/Public/Css/timeline.css
        displayColumns = label,datetime,teaser
        columnsFromTCA = 0
    }
}
```

You can also reuse built-in templates without creating a new one:

```typoscript
# Address book using the compact table layout with custom columns
mod.web_list.viewMode.types.addressbook {
    label = Address Book
    icon = actions-user
    template = CompactView
    displayColumns = name,email,phone,company,city
    columnsFromTCA = 0
    itemsPerPage = 500
}
```

Restrict a view type to a specific page using TSconfig conditions:

```typoscript
# Timeline only on the Events page
[page["uid"] == 42]
    mod.web_list.viewMode.allowed = list,timeline
    mod.web_list.viewMode.default = timeline
[end]
```

See [Custom View Types](Documentation/Developer/CustomViewTypes.rst) for full documentation with step-by-step guide, real-world examples, and template variable reference.

For ready-to-use examples, install the companion extension [Records List Examples](https://github.com/dirnbauer/typo3-records-list-examples) which adds 6 additional view types (Timeline, Catalog, Address Book, Event List, Gallery, Dashboard) with zero PHP.

## Configuration

### Per-Table Field Configuration

Configure which fields appear in cards for each table:

```typoscript
mod.web_list.gridView.table.tx_news_domain_model_news {
    titleField = title
    descriptionField = teaser
    imageField = fal_media
    preview = 1
}
```

| Option             | Description                             | Default          |
| ------------------ | --------------------------------------- | ---------------- |
| `titleField`       | Field used as card title                | TCA `ctrl.label` |
| `descriptionField` | Field shown as description text         | *(none)*         |
| `imageField`       | FAL field for thumbnail images          | *(none)*         |
| `preview`          | Enable thumbnail rendering (`1` or `0`) | `1`              |

### Record Filters

Filters are configured in Page TSconfig and share TYPO3's record-list query
setup. In LIVE and in the classic List View they are applied directly in the
query layer. In workspace mode, Grid, Compact, Teaser, and custom view modes
first fetch candidate rows, apply `BackendUtility::workspaceOL()`, and then
evaluate the active search term and filters against the effective workspace
row. Workspace-only text changes, visibility changes, dates, select values,
and category assignments are therefore searchable before they are published.

The filter toggle appears in **View > Show filters** after a table has been
selected. The visibility setting is stored in the user's Records module
settings, like the core search and clipboard toggles. If filters or search
return no records, the selected table section and filter panel stay visible
with an empty-result notice.

```typoscript
mod.web_list.filters {
    enabled = 1
    autoDefaults = title,dateRange,hidden,categories

    table.tx_news_domain_model_news {
        fields = title,dateRange,categories,topNews,hidden

        title {
            type = text
            fields = title,teaser
        }

        dateRange {
            field = datetime
        }

        topNews {
            type = boolean
            field = istopnews
            label = Top News
            falseLabel = No
            trueLabel = Yes
        }
    }
}
```

Generic defaults are TCA-derived and skip filters whose backing fields do not
exist, so newly added tables usually work without table-specific TSconfig.

Built-in aliases:

| Alias | Type | Behavior |
|-------|------|----------|
| `title`, `label` | `text` | Searches the TCA label field or configured `fields`. |
| `hidden` | `boolean` | Uses TCA `ctrl.enablecolumns.disabled`. |
| `date`, `dateRange` | `dateRange` | Uses common date fields or `ctrl.crdate`. |
| `category`, `categories` | `category` | Uses TYPO3 many-to-many category fields. |

Category options show the default-language category once and append available
translations in brackets. Selecting a category matches the default category UID
and all translated category UIDs. In workspaces, category matching also checks
the workspace version UID used by TYPO3's MM relations.

See [Record filters](Documentation/Configuration/Filters.rst) for the full
configuration reference.

### View Mode Settings

```typoscript
# Set the default view mode
mod.web_list.viewMode.default = grid

# Restrict available view modes
mod.web_list.viewMode.allowed = list,grid,compact

# Set default modes for single-table views
mod.web_list.viewMode.table {
    pages = grid
    tx_news_domain_model_news = teaser
    tt_content = compact
}

# Configure grid column count (2-6)
mod.web_list.gridView.cols = 4
```

### Sorting Modes

Tables with a TCA `sortby` field support two sorting modes:

- **Manual** (default): Drag-and-drop reordering using the TCA sorting field
- **Field**: Sort by any column with ascending/descending direction

A segmented toggle control switches between modes. When in field sorting mode,
a dropdown lets users pick the sort column.

## Accessibility

The extension is built with **WCAG 2.1** compliance throughout:

- **Keyboard drag-and-drop**: Full keyboard support for reordering -- press Space/Enter to grab, arrow keys to move, Space/Enter to drop, Escape to cancel
- **Screen reader announcements**: ARIA live regions announce drag state, position ("Position 3 of 12"), and drop confirmation
- **Semantic markup**: `role="listbox"` on grids, `role="option"` on cards, `role="button"` on drag handles, `aria-grabbed` state
- **Focus management**: Visible focus indicators, `focus-visible` support, proper tab order
- **Hidden instructions**: Screen-reader-only instructions for keyboard drag-and-drop

## Workspace Support

The extension is fully workspace-aware on TYPO3 v14:

- Queries use `WorkspaceRestriction` keyed to the workspace reported by
  the `Context` aspect (`getPropertyFromAspect('workspace', 'id')`), not
  the legacy `$BE_USER->workspace` property.
- Every fetched row is overlaid with `BackendUtility::workspaceOL()`
  before enrichment, so the view always reflects the workspace version
  of a record.
- Search and configured filters in alternative view modes are evaluated after
  the workspace overlay when a workspace is active. This avoids filtering out
  live rows before TYPO3 can replace them with draft rows.
- `t3ver_state` is mapped to colour-coded visual indicators. The legacy
  `t3ver_state = 3` branch was removed in TYPO3 v11 and is not handled.

| State    | `t3ver_state`       | Color  | Header Style                         |
| -------- | ------------------- | ------ | ------------------------------------ |
| New      | 1                   | Blue   | Blue background + left border        |
| Modified | 0 + `t3ver_oid > 0` | Purple | Purple background + left border      |
| Moved    | 4                   | Cyan   | Cyan background + left border        |
| Deleted  | 2                   | Red    | Red background + strikethrough title |

See [Documentation/Developer/Workspaces.rst](Documentation/Developer/Workspaces.rst)
for the full API reference and known FAL limitations.

## Dark Mode

All view modes fully support TYPO3's dark mode with design tokens:

- Explicit `[data-color-scheme="dark"]` support
- System preference via `prefers-color-scheme: dark`
- CSS custom properties for all colors, shadows, and borders
- Workspace state colors adapted for dark backgrounds

## CSS Architecture

The extension uses a **base + view-specific** CSS pattern:

```
base.css            ← Loaded for ALL view modes (always first)
├── grid-view.css   ← Grid-only: cards, drag-drop, field types
├── compact-view.css← Compact-only: table, sticky columns, zebra striping
├── teaser-view.css ← Teaser-only: teaser cards, badges, meta
└── view-mode-toggle.css ← DocHeader toggle buttons (always loaded)
```

**base.css** contains shared components that every view mode needs:

| Component           | Description                                                       |
| ------------------- | ----------------------------------------------------------------- |
| Recordlist heading  | Table header bar with title and action buttons                    |
| Pagination          | Core list view navigation (record range, page input, nav buttons) |
| Sorting mode toggle | Segmented control for manual/field sorting modes                  |
| Sorting dropdown    | Field sorting dropdown and disabled state                         |

**View-specific files** only contain styles unique to that view mode -- design tokens, card/row layouts, field type formatting, and responsive overrides. They reference TYPO3 CSS variables with hardcoded fallbacks for standalone use.

`base.css` is automatically prepended by `ViewTypeRegistry::getCssFiles()`. Custom view types registered via TSconfig or PSR-14 events also receive `base.css` automatically.

## Architecture

### Services

| Service | Purpose |
|---------|---------|
| `RecordGridDataProvider` | Enriches records with thumbnails, icons, workspace state, and language info |
| `GridConfigurationService` | Parses TSconfig for per-table field configuration |
| `RecordFilterConfigurationService` | Resolves TSconfig/TCA filter definitions |
| `RecordFilterQueryService` | Applies active filters to record-list queries and post-overlay workspace rows |
| `RecordFilterViewDataFactory` | Builds Fluid-ready filter panel data |
| `ThumbnailService` | Resolves FAL references and generates thumbnail URLs |
| `ViewModeResolver` | Determines active view mode from request, user preference, or TSconfig |
| `ViewTypeRegistry` | Registry for built-in and custom view types |
| `MiddlewareDiagnosticService` | Detects middleware interference with view rendering |

### PSR-14 Events

| Event / Listener | Purpose |
|------------------|---------|
| `RegisterViewModesEvent` | Register, remove, or modify custom view types |
| `GridViewButtonBarListener` | Injects view mode toggle buttons into the DocHeader |
| `RecordFilterButtonBarListener` | Adds the **Show filters** View menu entry for selected tables |
| `RecordFilterAdditionalContentListener` | Renders filters above the classic List View |
| `RecordFilterQueryListener` | Applies filters through TYPO3's record-list query event |
| `GridViewRecordActionsListener` | Observes TYPO3 record-list action events and keeps helper access for custom templates |

### Sanitization

| Item                                                             | Purpose                                                                    |
| ---------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `BackendFragmentSanitizerBuilder`                                | TYPO3 core htmlSanitizer preset for backend button/component fragments     |
| `f:sanitize.html(build: 'records-list-types-backend-fragments')` | Sanitizes TYPO3/core-generated backend fragments before rendering in Fluid |

### Template systematic

Built-in templates follow a consistent rendering split:

- **Structured data rendered directly in Fluid** for extension-owned UI such as:
  - `table.tableHeading`
  - `table.sortingDropdown`
  - `table.sortingModeToggle`
  - `table.sortableColumnHeaders`
- **TYPO3/core-generated backend fragments sanitized before output** for:
  - `table.actionButtons.*`
  - `table.multiRecordSelectionActionsHtml`
  - event-driven record action fragments

This keeps custom markup out of PHP string assembly wherever practical and
reserves sanitization for backend fragments that still originate from TYPO3
component APIs.

### JavaScript Modules

| Module | Purpose |
|--------|---------|
| `GridViewActions.js` | Lit custom element for drag-and-drop, record actions, sorting, pagination input, scroll shadows, ARIA announcements, and client-side search |
| `RecordFilters.js` | Category filter menu behavior and selected-label updates |

### AJAX Routes

| Route                              | Purpose                               |
| ---------------------------------- | ------------------------------------- |
| `records_list_types_set_view_mode` | Persist user's view mode preference   |
| `records_list_types_get_view_mode` | Retrieve current view mode preference |

## Security

The extension follows TYPO3 security best practices:

- **SQL Injection Prevention**: All queries use TYPO3's QueryBuilder with parameterized named parameters and type casting
- **CSRF Protection**: AJAX endpoints use TYPO3's built-in token handling
- **Access Control**: Full integration with TYPO3's backend user permissions and workspace restrictions
- **Input Validation**: View mode, table names, UIDs, and sort parameters are validated and sanitized
- **XSS Prevention**: Fluid templates auto-escape by default; TYPO3 core `f:sanitize.html` is used for trusted backend-generated fragments

## Testing

The extension includes a comprehensive test suite:

- **Unit Tests**: Services, events, controllers, and event listeners
- **Functional Tests**: Database integration with fixtures
- **Architecture Tests**: Dependency rules via PHPat
- **Code Quality**: PHPStan level max, PHP-CS-Fixer (PER-CS2.0), strict types

### CI/CD (GitHub Actions)

- PHPStan analysis (level max with PHPat architecture rules)
- PHP-CS-Fixer (auto-fix)
- Unit tests (PHP 8.3 + 8.4)
- Functional tests (PHP 8.3 + 8.4, MySQL 8.0)
- Coverage reports (Clover XML)

## Localization

- **English** (default) and **German** translations included
- All UI labels, action names, drag-and-drop messages, and sorting controls are translatable via XLIFF

## File Structure

```
records_list_types/
├── Classes/
│   ├── Controller/
│   │   ├── Ajax/ViewModeController.php        # AJAX preference persistence
│   │   └── RecordListController.php           # Main controller (XClass)
│   ├── Event/
│   │   └── RegisterViewModesEvent.php         # PSR-14: custom view registration
│   ├── EventListener/
│   │   ├── GridViewButtonBarListener.php      # Injects toggle buttons
│   │   ├── GridViewQueryListener.php          # Query modification bridge
│   │   ├── GridViewRecordActionsListener.php  # Record action event bridge
│   │   ├── RecordFilterButtonBarListener.php  # Show filters menu entry
│   │   ├── RecordFilterAdditionalContentListener.php
│   │   └── RecordFilterQueryListener.php      # Filter query integration
│   ├── Pagination/
│   │   └── DatabasePaginator.php              # Paginator for pre-fetched database records
│   ├── Service/
│   │   ├── GridConfigurationService.php       # TSconfig parsing
│   │   ├── MiddlewareDiagnosticService.php    # Middleware diagnostics
│   │   ├── RecordFilterConfigurationService.php
│   │   ├── RecordFilterQueryService.php
│   │   ├── RecordFilterStateService.php
│   │   ├── RecordFilterViewDataFactory.php
│   │   ├── RecordGridDataProvider.php         # Record fetching & enrichment
│   │   ├── ThumbnailService.php               # FAL image processing
│   │   ├── ViewModeResolver.php               # View mode determination
│   │   └── ViewTypeRegistry.php               # View type management
│   ├── Html/
│   │   └── BackendFragmentSanitizerBuilder.php # TYPO3 htmlSanitizer preset for backend fragments
│   ├── Utility/
│   │   └── ArrayUtility.php                  # Typed TSconfig/request array boundary helpers
│   └── ViewHelpers/
│       └── RecordActionsViewHelper.php        # Access to cached record action fragments
├── Configuration/
│   ├── Backend/AjaxRoutes.php                 # AJAX route definitions
│   ├── Icons.php                              # Icon registration
│   ├── JavaScriptModules.php                  # ES module registration
│   ├── page.tsconfig                          # Default TSconfig
│   └── Services.yaml                          # DI and event listener config
├── Documentation/                             # RST + Markdown documentation
├── Resources/
│   ├── Private/
│   │   ├── Language/                          # XLIFF translations (en, de)
│   │   ├── Layouts/
│   │   ├── Partials/
│   │   │   ├── Card.html                      # Grid view card partial
│   │   │   ├── CompactRow.html                # Compact view row partial
│   │   │   ├── EmptyRecordsNotice.html        # Search/filter empty state
│   │   │   ├── Pagination.html                # Pagination navigation (Core list view style)
│   │   │   ├── RecordFilters.html             # Configurable filter panel
│   │   │   ├── RecordActions.html             # Legacy custom-template record action partial
│   │   │   ├── SortableColumnHeader.html      # Structured sortable table header
│   │   │   ├── SortingDropdown.html           # Structured field sorting dropdown
│   │   │   ├── SortingModeToggle.html         # Structured manual/field sorting toggle
│   │   │   ├── TableHeading.html              # Structured table heading
│   │   │   ├── TeaserCard.html                # Teaser view card partial
│   │   │   └── ViewSwitcher.html              # View mode toggle buttons
│   │   └── Templates/
│   │       ├── CompactView.html               # Compact view template
│   │       ├── GenericView.html               # Generic view (base for custom types)
│   │       ├── GridView.html                  # Grid view template
│   │       └── TeaserView.html                # Teaser view template
│   └── Public/
│       ├── Css/
│       │   ├── base.css                       # Shared styles (heading, pagination, sorting)
│       │   ├── grid-view.css                  # Grid view styles + design tokens
│       │   ├── compact-view.css               # Compact view styles
│       │   ├── teaser-view.css                # Teaser view styles
│       │   └── view-mode-toggle.css           # Toggle button styles
│       ├── Icons/
│       │   └── Extension.svg                  # Extension icon
│       └── JavaScript/
│           ├── GridViewActions.js             # Lit actions component, drag-drop, actions, sorting
│           └── RecordFilters.js               # Category filter interaction
├── Tests/
│   ├── Unit/                                  # Unit tests
│   ├── Functional/                            # Functional tests with fixtures
│   └── Architecture/                          # PHPat architecture tests
├── composer.json
├── ext_emconf.php
└── ext_localconf.php
```

## Known Limitations

- **Workspace FAL limitation.** TYPO3 does not version physical files. Thumbnails rendered in the Grid and Teaser views always reflect the live binary, regardless of the active workspace. When preparing workspace content that changes imagery, upload new files with unique names instead of overwriting existing ones. See [Documentation/Developer/Workspaces.rst](Documentation/Developer/Workspaces.rst) for the full rationale.
- **Drag-and-drop accessibility has limited assistive technology coverage.** Keyboard-based drag-and-drop is implemented with ARIA attributes and live region announcements, but has primarily been tested with keyboard navigation in modern browsers. Testing with dedicated screen readers (NVDA, JAWS, VoiceOver) has been limited. If drag-and-drop reordering is critical for users relying on assistive technology, the standard List View provides a more thoroughly tested fallback. Please [report accessibility barriers on GitHub](https://github.com/dirnbauer/typo3-records-list-types/issues).

## Documentation

Comprehensive documentation is available in the `Documentation/` folder:

| Document | Description |
|----------|-------------|
| [Documentation](Documentation/Index.rst) | Main RST documentation |
| [Usage](Documentation/Usage/Index.rst) | Daily editor workflows |
| [Configuration](Documentation/Configuration/Index.rst) | TSconfig reference |
| [Record filters](Documentation/Configuration/Filters.rst) | Filter configuration |
| [Architecture](Documentation/Developer/Architecture.rst) | Technical architecture |
| [Custom view types](Documentation/Developer/CustomViewTypes.rst) | Creating custom view types |
| [Extending](Documentation/Developer/Extending.rst) | Extension points and PSR-14 events |
| [Records List Examples](https://github.com/dirnbauer/typo3-records-list-examples) | Companion extension with example view types |

## License

GPL-2.0-or-later

## Author

**Webconsulting** -- office@webconsulting.at
