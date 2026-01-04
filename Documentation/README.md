# Records Grid View for TYPO3 v14

A TYPO3 extension that adds a **Grid View** (card-based layout) to the Records module, providing a visual alternative to the traditional table-based List View.

## Features

- **Card-based Layout**: View records as Bootstrap 5 cards with thumbnails
- **Visual Browsing**: Quickly identify records by their images (news, products, team members)
- **Per-Table Configuration**: Configure which fields to display via TSconfig
- **User Preference Persistence**: Selected view mode is remembered per user
- **Dark Mode Support**: Fully compatible with TYPO3's dark mode
- **PSR-14 Integration**: Extends the core module without modifying it
- **Middleware Diagnostics**: Detects potential interference from custom middlewares

## Requirements

- TYPO3 v14.0+
- PHP 8.3+

## Installation

Install via Composer:

```bash
composer require webconsulting/records-list-types
```

Then activate the extension in the Extension Manager or via CLI:

```bash
./vendor/bin/typo3 extension:activate records_list_types
```

## Quick Start

### 1. Enable Grid View (Default)

The Grid View is enabled by default. After installation, navigate to **Content > Records** and you'll see the List/Grid toggle buttons in the module header.

### 2. Configure Fields per Table

Add TSconfig to define which fields should appear in the cards:

```typoscript
mod.web_list.gridView.table.tx_news_domain_model_news {
    titleField = title
    descriptionField = teaser
    imageField = fal_media
    preview = 1
}
```

### 3. Set Default View Mode

To default to Grid View instead of List View:

```typoscript
mod.web_list.gridView.default = grid
```

## Screenshots

### Grid View
Records displayed as cards with thumbnails:
```
┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│  [image]    │ │  [image]    │ │  [image]    │ │  [image]    │
│  Title 1    │ │  Title 2    │ │  Title 3    │ │  Title 4    │
│  Teaser...  │ │  Teaser...  │ │  Teaser...  │ │  Teaser...  │
│  [actions]  │ │  [actions]  │ │  [actions]  │ │  [actions]  │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘
```

### List View (Traditional)
The standard table-based list view:
```
┌──────┬────────────────────┬────────────┬─────────┐
│ UID  │ Title              │ Date       │ Actions │
├──────┼────────────────────┼────────────┼─────────┤
│ 1    │ First Article      │ 2025-01-15 │ ✏️ 🗑️   │
│ 2    │ Second Article     │ 2025-01-14 │ ✏️ 🗑️   │
└──────┴────────────────────┴────────────┴─────────┘
```

## Configuration

The extension automatically loads its default TSconfig from `Configuration/page.tsconfig`.

See [Configuration.md](Configuration.md) for the complete TSconfig reference.

## Architecture

See [Architecture.md](Architecture.md) for technical details on how the extension works.

## Custom View Modes

The extension supports registering custom view modes beyond the built-in `list`, `grid`, and `compact` views.

### Method 1: PSR-14 Event (Recommended)

Register a listener for `RegisterViewModesEvent`:

```php
<?php
// Classes/EventListener/RegisterCustomViewListener.php
namespace MyVendor\MyExtension\EventListener;

use Webconsulting\RecordsListTypes\Event\RegisterViewModesEvent;

final class RegisterCustomViewListener
{
    public function __invoke(RegisterViewModesEvent $event): void
    {
        $event->addViewMode('kanban', [
            'label' => 'LLL:EXT:my_extension/Resources/Private/Language/locallang.xlf:viewMode.kanban',
            'icon' => 'actions-view-table-columns',
            'description' => 'Kanban board view for workflow management',
        ]);
    }
}
```

Register in `Configuration/Services.yaml`:

```yaml
MyVendor\MyExtension\EventListener\RegisterCustomViewListener:
  tags:
    - name: event.listener
      identifier: 'my-extension/register-custom-view'
```

### Method 2: TSconfig

Register custom views directly in Page TSconfig:

```typoscript
mod.web_list.viewMode {
    # Define custom view mode
    types {
        kanban {
            label = Kanban Board
            icon = actions-view-table-columns
            description = Kanban board view
        }
        timeline {
            label = Timeline
            icon = actions-calendar
            description = Timeline view for date-based records
        }
    }
    
    # Allow the custom views (add to allowed list)
    allowed = list,grid,compact,kanban,timeline
    
    # Optionally set as default
    default = kanban
}
```

### View Mode Schema

Each view mode requires:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `label` | string | ✓ | Display label (supports `LLL:` references) |
| `icon` | string | ✓ | TYPO3 icon identifier (see Icon API) |
| `description` | string | | Optional description text |

### Available Icons

Common TYPO3 icons for view modes:
- `actions-viewmode-list` - Horizontal lines
- `actions-viewmode-tiles` - Grid of boxes
- `actions-menu` - Hamburger menu
- `actions-view-table-columns` - Table columns
- `actions-calendar` - Calendar
- `actions-list-alternative` - Bullet list

### Implementing a Custom View

After registering your view mode, you need to implement the rendering logic.

**Option A: Extend the Controller (Recommended)**

```php
<?php
namespace MyVendor\MyExtension\Controller;

use Webconsulting\RecordsListTypes\Controller\RecordListController;

class CustomRecordListController extends RecordListController
{
    protected function renderKanbanViewContent(...): string
    {
        // Your kanban rendering logic
    }
}
```

**Option B: Use an Event Listener**

Listen to `RenderAdditionalContentToRecordListEvent` and inject your content.

### TSconfig Reference for Views

```typoscript
mod.web_list.viewMode {
    # Default view when user has no preference
    default = grid
    
    # Comma-separated list of allowed views
    allowed = list,grid,compact
    
    # Register custom view types
    types {
        myview {
            label = My Custom View
            icon = my-icon
            description = Description text
        }
    }
}
```

## Extending

See [Extending.md](Extending.md) for more information on customizing the Grid View.

## Troubleshooting

### Grid View toggle not appearing

1. Ensure the extension is activated
2. Check that `mod.web_list.allowedViews` includes `grid`
3. Clear all caches

### Thumbnails not showing

1. Verify the `imageField` in TSconfig points to a valid FAL field
2. Ensure `preview = 1` is set for the table
3. Check that the records have images attached

### Middleware Warning

If you see a middleware warning, a custom middleware may be interfering with the response. Check the middleware stack in **System > Configuration > HTTP Middlewares**.

## License

GPL-2.0-or-later

