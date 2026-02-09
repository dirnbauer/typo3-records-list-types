# Architecture

This document explains the technical architecture of the Records Grid View extension.

## Overview

The extension hooks into the TYPO3 v14 Records module using PSR-14 events. It does not modify the core module but augments it with an alternative visualization.

## Component Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              Backend Request                                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                       MiddlewareDiagnosticService                            │
│  - Inspects middleware stack for potential interference                      │
│  - Generates warnings if risks detected                                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         RecordListController (Core)                          │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                   ┌──────────────────┼──────────────────┐
                   ▼                  ▼                  ▼
          ┌───────────────┐  ┌───────────────┐  ┌───────────────┐
          │ModifyButtonBar│  │ModifyDatabase │  │ModifyRecord   │
          │Event          │  │QueryEvent     │  │ListActionsEvt │
          └───────────────┘  └───────────────┘  └───────────────┘
                   │                  │                  │
                   ▼                  ▼                  ▼
          ┌───────────────┐  ┌───────────────┐  ┌───────────────┐
          │GridViewButton │  │GridViewQuery  │  │GridViewRecord │
          │BarListener    │  │Listener       │  │ActionsListener│
          └───────────────┘  └───────────────┘  └───────────────┘
                   │
                   ▼
          ┌───────────────┐
          │ViewModeResolver│──────────────────────────┐
          └───────────────┘                           │
                   │                                  │
        ┌──────────┴──────────┐                       ▼
        ▼                     ▼              ┌───────────────┐
┌───────────────┐    ┌───────────────┐       │ User Prefs    │
│ Request Param │    │ Page TSconfig │       │ (BE_USER->uc) │
│ ?displayMode= │    │ mod.web_list. │       └───────────────┘
└───────────────┘    └───────────────┘
                              │
                              ▼
                     ┌───────────────┐
                     │GridConfig     │
                     │Service        │
                     └───────────────┘
                              │
                              ▼
                     ┌───────────────┐
                     │RecordGridData │
                     │Provider       │
                     └───────────────┘
                              │
                              ▼
                     ┌───────────────┐
                     │ThumbnailService│
                     └───────────────┘
                              │
                              ▼
                     ┌───────────────┐
                     │ Fluid Engine  │
                     │ GridView.html │
                     └───────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Bootstrap 5 Cards                                 │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐                            │
│  │[image]  │ │[image]  │ │[image]  │ │[image]  │                            │
│  │Title    │ │Title    │ │Title    │ │Title    │                            │
│  │Desc...  │ │Desc...  │ │Desc...  │ │Desc...  │                            │
│  │[actions]│ │[actions]│ │[actions]│ │[actions]│                            │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘                            │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Components

### Services

| Service | Responsibility |
|---------|----------------|
| `ViewModeResolver` | Determines active view mode based on request params > user prefs > TSconfig |
| `GridConfigurationService` | Parses TSconfig for per-table field mappings, caches results |
| `RecordGridDataProvider` | Fetches records with resolved FAL references for thumbnails |
| `ThumbnailService` | Generates backend thumbnails using TYPO3's ProcessedFile API |
| `MiddlewareDiagnosticService` | Detects middleware configurations that could break rendering |

### Event Listeners

| Listener | Event | Purpose |
|----------|-------|---------|
| `GridViewButtonBarListener` | `ModifyButtonBarEvent` | Injects List/Grid toggle into DocHeader |
| `GridViewQueryListener` | `ModifyDatabaseQueryForRecordListingEvent` | Ensures Grid View respects query modifications |
| `GridViewRecordActionsListener` | `ModifyRecordListRecordActionsEvent` | Bridges record actions to card footers |

## View Mode Resolution

The view mode is determined with strict precedence:

```
1. Request Parameter (?displayMode=grid)
   │
   └─ if not set ─▶ 2. User Preference ($BE_USER->uc['web_list_view_mode'])
                     │
                     └─ if not set ─▶ 3. Page TSconfig (mod.web_list.gridView.default)
                                       │
                                       └─ if not set ─▶ 4. Fallback: "list"
```

### Implementation

```php
public function getActiveViewMode(ServerRequestInterface $request, int $pageId): string
{
    // 1. Explicit request parameter (highest priority)
    $queryParams = $request->getQueryParams();
    if (isset($queryParams['displayMode']) && in_array($queryParams['displayMode'], ['list', 'grid'])) {
        return $queryParams['displayMode'];
    }

    // 2. User preference (stored in backend user configuration)
    $userPreference = $this->backendUser->uc['web_list_view_mode'] ?? null;
    if ($userPreference && in_array($userPreference, ['list', 'grid'])) {
        return $userPreference;
    }

    // 3. Page TSconfig default
    $tsConfig = BackendUtility::getPagesTSconfig($pageId);
    $default = $tsConfig['mod.']['web_list.']['gridView.']['default'] ?? 'list';
    
    return in_array($default, ['list', 'grid']) ? $default : 'list';
}
```

## User Preference Persistence

User preferences are stored in `$GLOBALS['BE_USER']->uc['web_list_view_mode']`.

### AJAX Flow

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│ User clicks │────▶│ JavaScript  │────▶│ AJAX POST   │
│ Grid button │     │ event       │     │ /ajax/...   │
└─────────────┘     └─────────────┘     └─────────────┘
                                               │
                                               ▼
                                        ┌─────────────┐
                                        │ViewMode     │
                                        │Controller   │
                                        └─────────────┘
                                               │
                                               ▼
                                        ┌─────────────┐
                                        │BE_USER->uc  │
                                        │updated      │
                                        └─────────────┘
```

## Middleware Diagnostics

The extension includes a diagnostic service that checks for potential middleware interference.

### Detection Strategies

1. **Static Analysis**: Inspects the registered middleware stack for non-core entries positioned after output compression/finalization steps.

2. **Runtime Check**: Verifies required request attributes exist:
   - `normalizedParams`
   - `backend.user`
   - `site`

### Warning Display

When a risk is detected, a FlashMessage is displayed:

```
⚠️ System Warning: A custom middleware configuration has been detected 
that may interfere with the Grid View visualization. If the display is 
corrupted, please verify middleware stack configuration.

[Force List View]
```

## CSS Architecture

The extension uses a **base + view-specific** CSS pattern:

```
base.css              ← Loaded for ALL view modes (always first)
├── grid-view.css     ← Grid-only: cards, drag-drop, field types
├── compact-view.css  ← Compact-only: table, sticky columns, zebra striping
├── teaser-view.css   ← Teaser-only: teaser cards, badges, meta
└── view-mode-toggle.css ← DocHeader toggle buttons (always loaded)
```

**`base.css`** contains shared components used by every view mode:

| Component | Description |
|-----------|-------------|
| Recordlist heading | Table header bar with title and action buttons |
| Pagination | Core list view navigation (record range, page input, nav buttons) |
| Sorting mode toggle | Segmented control for manual/field sorting modes |
| Sorting dropdown | Field sorting dropdown and disabled state |

**View-specific files** contain only styles unique to that view -- design tokens, layout, and component styling. They use TYPO3 CSS variables (`--typo3-*`) with hardcoded fallbacks.

`base.css` is automatically prepended by `ViewTypeRegistry::getCssFiles()`. Custom view types registered via TSconfig or PSR-14 events also receive `base.css` automatically -- they only need to provide their own view-specific CSS.

## Bootstrap 5 Integration

The Grid View uses Bootstrap 5 components included in the TYPO3 v14 backend.

### Responsive Breakpoints

```
┌──────────────┬─────────────────────────────┐
│ Breakpoint   │ Columns (configurable)      │
├──────────────┼─────────────────────────────┤
│ xs (<576px)  │ 1 column (stacked)          │
│ md (≥768px)  │ 2 columns                   │
│ lg (≥992px)  │ 3 columns                   │
│ xl (≥1200px) │ 4 columns (default)         │
│ xxl (≥1400px)│ Based on cols setting       │
└──────────────┴─────────────────────────────┘
```

### CSS Custom Properties

The extension uses TYPO3's CSS variables for theming:

```css
.recordlist-gridview-card {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
}
```

This ensures automatic compatibility with:
- Light mode
- Dark mode
- Custom backend themes

## File Structure

```
Classes/
├── Controller/
│   └── Ajax/
│       └── ViewModeController.php      # AJAX endpoint for preference persistence
├── EventListener/
│   ├── GridViewButtonBarListener.php   # Injects toggle buttons
│   ├── GridViewQueryListener.php       # Query modification bridge
│   └── GridViewRecordActionsListener.php # Action bridging
├── Pagination/
│   └── DatabasePaginator.php           # Core Pagination API paginator for DB records
├── Service/
│   ├── GridConfigurationService.php    # TSconfig parsing
│   ├── MiddlewareDiagnosticService.php # Middleware checks
│   ├── RecordGridDataProvider.php      # Record fetching
│   ├── ThumbnailService.php            # Image processing
│   └── ViewModeResolver.php            # View mode determination
└── ViewHelpers/
    └── RecordActionsViewHelper.php     # Action rendering in cards

Configuration/
├── Icons.php                           # Icon registration
├── page.tsconfig                       # Default Page TSconfig (auto-loaded in v14)
└── Services.yaml                       # DI configuration
```

