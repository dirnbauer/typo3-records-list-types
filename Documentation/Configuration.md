# Configuration Reference

This document provides a complete reference for configuring the Records Grid View extension via TSconfig.

## Page TSconfig

All configuration is done under `mod.web_list`.

### Global Settings

```typoscript
mod.web_list {
    # Which views are available? Comma-separated: list, grid
    # Setting only "list" hides the Grid View toggle entirely
    # Default: list,grid
    allowedViews = list,grid

    gridView {
        # Default view when no user preference exists
        # Options: list, grid
        # Default: list
        default = list

        # Number of columns in the grid (Bootstrap row-cols-xl-*)
        # Options: 2, 3, 4, 5, 6
        # Default: 4
        cols = 4
    }
}
```

### Per-Table Configuration

Configure how each table appears in the Grid View:

```typoscript
mod.web_list.gridView.table {
    # Table name (e.g., tx_news_domain_model_news, pages, fe_users)
    tx_news_domain_model_news {
        # Field to use as card title
        # Default: TCA 'label' field
        titleField = title

        # Field to display in card body (optional)
        # Leave empty to hide description
        descriptionField = teaser

        # FAL field for card thumbnail (optional)
        # Must be a field of type 'file' with FAL configuration
        imageField = fal_media

        # Enable/disable thumbnail for this table
        # Set to 0 to always show a placeholder instead
        # Default: 1
        preview = 1
    }
}
```

## Common Configurations

### News Records

```typoscript
mod.web_list.gridView.table.tx_news_domain_model_news {
    titleField = title
    descriptionField = teaser
    imageField = fal_media
    preview = 1
}
```

### Frontend Users ("Face Book")

```typoscript
mod.web_list.gridView.table.fe_users {
    titleField = name
    descriptionField = email
    imageField = image
    preview = 1
}
```

### Pages

```typoscript
mod.web_list.gridView.table.pages {
    titleField = title
    descriptionField = abstract
    imageField = media
    preview = 1
}
```

### Content Elements

```typoscript
mod.web_list.gridView.table.tt_content {
    titleField = header
    descriptionField = bodytext
    imageField = image
    preview = 1
}
```

## Pagination

All alternative view modes (Grid, Compact, Teaser, and custom types) support
pagination using TYPO3's Core Pagination API (`SlidingWindowPagination`).
When the number of records exceeds the configured `itemsPerPage`, a
pagination bar is shown at the top and bottom of the record list.

### Global Default

```typoscript
mod.web_list.viewMode {
    # Default items per page for all view types (0 = no pagination)
    itemsPerPage = 100
}
```

### Per-Type Override

Built-in defaults: Grid = 100, Compact = 300, Teaser = 100.

```typoscript
mod.web_list.viewMode.types {
    # Grid view: 100 records per page (default)
    grid.itemsPerPage = 100

    # Compact view: 300 records per page (denser layout)
    compact.itemsPerPage = 300

    # Teaser view: 100 records per page (default)
    teaser.itemsPerPage = 100

    # Custom view type
    myview.itemsPerPage = 50

    # Disable pagination for a specific view
    grid.itemsPerPage = 0
}
```

### Resolution Order

1. Per-type TSconfig: `mod.web_list.viewMode.types.<type>.itemsPerPage`
2. Global TSconfig: `mod.web_list.viewMode.itemsPerPage`
3. Built-in default: `100` (or `300` for compact)

## User TSconfig

Control Grid View behavior per user or user group.

### Force Specific View

Disable the toggle and force a specific view:

```typoscript
options.layout.records {
    # Force a specific view, disabling the toggle switch
    # Useful for simplified "Editor" groups
    # Options: list, grid
    forceView = grid
}
```

### Disable Grid View for User Group

```typoscript
# In User TSconfig for the group
mod.web_list.allowedViews = list
```

## Advanced Configuration

### Disable Grid View for Specific Pages

```typoscript
# In Page TSconfig for a specific page tree
[page["uid"] == 123 || page["pid"] == 123]
    mod.web_list.allowedViews = list
[end]
```

### Different Column Counts per Page Type

```typoscript
# More columns for media folders
[page["doktype"] == 254]
    mod.web_list.gridView.cols = 6
[end]

# Fewer columns for complex records
[page["module"] == "events"]
    mod.web_list.gridView.cols = 3
[end]
```

### Conditional Configuration

```typoscript
# Only show Grid View for certain tables
mod.web_list.gridView.table.sys_file_metadata.preview = 1
mod.web_list.gridView.table.sys_file_metadata.imageField = file

# Disable preview for system tables
mod.web_list.gridView.table.be_users.preview = 0
mod.web_list.gridView.table.sys_log.preview = 0
```

## View Mode Resolution Precedence

The active view mode is determined in this order:

1. **Request Parameter** (`?displayMode=grid`) - Highest priority
2. **User Preference** (stored in backend user settings)
3. **Page TSconfig** (`mod.web_list.gridView.default`)
4. **Fallback**: `list`

This means:
- URL parameters always win (useful for sharing links)
- User preferences are remembered across sessions
- TSconfig defines the default for new users

