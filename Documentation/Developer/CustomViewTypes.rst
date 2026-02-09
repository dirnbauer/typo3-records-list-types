..  include:: /Includes.rst.txt

.. _custom-view-types:

=================
Custom view types
=================

Adding a new view type requires **zero PHP**. You provide TSconfig and a
Fluid template -- the extension handles record fetching, pagination,
sorting, action buttons, and asset loading.

.. _custom-view-types-quickstart:

Quick start: 3 steps
====================

**Step 1 -- Register the view type (Page TSconfig):**

..  code-block:: typoscript
    :caption: Page TSconfig

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

**Step 2 -- Create the Fluid template:**

Copy :file:`EXT:records_list_types/Resources/Private/Templates/GenericView.html`
to your sitepackage and customize it. The template receives ``tableData``
with all records, ``paginator``/``pagination`` for paging, and
``actionButtons`` for the heading bar.

**Step 3 -- Add CSS (optional):**

Your CSS file is loaded after ``base.css``, which already provides
heading, pagination, and sorting styles.

That's it. The new view appears in the view switcher and works with
pagination, sorting, search, and all record actions.

.. _custom-view-types-page-specific:

View type on a specific page
============================

Use TSconfig conditions to restrict a view to certain pages:

..  code-block:: typoscript
    :caption: Page TSconfig

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

More scoping examples:

..  code-block:: typoscript
    :caption: Page TSconfig

    # Grid-only for media folders (doktype 254 = sysfolder)
    [page["doktype"] == 254]
        mod.web_list.viewMode.default = grid
        mod.web_list.viewMode.allowed = list,grid
    [end]

    # Custom view for an entire page tree
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

.. _custom-view-types-examples:

Real-world examples
===================

Address Book (compact with specific columns)
---------------------------------------------

..  code-block:: typoscript
    :caption: Page TSconfig

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

This reuses the built-in ``CompactView`` template with custom columns --
no new template needed.

Event Calendar List
-------------------

..  code-block:: typoscript
    :caption: Page TSconfig

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

Reuses the built-in ``TeaserView`` template. The date field is
automatically detected and shown with a calendar icon.

Photo Gallery
-------------

..  code-block:: typoscript
    :caption: Page TSconfig

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

No custom template -- ``GridView`` handles thumbnail display
automatically from the ``imageField`` config.

Full list without pagination
----------------------------

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.fulllist {
        label = Full List
        icon = actions-viewmode-list
        template = CompactView
        columnsFromTCA = 1
        itemsPerPage = 0
    }

Setting ``itemsPerPage = 0`` disables pagination entirely.

.. _custom-view-types-reuse:

Reusing built-in templates
==========================

You don't need a custom template for every view type:

.. list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Template
        -   Best for

    *   -   ``GridView``
        -   Visual content with images (products, team, portfolio)

    *   -   ``CompactView``
        -   Dense data (addresses, system records, logs)

    *   -   ``TeaserView``
        -   Content previews (news, blog posts, events)

    *   -   ``GenericView``
        -   Starting point for fully custom layouts

.. _custom-view-types-builtin:

Built-in view types
===================

.. list-table::
    :header-rows: 1
    :widths: 15 20 65

    *   -   ID
        -   Label
        -   Description

    *   -   ``list``
        -   List View
        -   Standard TYPO3 table view (handled by core)

    *   -   ``grid``
        -   Grid View
        -   Card-based grid with thumbnails and field display

    *   -   ``compact``
        -   Compact View
        -   Single-line table with sortable columns

    *   -   ``teaser``
        -   Teaser List
        -   Minimal cards with title, date, and teaser

.. _custom-view-types-options:

Configuration reference
=======================

..  confval:: types.<id>.label
    :name: conf-type-label
    :type: string
    :required: true

    Display name in the view switcher. Supports ``LLL:`` references.

..  confval:: types.<id>.icon
    :name: conf-type-icon
    :type: string
    :required: true

    TYPO3 icon identifier.

..  confval:: types.<id>.template
    :name: conf-type-template
    :type: string
    :default: ``<TypeId>View``

    Fluid template name (without :file:`.html`).

..  confval:: types.<id>.css
    :name: conf-type-css
    :type: string
    :default: *(none)*

    CSS file to load (``EXT:`` syntax). ``base.css`` is always loaded
    automatically before this file.

..  confval:: types.<id>.js
    :name: conf-type-js
    :type: string
    :default: *(none)*

    JavaScript module (``@vendor/module.js`` syntax).

..  confval:: types.<id>.columnsFromTCA
    :name: conf-type-columnsfromtca
    :type: boolean
    :default: ``1``

    Controls how display columns are determined. See
    :ref:`custom-view-types-columns` below.

..  confval:: types.<id>.displayColumns
    :name: conf-type-displaycolumns
    :type: string (comma-separated)
    :default: *(empty)*

    Explicit list of fields (used when ``columnsFromTCA = 0``).
    Special names: ``label`` (title field), ``datetime`` (first date
    field), ``teaser`` (first description field).

..  confval:: types.<id>.itemsPerPage
    :name: conf-type-itemsperpage
    :type: int
    :default: ``100``

    Records per page. Set to ``0`` to disable pagination.

.. _custom-view-types-columns:

Column display: columnsFromTCA vs displayColumns
==================================================

These two options control which fields appear in your view.

columnsFromTCA = 1 (default)
-----------------------------

The view uses the same column selection as the standard TYPO3 List
View, resolved in this order:

1.  **Editor's "Show columns" selection** -- editors click the column
    selector button in the List View header to pick visible columns.
    Stored per-user per-table. Your custom view respects them
    automatically.
2.  **TSconfig showFields** -- fallback if the editor hasn't chosen
    columns (``mod.web_list.table.<table>.showFields``).
3.  **TCA searchFields** -- fallback if no TSconfig is set (the table's
    most relevant fields from ``ctrl.searchFields``).
4.  **Label field only** -- final fallback: just the record title.

Ideal for **editor-controlled views** where users pick their own
columns.

columnsFromTCA = 0
-------------------

The view ignores the editor's column selection and uses the explicit
``displayColumns`` list instead. The template always receives exactly
the fields you specified.

Ideal for **fixed-layout views** where the template is designed for
specific fields.

How the built-in views use it:

-   **Grid** (``columnsFromTCA = 1``) -- editors choose columns via
    the selector; each card shows those fields.
-   **Compact** (``columnsFromTCA = 1``) -- editors choose columns;
    each table row shows those fields.
-   **Teaser** (``columnsFromTCA = 0``,
    ``displayColumns = label,datetime,teaser``) -- always shows
    title + date + description; the template expects exactly these.

Example -- fixed columns:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.contacts {
        label = Contact List
        template = CompactView
        columnsFromTCA = 0
        displayColumns = name,email,phone,company
    }

Always shows name, email, phone, company -- regardless of the
editor's column selector.

Example -- editor-controlled columns:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.dashboard {
        label = Dashboard
        template = GridView
        columnsFromTCA = 1
    }

Editors click "Show columns" to pick which fields appear on the cards.

.. _custom-view-types-assets:

Assets: CSS, JavaScript, Images
================================

What is loaded automatically
-----------------------------

Every view type automatically receives:

-   ``base.css`` -- shared heading, pagination, sorting styles
-   ``GridViewActions.js`` -- drag-drop, record actions, pagination,
    sorting, search
-   ``column-selector-button.js`` -- TYPO3 column selector web component

You only need to add assets for view-specific styling or behavior.

CSS
---

Add a CSS file via the ``css`` option. It loads **after** ``base.css``:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.kanban {
        css = EXT:my_sitepackage/Resources/Public/Css/kanban.css
    }

Use TYPO3 CSS variables for automatic dark mode support:

..  code-block:: css

    .kanban-column {
        background: var(--typo3-component-bg, #fff);
        border: 1px solid var(--typo3-component-border-color, #d4d4d8);
    }

JavaScript
----------

Add custom JS modules via the ``js`` option. Your module loads alongside
the base ``GridViewActions.js``:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.kanban {
        js = @my-sitepackage/kanban-board.js
    }

Register the module path in
:file:`Configuration/JavaScriptModules.php`:

..  code-block:: php

    <?php

    return [
        'imports' => [
            '@my-sitepackage/' => 'EXT:my_sitepackage/Resources/Public/JavaScript/',
        ],
    ];

Images and Icons
-----------------

Reference static images in templates:

..  code-block:: html

    <img src="{f:uri.resource(path: 'Icons/my-icon.svg', extensionName: 'my_sitepackage')}" alt="" />

Register custom icons for the view switcher in
:file:`Configuration/Icons.php`:

..  code-block:: php

    <?php

    return [
        'my-kanban-icon' => [
            'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
            'source' => 'EXT:my_sitepackage/Resources/Public/Icons/kanban.svg',
        ],
    ];

Then reference in TSconfig:

..  code-block:: typoscript

    mod.web_list.viewMode.types.kanban.icon = my-kanban-icon

File structure for a custom view type
--------------------------------------

..  code-block:: text

    my_sitepackage/
    ├── Configuration/
    │   ├── Icons.php                      # Custom icon (optional)
    │   ├── JavaScriptModules.php          # ES module paths (if using js)
    │   └── TsConfig/Page/mod.tsconfig    # View type TSconfig
    └── Resources/
        ├── Private/Backend/
        │   ├── Templates/KanbanView.html  # Main Fluid template
        │   └── Partials/KanbanCard.html   # Card partial (optional)
        └── Public/
            ├── Css/kanban.css             # View-specific styles
            ├── JavaScript/kanban-board.js  # Custom JS (optional)
            └── Icons/kanban.svg           # Custom icon (optional)

Asset loading order
--------------------

1.  ``base.css`` -- always (shared components)
2.  Your ``css`` file -- view-specific styles
3.  ``GridViewActions.js`` -- always (core interactions)
4.  ``column-selector-button.js`` -- always (TYPO3 component)
5.  Your ``js`` module -- custom behavior

.. _custom-view-types-psr14:

Method 2: PSR-14 event (for extensions)
========================================

If building a TYPO3 extension, register view types via PSR-14:

..  code-block:: php
    :caption: Classes/EventListener/RegisterCustomViewListener.php

    <?php

    declare(strict_types=1);

    namespace MyVendor\MyExtension\EventListener;

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

Templates and CSS work exactly the same as with TSconfig registration.
