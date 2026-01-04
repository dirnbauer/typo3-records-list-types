# Extending the Grid View

This document explains how developers can customize and extend the Records Grid View.

## Custom Card Templates

### Override Templates via TypoScript

You can override the Fluid templates using standard TYPO3 template paths:

```typoscript
# In your extension's TypoScript setup
module.tx_recordsgridview {
    view {
        templateRootPaths {
            100 = EXT:your_extension/Resources/Private/Templates/RecordsListTypes/
        }
        partialRootPaths {
            100 = EXT:your_extension/Resources/Private/Partials/RecordsListTypes/
        }
        layoutRootPaths {
            100 = EXT:your_extension/Resources/Private/Layouts/RecordsListTypes/
        }
    }
}
```

### Custom Card Partial

Create your own `Card.html` partial:

```html
<!-- EXT:your_extension/Resources/Private/Partials/RecordsListTypes/Card.html -->
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:core="http://typo3.org/ns/TYPO3/CMS/Core/ViewHelpers"
      data-namespace-typo3-fluid="true">

<div class="card h-100 my-custom-card">
    <!-- Your custom card layout -->
    <div class="card-body">
        <h5 class="card-title">{record.title}</h5>
        
        <!-- Custom field display -->
        <f:if condition="{record.customField}">
            <span class="badge bg-primary">{record.customField}</span>
        </f:if>
    </div>
</div>

</html>
```

## Custom Actions

The Grid View automatically displays all actions registered via `ModifyRecordListRecordActionsEvent`. To add custom actions:

```php
<?php

namespace YourVendor\YourExtension\EventListener;

use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener]
final class CustomRecordActionListener
{
    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        $table = $event->getTable();
        $record = $event->getRecord();

        // Add custom action for specific table
        if ($table === 'tx_yourext_domain_model_item') {
            $event->setAction(
                action: '<a href="..." title="My Action"><span class="icon">...</span></a>',
                actionName: 'myCustomAction',
                group: 'primary',
                after: 'edit'
            );
        }
    }
}
```

Register in `Services.yaml`:

```yaml
services:
  YourVendor\YourExtension\EventListener\CustomRecordActionListener:
    tags:
      - name: event.listener
```

## Custom Thumbnail Logic

### Extend ThumbnailService

For special image handling, extend the thumbnail service:

```php
<?php

namespace YourVendor\YourExtension\Service;

use Webconsulting\RecordsListTypes\Service\ThumbnailService;
use TYPO3\CMS\Core\Resource\FileInterface;

class CustomThumbnailService extends ThumbnailService
{
    public function getThumbnailForRecord(string $table, array $record, string $imageField): ?FileInterface
    {
        // Custom logic for specific tables
        if ($table === 'tx_yourext_domain_model_item') {
            return $this->getFromExternalSource($record);
        }

        // Fall back to default behavior
        return parent::getThumbnailForRecord($table, $record, $imageField);
    }

    private function getFromExternalSource(array $record): ?FileInterface
    {
        // Your custom logic here
        return null;
    }
}
```

Register as override in `Services.yaml`:

```yaml
services:
  Webconsulting\RecordsListTypes\Service\ThumbnailService:
    class: YourVendor\YourExtension\Service\CustomThumbnailService
```

## Disable Grid View for Specific Tables

### Via TSconfig

```typoscript
# Disable preview (shows placeholder instead of image)
mod.web_list.gridView.table.sys_file_metadata.preview = 0

# Or hide the entire Grid View option for certain pages
[page["uid"] == 123]
    mod.web_list.allowedViews = list
[end]
```

### Via Event Listener

Listen to the button bar event and conditionally hide the toggle:

```php
<?php

namespace YourVendor\YourExtension\EventListener;

use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener(after: \Webconsulting\RecordsListTypes\EventListener\GridViewButtonBarListener::class)]
final class ConditionalGridViewListener
{
    public function __invoke(ModifyButtonBarEvent $event): void
    {
        // Your condition to hide the Grid View toggle
        if ($this->shouldHideGridView()) {
            $buttons = $event->getButtons();
            
            // Remove the grid view buttons (they're in position right, group 1)
            unset($buttons[\TYPO3\CMS\Backend\Template\Components\ButtonBar::BUTTON_POSITION_RIGHT][1]);
            
            $event->setButtons($buttons);
        }
    }

    private function shouldHideGridView(): bool
    {
        // Your custom logic
        return false;
    }
}
```

## Custom Data Provider

To add custom data to the Grid View records:

```php
<?php

namespace YourVendor\YourExtension\Service;

use Webconsulting\RecordsListTypes\Service\RecordGridDataProvider;

class EnhancedRecordGridDataProvider extends RecordGridDataProvider
{
    public function getRecordsForTable(string $table, int $pageId, array $config): array
    {
        $records = parent::getRecordsForTable($table, $pageId, $config);

        // Enhance records with additional data
        foreach ($records as &$record) {
            $record['customData'] = $this->loadCustomData($record['uid']);
        }

        return $records;
    }

    private function loadCustomData(int $uid): array
    {
        // Your custom data loading
        return [];
    }
}
```

## JavaScript Hooks

The view switcher JavaScript exposes events you can listen to:

```javascript
document.addEventListener('recordsGridview:viewModeChanged', (event) => {
    console.log('View mode changed to:', event.detail.mode);
    
    // Your custom logic
    if (event.detail.mode === 'grid') {
        // Initialize lazy loading, etc.
    }
});
```

## CSS Customization

### Override Styles

Add custom CSS in your extension:

```css
/* EXT:your_extension/Resources/Public/Css/custom-gridview.css */

.recordlist-gridview-card {
    /* Your custom card styles */
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.recordlist-gridview-thumbnail {
    /* Custom thumbnail styles */
    aspect-ratio: 16 / 9;
    object-fit: cover;
}
```

Include in backend:

```typoscript
# In Page TSconfig
page.includeCSS.customGridview = EXT:your_extension/Resources/Public/Css/custom-gridview.css
```

### Dark Mode Considerations

Always use CSS custom properties for colors:

```css
.my-custom-element {
    /* Good: Uses TYPO3's CSS variables */
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
    
    /* Bad: Hardcoded colors won't adapt to dark mode */
    /* background-color: #ffffff; */
}
```

## Testing Your Customizations

### Unit Tests

```php
<?php

namespace YourVendor\YourExtension\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use YourVendor\YourExtension\Service\CustomThumbnailService;

class CustomThumbnailServiceTest extends TestCase
{
    public function testCustomLogicIsApplied(): void
    {
        $service = new CustomThumbnailService();
        
        $result = $service->getThumbnailForRecord(
            'tx_yourext_domain_model_item',
            ['uid' => 1, 'title' => 'Test'],
            'image'
        );
        
        // Your assertions
        $this->assertNotNull($result);
    }
}
```

### Functional Tests

Test that your event listeners are registered and working:

```php
<?php

namespace YourVendor\YourExtension\Tests\Functional\EventListener;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class CustomRecordActionListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/records-list-types',
        'your-vendor/your-extension',
    ];

    public function testCustomActionIsAdded(): void
    {
        // Your functional test
    }
}
```

