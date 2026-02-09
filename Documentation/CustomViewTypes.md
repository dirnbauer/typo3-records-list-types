# Custom View Types

Adding a new view type requires **zero PHP**. You provide TSconfig + a Fluid template, and the extension handles the rest: record fetching, pagination, sorting, action buttons, and asset loading.

## Quick Start: Add a View Type in 3 Steps

### Step 1: Register the view type (Page TSconfig)

```tsconfig
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

### Step 2: Create the Fluid template

Copy `GenericView.html` from the extension and customize it:

```
EXT:my_sitepackage/Resources/Private/Backend/Templates/TimelineView.html
```

```html
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:core="http://typo3.org/ns/TYPO3/CMS/Core/ViewHelpers"
      data-namespace-typo3-fluid="true">

<div class="timeline-container">
    <f:for each="{tableData}" as="table">
        <div class="recordlist" id="t3-table-{table.tableName}">
            <div class="recordlist-heading">
                <div class="recordlist-heading-row">
                    <div class="recordlist-heading-title">
                        <a href="{table.singleTableUrl}">
                            <span class="fw-bold">{table.tableLabel}</span>
                            <span>({table.recordCount})</span>
                        </a>
                    </div>
                    <div class="recordlist-heading-actions">
                        <f:if condition="{table.actionButtons.newRecordButton}">
                            <f:format.raw>{table.actionButtons.newRecordButton}</f:format.raw>
                        </f:if>
                    </div>
                </div>
            </div>

            <f:render partial="Pagination" arguments="{paginator: table.paginator, pagination: table.pagination, paginationUrl: table.paginationUrl, tableName: table.tableName, position: 'top'}" />

            <div class="timeline-list">
                <f:for each="{table.records}" as="record">
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <strong>{record.title}</strong>
                            <f:for each="{record.displayValues}" as="field">
                                <f:if condition="{field.type} == 'datetime'">
                                    <span class="text-muted small ms-2">{field.formatted}</span>
                                </f:if>
                            </f:for>
                        </div>
                    </div>
                </f:for>
            </div>

            <f:render partial="Pagination" arguments="{paginator: table.paginator, pagination: table.pagination, paginationUrl: table.paginationUrl, tableName: table.tableName, position: 'bottom'}" />
        </div>
    </f:for>
</div>

</html>
```

### Step 3: Add CSS (optional)

```css
/* EXT:my_sitepackage/Resources/Public/Css/timeline.css */
.timeline-list { padding: 1rem; }
.timeline-item { display: flex; gap: 1rem; padding: 0.5rem 0; border-bottom: 1px solid #e5e7eb; }
.timeline-marker { width: 12px; height: 12px; border-radius: 50%; background: #3b82f6; margin-top: 4px; flex-shrink: 0; }
.timeline-content { flex: 1; }
```

That's it. No PHP, no classes, no dependency injection. The new "Timeline" view appears in the view switcher and works with pagination, sorting, search, and all record actions.

## View Type on a Specific Page Only

Use TSconfig conditions to restrict a view type to certain pages:

```tsconfig
# Timeline view only on the "Events" page (uid=42)
[page["uid"] == 42]
    mod.web_list.viewMode {
        allowed = list,timeline
        default = timeline

        types.timeline {
            label = Event Timeline
            icon = actions-calendar
            template = TimelineView
            templateRootPath = EXT:my_sitepackage/Resources/Private/Backend/Templates/
            css = EXT:my_sitepackage/Resources/Public/Css/timeline.css
            displayColumns = label,datetime,teaser
            columnsFromTCA = 0
            itemsPerPage = 50
        }
    }
[end]
```

This gives the Events page a "Timeline" toggle while all other pages keep the default views. Setting `default = timeline` makes it the default when editors first visit that page.

### More page-scoping examples

```tsconfig
# Grid-only for media folders (doktype 254 = sysfolder)
[page["doktype"] == 254]
    mod.web_list.viewMode.default = grid
    mod.web_list.viewMode.allowed = list,grid
[end]

# Compact view for system records pages
[page["module"] == "system"]
    mod.web_list.viewMode.default = compact
    mod.web_list.viewMode.allowed = list,compact
[end]

# Custom view for an entire page tree (pid=100 and its children)
[page["uid"] == 100 || page["pid"] == 100]
    mod.web_list.viewMode {
        allowed = list,grid,catalog
        default = catalog
        types.catalog {
            label = Product Catalog
            icon = actions-viewmode-tiles
            template = CatalogView
            templateRootPath = EXT:my_sitepackage/Resources/Private/Backend/Templates/
            css = EXT:my_sitepackage/Resources/Public/Css/catalog.css
            columnsFromTCA = 1
        }
    }
[end]
```

## Real-World Examples

### Product Catalog (Grid with large thumbnails)

A visual overview for browsing products, showing images prominently:

```tsconfig
mod.web_list.viewMode {
    allowed = list,grid,catalog
    types.catalog {
        label = Product Catalog
        icon = actions-viewmode-tiles
        template = CatalogView
        templateRootPath = EXT:my_sitepackage/Resources/Private/Backend/Templates/
        css = EXT:my_sitepackage/Resources/Public/Css/catalog.css
        displayColumns = label,teaser
        columnsFromTCA = 0
        itemsPerPage = 24
    }
}

mod.web_list.gridView.table.tx_myshop_domain_model_product {
    titleField = name
    descriptionField = short_description
    imageField = images
    preview = 1
}
```

### Address Book (Compact with specific columns)

A dense list showing key contact information at a glance:

```tsconfig
mod.web_list.viewMode {
    allowed = list,compact,addressbook
    types.addressbook {
        label = Address Book
        icon = actions-address
        template = CompactView
        displayColumns = name,email,phone,company,city
        columnsFromTCA = 0
        itemsPerPage = 500
    }
}
```

Note: this reuses the built-in `CompactView` template but with custom columns -- no new template needed.

### Event Calendar List

A view optimized for upcoming events, sorted by date:

```tsconfig
[page["uid"] == 55]
    mod.web_list.viewMode {
        allowed = list,eventlist
        default = eventlist
        types.eventlist {
            label = Event List
            icon = actions-calendar
            template = TeaserView
            displayColumns = label,datetime,teaser
            columnsFromTCA = 0
            itemsPerPage = 30
        }
    }
[end]
```

This reuses the built-in `TeaserView` template -- the date field is automatically detected and displayed with a calendar icon.

### News Dashboard (Teaser with all fields from TCA)

An editorial view showing all user-selected columns in teaser format:

```tsconfig
mod.web_list.viewMode.types.newsdash {
    label = News Dashboard
    icon = content-news
    template = TeaserView
    columnsFromTCA = 1
    itemsPerPage = 20
}
mod.web_list.viewMode.allowed = list,grid,newsdash
```

Setting `columnsFromTCA = 1` means editors can choose which columns appear via TYPO3's "Show columns" selector.

### Minimal List (no pagination)

A simple list that shows all records without pagination:

```tsconfig
mod.web_list.viewMode.types.fulllist {
    label = Full List
    icon = actions-viewmode-list
    template = CompactView
    columnsFromTCA = 1
    itemsPerPage = 0
}
mod.web_list.viewMode.allowed = list,fulllist
```

Setting `itemsPerPage = 0` disables pagination entirely.

## Reusing Built-in Templates

You don't need to create a custom template for every view type. The built-in templates work with any configuration:

| Template | Best for |
|----------|----------|
| `GridView` | Visual content with images (products, team members, portfolio) |
| `CompactView` | Dense data (addresses, system records, logs, settings) |
| `TeaserView` | Content previews (news, blog posts, events, press releases) |
| `GenericView` | Starting point for fully custom layouts |

Example: a "Photo Gallery" view using the built-in `GridView` template with custom per-table config:

```tsconfig
mod.web_list.viewMode.types.gallery {
    label = Photo Gallery
    icon = actions-image
    template = GridView
    columnsFromTCA = 0
    displayColumns = label
    itemsPerPage = 48
}

mod.web_list.gridView.table.sys_file_metadata {
    titleField = title
    imageField = file
    preview = 1
}
```

## Built-in View Types

| ID | Label | Description |
|----|-------|-------------|
| `list` | List View | Standard TYPO3 table view (handled by core) |
| `grid` | Grid View | Card-based grid with thumbnails and field display |
| `compact` | Compact View | Single-line table with sortable columns |
| `teaser` | Teaser List | Minimal cards with title, date, and teaser |

## Configuration Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `label` | string | *required* | Display name in the view switcher (supports `LLL:`) |
| `icon` | string | *required* | TYPO3 icon identifier |
| `description` | string | *(empty)* | Tooltip description |
| `template` | string | `<TypeId>View` | Fluid template name (without `.html`) |
| `partial` | string | `Card` | Default partial for record cards |
| `templateRootPath` | string | *(extension default)* | Custom template path |
| `partialRootPath` | string | *(extension default)* | Custom partial path |
| `layoutRootPath` | string | *(extension default)* | Custom layout path |
| `css` | string | *(none)* | CSS file to include (`EXT:` syntax) |
| `js` | string | *(none)* | JavaScript module (`@vendor/module.js` syntax) |
| `columnsFromTCA` | bool | `1` | Use editor's column selection from TCA |
| `displayColumns` | string | *(empty)* | Comma-separated field list |
| `itemsPerPage` | int | `100` | Records per page (`0` = no pagination) |

### Column Display: `columnsFromTCA` vs `displayColumns`

These two options control which fields appear in your view.

**`columnsFromTCA = 1`** (default) -- the view uses the same column selection as the standard TYPO3 List View, resolved in this order:

1. **Editor's "Show columns" selection** -- editors click the column selector button in the List View header to pick visible columns. These are stored per-user per-table. Your custom view automatically respects them.
2. **TSconfig `showFields`** -- fallback if the editor hasn't chosen columns (`mod.web_list.table.<table>.showFields`).
3. **TCA `searchFields`** -- fallback if no TSconfig is set (the table's most relevant fields).
4. **Label field only** -- final fallback: just the record title field.

This is ideal for **editor-controlled views** where users pick their own columns.

**`columnsFromTCA = 0`** -- the view ignores the editor's column selection and uses the explicit `displayColumns` list instead. The template always receives exactly the fields you specified.

This is ideal for **fixed-layout views** where the template is designed for specific fields.

**How the built-in views use it:**

| View | `columnsFromTCA` | `displayColumns` | Result |
|------|:-:|---|---|
| Grid | `1` | *(not set)* | Editors choose columns via selector; cards show those fields |
| Compact | `1` | *(not set)* | Editors choose columns; table rows show those fields |
| Teaser | `0` | `label,datetime,teaser` | Always title + date + description (template expects exactly these) |

**Example -- fixed columns:**

```tsconfig
mod.web_list.viewMode.types.contacts {
    label = Contact List
    template = CompactView
    columnsFromTCA = 0
    displayColumns = name,email,phone,company
}
```

Always shows exactly name, email, phone, company -- regardless of what the editor has selected in the column selector.

**Example -- editor-controlled columns:**

```tsconfig
mod.web_list.viewMode.types.dashboard {
    label = Dashboard
    template = GridView
    columnsFromTCA = 1
}
```

Editors can click "Show columns" to pick which fields appear on the cards.

### Special Column Names

When using `displayColumns`, these names are resolved automatically:

| Name | Resolves to |
|------|-------------|
| `label` | TCA `ctrl.label` field (the record title) |
| `datetime` | First date field found (`datetime`, `date`, `starttime`, or `crdate`) |
| `teaser` | First description field found (`teaser`, `abstract`, `description`, `bodytext`, `short`) |

## Template Variables

Your template receives these variables:

| Variable | Description |
|----------|-------------|
| `pageId` | Current page ID |
| `tableData` | Array of table data (see below) |
| `currentTable` | Filtered table name (or empty) |
| `searchTerm` | Current search term |
| `viewMode` | View type identifier |
| `viewConfig` | View type configuration array |
| `middlewareWarning` | Middleware diagnostic warning (if any) |
| `forceListViewUrl` | URL to force list view (if middleware issue) |

Each item in `tableData`:

| Key | Description |
|-----|-------------|
| `tableName` | Database table name |
| `tableLabel` | Human-readable table label |
| `tableIcon` | TYPO3 icon identifier |
| `records` | Array of enriched records |
| `recordCount` | Total number of records |
| `actionButtons` | Rendered action button HTML |
| `sortingDropdownHtml` | Sorting dropdown HTML |
| `sortingModeToggleHtml` | Manual/field sorting toggle HTML |
| `sortableColumnHeaders` | Sortable column header HTML (for table views) |
| `singleTableUrl` | URL to filter by this table |
| `clearTableUrl` | URL to show all tables |
| `displayColumns` | Columns to display |
| `sortField`, `sortDirection` | Current sorting state |
| `canReorder` | Whether drag-drop is enabled |
| `lastRecordUid` | Last record UID (for drag-drop end zone) |
| `paginator` | `DatabasePaginator` (TYPO3 Core PaginatorInterface) |
| `pagination` | `SlidingWindowPagination` (TYPO3 Core PaginationInterface) |
| `paginationUrl` | Base URL for pagination links |

Each record in `records`:

| Key | Description |
|-----|-------------|
| `uid`, `pid` | Record identifiers |
| `tableName` | Table name |
| `title` | Label field value |
| `iconIdentifier` | Record icon |
| `hidden` | Hidden status |
| `rawRecord` | Full database row |
| `displayValues` | Array of formatted field values |

Each field in `displayValues`:

| Key | Description |
|-----|-------------|
| `field` | Field name |
| `label` | Translated label |
| `type` | Field type (`text`, `datetime`, `boolean`, `number`, `relation`, `link`) |
| `isLabelField` | Whether this is the title field |
| `raw` | Raw database value |
| `formatted` | Formatted display value |
| `isEmpty` | Whether value is empty |

## Assets: CSS, JavaScript, Images

### What is loaded automatically

Every view type automatically receives:

| Asset | Source | Purpose |
|-------|--------|---------|
| `base.css` | Extension | Shared heading, pagination, sorting styles |
| `GridViewActions.js` | Extension | Drag-drop, record actions, pagination input, sorting, search |
| `column-selector-button.js` | TYPO3 Core | Column selector web component |

You only need to add your own assets for view-specific styling or behavior.

### CSS

Add a CSS file via the `css` option. It is loaded **after** `base.css`, so you can use all shared styles and override them if needed:

```tsconfig
mod.web_list.viewMode.types.kanban {
    css = EXT:my_sitepackage/Resources/Public/Css/kanban.css
}
```

Your CSS file can reference TYPO3 CSS variables for automatic dark mode support:

```css
.kanban-column {
    background: var(--typo3-component-bg, #fff);
    border: 1px solid var(--typo3-component-border-color, #d4d4d8);
    color: var(--typo3-text-color-base, #18181b);
}
```

### JavaScript

Add custom JavaScript modules via the `js` option. Your module is loaded alongside the base `GridViewActions.js`:

```tsconfig
mod.web_list.viewMode.types.kanban {
    js = @my-sitepackage/kanban-board.js
}
```

Register the ES module path in your extension's `Configuration/JavaScriptModules.php`:

```php
<?php

return [
    'imports' => [
        '@my-sitepackage/' => 'EXT:my_sitepackage/Resources/Public/JavaScript/',
    ],
];
```

Your module can use TYPO3 backend APIs:

```javascript
// Resources/Public/JavaScript/kanban-board.js
import Notification from '@typo3/backend/notification.js';

class KanbanBoard {
    constructor() {
        document.querySelectorAll('.kanban-card').forEach(card => {
            card.addEventListener('dragend', () => {
                Notification.success('Moved', 'Card moved to new column');
            });
        });
    }
}

new KanbanBoard();
```

### Images and Icons

Static images (logos, illustrations) can be referenced directly in templates using `EXT:` paths:

```html
<img src="{f:uri.resource(path: 'Icons/my-icon.svg', extensionName: 'my_sitepackage')}" alt="" />
```

For record icons and view switcher icons, use TYPO3's icon registry. Register custom icons in `Configuration/Icons.php`:

```php
<?php

return [
    'my-kanban-icon' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => 'EXT:my_sitepackage/Resources/Public/Icons/kanban.svg',
    ],
];
```

Then reference it in TSconfig:

```tsconfig
mod.web_list.viewMode.types.kanban.icon = my-kanban-icon
```

### Complete file structure for a custom view type

```
my_sitepackage/
├── Configuration/
│   ├── Icons.php                          # Custom icon registration (optional)
│   ├── JavaScriptModules.php              # ES module paths (only if using js)
│   └── TsConfig/
│       └── Page/
│           └── mod.tsconfig               # View type TSconfig
├── Resources/
│   ├── Private/
│   │   └── Backend/
│   │       ├── Templates/
│   │       │   └── KanbanView.html        # Main Fluid template
│   │       └── Partials/
│   │           └── KanbanCard.html        # Card partial (optional)
│   └── Public/
│       ├── Css/
│       │   └── kanban.css                 # View-specific styles
│       ├── JavaScript/
│       │   └── kanban-board.js            # Custom JS module (optional)
│       └── Icons/
│           └── kanban.svg                 # Custom icon (optional)
```

### Asset loading order

```
1. base.css                          ← Always (shared heading, pagination, sorting)
2. your-view.css                     ← Your css option
3. GridViewActions.js                ← Always (drag-drop, actions, pagination input)
4. column-selector-button.js         ← Always (TYPO3 column selector)
5. your-module.js                    ← Your js option
```

## Method 2: PSR-14 Event (for extensions)

If you're building a TYPO3 extension (not a sitepackage), use the PSR-14 event to register view types programmatically:

```php
use TYPO3\CMS\Core\Attribute\AsEventListener;
use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;

#[AsEventListener]
final class RegisterCustomViewListener
{
    public function __invoke(RegisterViewModesEvent $event): void
    {
        $event->addViewMode('kanban', [
            'label' => 'LLL:EXT:my_extension/Resources/Private/Language/locallang.xlf:viewMode.kanban',
            'icon' => 'actions-view-table-columns',
            'description' => 'Kanban board view',
            'template' => 'KanbanView',
            'css' => 'EXT:my_extension/Resources/Public/Css/kanban.css',
        ]);
    }
}
```

Templates and CSS work exactly the same way as with TSconfig registration.
