# Records List Types for TYPO3 v14

A TYPO3 extension that adds multiple view modes (Grid, Compact, Teaser) to the backend Records module, providing visual alternatives to the traditional table-based List View.

## Features

- **Grid View**: Card-based layout with thumbnails for visual record browsing
- **Compact View**: Single-line rows for dense data display
- **Teaser View**: News-style cards with title, date, and description
- **Custom Views**: Register your own view types via PSR-14 events or TSconfig
- **User Preferences**: View mode is persisted per user
- **Dark Mode**: Full compatibility with TYPO3's dark mode
- **Per-Table Config**: Configure fields to display via TSconfig

## Requirements

- TYPO3 v14.0+
- PHP 8.3+

## Installation

 For standalone installation:

```bash
composer require webconsulting/records-list-types
```

Activate the extension:

```bash
./vendor/bin/typo3 extension:activate records_list_types
```

## Quick Start

After installation, navigate to **Content > Records** in the TYPO3 backend. You'll see view mode toggle buttons in the module header.

### Configure Fields per Table

```typoscript
mod.web_list.gridView.table.tx_news_domain_model_news {
    titleField = title
    descriptionField = teaser
    imageField = fal_media
    preview = 1
}
```

### Set Default View Mode

```typoscript
mod.web_list.viewMode.default = grid
```

## Documentation

Comprehensive documentation is available in the `Documentation/` folder:

| Document | Description |
|----------|-------------|
| [README.md](Documentation/README.md) | Full documentation with screenshots |
| [Architecture.md](Documentation/Architecture.md) | Technical architecture and component diagrams |
| [Configuration.md](Documentation/Configuration.md) | Complete TSconfig reference |
| [CustomViewTypes.md](Documentation/CustomViewTypes.md) | Creating custom view types |
| [Extending.md](Documentation/Extending.md) | Extension points and PSR-14 events |

## File Structure

```
records_list_types/
├── Classes/
│   ├── Controller/
│   │   ├── Ajax/ViewModeController.php    # AJAX preference persistence
│   │   └── RecordListController.php       # Main controller
│   ├── Event/
│   │   └── RegisterViewModesEvent.php     # PSR-14 event for custom views
│   ├── EventListener/
│   │   ├── GridViewButtonBarListener.php  # Injects toggle buttons
│   │   ├── GridViewQueryListener.php      # Query modification bridge
│   │   └── GridViewRecordActionsListener.php
│   ├── Service/
│   │   ├── GridConfigurationService.php   # TSconfig parsing
│   │   ├── MiddlewareDiagnosticService.php
│   │   ├── RecordGridDataProvider.php     # Record fetching
│   │   ├── ThumbnailService.php           # Image processing
│   │   ├── ViewModeResolver.php           # View mode determination
│   │   └── ViewTypeRegistry.php           # View type management
│   └── ViewHelpers/
│       └── RecordActionsViewHelper.php
├── Configuration/
│   ├── Backend/AjaxRoutes.php
│   ├── Icons.php
│   ├── JavaScriptModules.php
│   ├── page.tsconfig                      # Default configuration
│   └── Services.yaml
├── Documentation/                         # Comprehensive docs
├── Resources/
│   ├── Private/
│   │   ├── Language/                      # Translations (de, en)
│   │   ├── Layouts/
│   │   ├── Partials/
│   │   └── Templates/
│   └── Public/
│       ├── Css/                           # View-specific styles
│       ├── Icons/
│       └── JavaScript/                    # Frontend interactions
├── composer.json
├── ext_emconf.php
└── ext_localconf.php
```

## Security

The extension follows TYPO3 security best practices:

- **Input Validation**: All user input is validated and sanitized
- **SQL Injection**: Uses TYPO3's QueryBuilder with parameterized queries
- **CSRF Protection**: AJAX endpoints use TYPO3's token handling
- **Access Control**: Respects TYPO3's backend user permissions

## License

GPL-2.0-or-later

## Author

Webconsulting - office@webconsulting.at

