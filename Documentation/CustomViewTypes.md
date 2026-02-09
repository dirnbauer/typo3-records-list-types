# Custom View Types

The Records Grid View extension supports custom view types that can be registered via TSconfig. Each view type can have its own Fluid template, styling, and behavior.

## Built-in View Types

| ID | Label | Description |
|----|-------|-------------|
| `list` | List View | Standard TYPO3 table view (handled by core) |
| `grid` | Grid View | Card-based grid with thumbnails and field display |
| `compact` | Compact View | Single-line table with sortable columns |
| `teaser` | Teaser List | Minimal cards with title, date, and teaser |

## Registering Custom View Types

Add custom view types via Page TSconfig:

```tsconfig
mod.web_list.viewMode {
    # Make your view available
    allowed = list,grid,compact,teaser,myview

    types {
        myview {
            # Required: Display settings
            label = My Custom View
            icon = content-text
            description = Custom view for my content

            # Template configuration
            template = MyView
            partial = MyCard

            # Custom template paths (optional)
            templateRootPath = EXT:my_sitepackage/Resources/Private/Backend/Templates/
            partialRootPath = EXT:my_sitepackage/Resources/Private/Backend/Partials/

            # Assets (optional - base.css is loaded automatically)
            css = EXT:my_sitepackage/Resources/Public/Css/my-view.css
            js = @my-sitepackage/my-view.js

            # Column configuration
            columnsFromTCA = 1
            # OR specify exact columns:
            # displayColumns = title,datetime,teaser,categories
        }
    }
}
```

## Configuration Options

| Option | Type | Description |
|--------|------|-------------|
| `label` | string | Display name in the view switcher |
| `icon` | string | TYPO3 icon identifier |
| `description` | string | Tooltip description |
| `template` | string | Fluid template name (without .html) |
| `partial` | string | Default partial for record cards |
| `templateRootPath` | string | Custom template path |
| `partialRootPath` | string | Custom partial path |
| `layoutRootPath` | string | Custom layout path |
| `css` | string/array | CSS file(s) to include |
| `js` | string/array | JavaScript module(s) to load |
| `columnsFromTCA` | bool | Use user's column selection (default: true) |
| `displayColumns` | string | Comma-separated field list |
| `itemsPerPage` | int | Records per page (default: 100, compact: 300, 0 = no pagination) |

## Creating Templates

### 1. Copy the Generic Template

Copy `EXT:records_list_types/Resources/Private/Templates/GenericView.html` to your extension:

```
EXT:my_sitepackage/Resources/Private/Backend/Templates/MyView.html
```

### 2. Available Variables

Your template receives these variables:

```
pageId              - Current page ID
tableData           - Array of table data (see below)
currentTable        - Filtered table name (or empty)
searchTerm          - Current search term
viewMode            - View type identifier
viewConfig          - View type configuration
```

Each item in `tableData` contains:

```
tableName           - Database table name
tableLabel          - Human-readable label
tableIcon           - TYPO3 icon identifier
tableConfig         - Table configuration
records             - Array of enriched records
recordCount         - Number of records
actionButtons       - Rendered button HTML
singleTableUrl      - URL to filter by table
clearTableUrl       - URL to clear filter
displayColumns      - Columns to display
sortableFields      - Available sort fields
sortField           - Current sort field
sortDirection       - "asc" or "desc"
canReorder          - Whether drag-drop is enabled
paginator           - DatabasePaginator (TYPO3 Core PaginatorInterface)
pagination          - SlidingWindowPagination (TYPO3 Core PaginationInterface)
paginationUrl       - Base URL for pagination links
```

Each record in `records` contains:

```
uid                 - Record UID
pid                 - Page ID
tableName           - Table name
title               - Label field value
iconIdentifier      - Record icon
hidden              - Hidden status
rawRecord           - Full database row
displayValues       - Formatted field values (see below)
```

Each field in `displayValues`:

```
field               - Field name
label               - Translated label
type                - Field type (text, datetime, boolean, etc.)
isLabelField        - Is this the title field?
raw                 - Raw database value
formatted           - Formatted display value
isEmpty             - Whether value is empty
```

### 3. Example: News Teaser View

**TSconfig:**

```tsconfig
mod.web_list.viewMode {
    allowed = list,grid,newsteaser

    types {
        newsteaser {
            label = News Teaser
            icon = content-news
            description = News list with image and teaser
            template = NewsTeaserView
            partial = NewsTeaserCard
            templateRootPath = EXT:my_sitepackage/Resources/Private/Backend/Templates/
            partialRootPath = EXT:my_sitepackage/Resources/Private/Backend/Partials/
            css = EXT:my_sitepackage/Resources/Public/Css/news-teaser.css
            columnsFromTCA = 0
            displayColumns = title,datetime,teaser
        }
    }
}
```

**Template (NewsTeaserView.html):**

```html
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:core="http://typo3.org/ns/TYPO3/CMS/Core/ViewHelpers"
      data-namespace-typo3-fluid="true">

<div class="newsteaser-container">
    <f:for each="{tableData}" as="table">
        <div class="recordlist" id="t3-table-{table.tableName}">
            <!-- Header -->
            <div class="recordlist-heading">
                <div class="recordlist-heading-row">
                    <div class="recordlist-heading-title">
                        <core:icon identifier="{table.tableIcon}" size="small" />
                        <span class="fw-bold ms-1">{table.tableLabel}</span>
                        <span>({table.recordCount})</span>
                    </div>
                    <div class="recordlist-heading-actions">
                        <f:format.raw>{table.actionButtons.newRecordButton}</f:format.raw>
                    </div>
                </div>
            </div>
            
            <!-- Records -->
            <div class="newsteaser-list">
                <f:for each="{table.records}" as="record">
                    <f:render partial="NewsTeaserCard" arguments="{record: record}" />
                </f:for>
            </div>
        </div>
    </f:for>
</div>

</html>
```

**Partial (NewsTeaserCard.html):**

```html
<div class="newsteaser-card card mb-3">
    <div class="row g-0">
        <div class="col-md-3">
            <f:if condition="{record.thumbnailUrl}">
                <img src="{record.thumbnailUrl}" class="img-fluid rounded-start" alt="">
            </f:if>
        </div>
        <div class="col-md-9">
            <div class="card-body">
                <h5 class="card-title">{record.title}</h5>
                <f:for each="{record.displayValues}" as="field">
                    <f:if condition="{field.type} == 'datetime'">
                        <p class="card-text text-muted small">{field.formatted}</p>
                    </f:if>
                    <f:if condition="{field.type} == 'text' && !{field.isLabelField}">
                        <p class="card-text">{field.formatted}</p>
                    </f:if>
                </f:for>
            </div>
        </div>
    </div>
</div>
```

## Special Column Names

When using `displayColumns`, you can use these special names:

| Name | Resolves To |
|------|-------------|
| `label` | TCA ctrl.label field (title) |
| `datetime` | First date field found |
| `teaser` | First teaser/description field |

Example:

```tsconfig
displayColumns = label,datetime,teaser,categories
```

## Per-Table Configuration

Configure how each table should appear in grid/teaser views:

```tsconfig
mod.web_list.gridView.table {
    tx_news_domain_model_news {
        titleField = title
        descriptionField = teaser
        imageField = fal_media
        preview = 1
    }
}
```

## Adding Custom JavaScript

Register ES6 modules for custom interactivity:

```tsconfig
mod.web_list.viewMode.types.myview {
    js = @my-sitepackage/my-custom-view.js
}
```

Your module should be registered in `Configuration/JavaScriptModules.php`:

```php
return [
    'imports' => [
        '@my-sitepackage/' => 'EXT:my_sitepackage/Resources/Public/JavaScript/',
    ],
];
```

