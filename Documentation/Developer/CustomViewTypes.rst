..  include:: /Includes.rst.txt

.. _custom-view-types:

=================
Custom view types
=================

Adding a new view type requires **zero PHP**. You provide TSconfig and a
Fluid template -- the extension handles record fetching, pagination,
sorting, action buttons, and asset loading.

..  tip::

    Install the companion extension
    `Records List Examples <https://github.com/dirnbauer/typo3-records-list-examples>`__
    to get 6 ready-to-use view types (Timeline, Catalog, Address Book,
    Event List, Gallery, Dashboard) with full templates and CSS.

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
to your sitepackage and change the list item. The template receives
``tableData`` (one entry per table, with its ``records``) and renders each
table through the ``Table/Section`` partial, which frames it like a table of
the List View: filters, heading with the table actions, the selection bar,
workspace notices, pagination, the empty state and "Expand table". Your
template only renders the records, as the child content:

..  code-block:: html
    :caption: Resources/Private/Backend/Templates/TimelineView.html

    <records-list-types-actions class="rlt-view">
        <f:for each="{tableData}" as="table">
            <f:render partial="Table/Section"
                      arguments="{table: table, currentTable: currentTable, sortingControls: 1}"
                      contentAs="body">
                <ul class="my-timeline">
                    <f:for each="{table.records}" as="record">
                        <li data-uid="{record.uid}" data-multi-record-selection-element="true">
                            <f:render partial="Record/Checkbox" arguments="{record: record}" />
                            <f:render partial="Record/Icon" arguments="{record: record}" />
                            <f:render partial="Record/Title" arguments="{record: record}" />
                            <f:render partial="Record/States" arguments="{record: record}" />
                            <f:render partial="Record/Controls" arguments="{record: record}" />
                        </li>
                    </f:for>
                </ul>
            </f:render>
        </f:for>
    </records-list-types-actions>

The ``Record/*`` partials render what the List View renders for a record:

-   ``Record/Icon`` -- the icon with its state overlays, opening the context
    menu (``record.iconHtml``)
-   ``Record/Controls`` -- Core's control panel with edit, visibility, move,
    delete, info, history, clipboard and the actions of other extensions
    (``record.controlsHtml``)
-   ``Record/Title`` -- the title as contextual edit trigger, with Core's
    lock symbol and the strike-through of records deleted in a workspace
-   ``Record/States`` -- text badges for hidden, workspace state and
    free-mode translations
-   ``Record/Checkbox`` -- the selection checkbox, with an accessible name
-   ``Record/FieldValue`` -- one entry of ``record.displayValues``, formatted
    like the List View formats it
-   ``Record/DragHandle`` -- the keyboard handle for reordering
-   ``Table/SelectionToggle`` -- Core's "Check all / Uncheck all / Toggle"
    menu (pass a unique ``id``)
-   ``TranslationStrip`` -- the translations of a record

Records rendered by the extension also expose ``record.editUrl`` and
``record.contextualEditUrl`` for links of your own:

..  code-block:: html

    <typo3-backend-contextual-record-edit-trigger
        edit-url="{record.editUrl}"
        url="{record.contextualEditUrl}">
        {record.title}
    </typo3-backend-contextual-record-edit-trigger>

This mirrors TYPO3 core behavior: contextual editing opens the native
sheet editor when enabled for the current backend user and otherwise
falls back to the regular FormEngine in the content frame.

Wrap the template in ``<records-list-types-actions>``. The element is
registered by ``GridViewActions.js`` and adds visibility changes in place on
non-table markup, drag-and-drop and keyboard reordering (for markup like the
built-in grid), the page number input and the ``data-gridview-action``
buttons of templates written for 1.x.

..  note::

    The partials ``RecordActionDropdown`` and ``RecordActions`` of 1.x still
    work; use ``Record/Controls`` in new templates, which also shows the
    actions of other extensions.

**Step 3 -- Add CSS (optional):**

Your CSS file is loaded after ``base.css``, which already styles the parts
all views share. Style with TYPO3's design tokens (``--typo3-*``) and Core's
components so light and dark mode keep working.

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
            icon = actions-user
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

..  confval:: mod.web_list.viewMode.types.<id>.label
    :name: conf-type-label
    :type: string
    :required: true

    Display name in the view switcher. Supports ``LLL:`` references.

..  confval:: mod.web_list.viewMode.types.<id>.icon
    :name: conf-type-icon
    :type: string
    :required: true

    TYPO3 icon identifier.

..  confval:: mod.web_list.viewMode.types.<id>.template
    :name: conf-type-template
    :type: string
    :default: ``<TypeId>View``

    Fluid template name (without :file:`.html`).

..  confval:: mod.web_list.viewMode.types.<id>.css
    :name: conf-type-css
    :type: string
    :default: *(none)*

    CSS file to load (``EXT:`` syntax). ``base.css`` and, when the type
    renders a built-in template (``GridView``, ``CompactView``,
    ``TeaserView``), that template's stylesheet are loaded before it.

..  confval:: mod.web_list.viewMode.types.<id>.js
    :name: conf-type-js
    :type: string
    :default: *(none)*

    JavaScript module (``@vendor/module.js`` syntax).

..  confval:: mod.web_list.viewMode.types.<id>.columnsFromTCA
    :name: conf-type-columnsfromtca
    :type: boolean
    :default: ``1``

    Controls how display columns are determined. See
    :ref:`custom-view-types-columns` below.

..  confval:: mod.web_list.viewMode.types.<id>.displayColumns
    :name: conf-type-displaycolumns
    :type: string (comma-separated)
    :default: *(empty)*

    Explicit list of fields (used when ``columnsFromTCA = 0``).
    Special names: ``label`` (title field), ``datetime`` (first date
    field), ``teaser`` (first description field).

..  confval:: mod.web_list.viewMode.types.<id>.itemsPerPage
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

-   ``base.css`` -- the parts all views share
-   the stylesheet of the built-in template the type renders, if any
-   ``GridViewActions.js`` -- the ``<records-list-types-actions>`` element
-   Core's record list modules: context menu, multi-record selection,
    column selector, localization, clipboard

You only need to add assets for view-specific styling or behavior.

CSS
---

Add a CSS file via the ``css`` option. It loads **after** ``base.css``:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.kanban {
        css = EXT:my_sitepackage/Resources/Public/Css/kanban.css
    }

Use TYPO3's design tokens; they follow the colour scheme chosen in the
backend. Avoid own colours and ``prefers-color-scheme`` queries:

..  code-block:: css

    .kanban-column {
        background: var(--typo3-component-bg);
        border: var(--typo3-component-border-width) solid var(--typo3-component-border-color);
        border-radius: var(--typo3-component-border-radius);
    }

JavaScript
----------

Add custom JS modules via the ``js`` option. Your module loads alongside
the base ``GridViewActions.js``. If your template needs the extension's
shared interactions, keep the ``<records-list-types-actions>`` wrapper in
the rendered markup:

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
    │   └── page.tsconfig                  # View type TSconfig (loaded automatically)
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
2.  The stylesheet of the built-in template the type renders, if any
3.  Your ``css`` file -- view-specific styles
4.  ``GridViewActions.js`` -- always
5.  Your ``js`` module -- custom behavior

.. _custom-view-types-psr14:

PSR-14 registration hook
========================

If building a TYPO3 extension, use PSR-14 to add, remove, or rename view
mode entries before TSconfig is merged. The event API accepts the view
mode identity data (label, icon, description). ``label`` and ``description``
accept a label reference as well as plain text; the description becomes the
tooltip of the entry in the view switcher. Use Page TSconfig for template
paths, CSS, JavaScript, and display-column settings.

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
                'label' => 'my_extension.messages:viewMode.kanban',
                'icon' => 'actions-view-table-columns',
                'description' => 'my_extension.messages:viewMode.kanban.description',
            ]);
        }
    }

Then configure rendering details in Page TSconfig:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode.types.kanban {
        template = KanbanView
        templateRootPath = EXT:my_extension/Resources/Private/Templates/
        css = EXT:my_extension/Resources/Public/Css/kanban.css
        columnsFromTCA = 1
    }
