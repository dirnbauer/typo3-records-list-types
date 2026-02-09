..  include:: /Includes.rst.txt

.. _custom-view-types:

=================
Custom view types
=================

The extension supports custom view types beyond the built-in
``list``, ``grid``, ``compact``, and ``teaser`` views.

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

.. _custom-view-types-psr14:

Method 1: PSR-14 event (recommended)
=====================================

Register a listener for
:php:`RegisterViewModesEvent`:

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
            ]);
        }
    }

.. _custom-view-types-tsconfig:

Method 2: TSconfig
==================

Register custom views directly in Page TSconfig:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.viewMode {
        # Allow the custom view
        allowed = list,grid,compact,teaser,kanban

        types {
            kanban {
                label = Kanban Board
                icon = actions-view-table-columns
                description = Kanban board view
                template = KanbanView
                partial = KanbanCard
                css = EXT:my_ext/Resources/Public/Css/kanban.css
                columnsFromTCA = 0
                displayColumns = title,status
            }
        }
    }

.. _custom-view-types-options:

Configuration options
=====================

..  confval:: types.<id>.label
    :name: conf-type-label
    :type: string
    :required: true

    Display name in the view switcher. Supports ``LLL:`` references.

..  confval:: types.<id>.icon
    :name: conf-type-icon
    :type: string
    :required: true

    TYPO3 icon identifier. Common options:
    ``actions-viewmode-list``, ``actions-viewmode-tiles``,
    ``actions-menu``, ``actions-view-table-columns``,
    ``actions-calendar``, ``content-news``.

..  confval:: types.<id>.description
    :name: conf-type-description
    :type: string
    :default: *(empty)*

    Tooltip description shown in the view switcher.

..  confval:: types.<id>.template
    :name: conf-type-template
    :type: string
    :default: ``<TypeId>View``

    Fluid template name (without :file:`.html`).

..  confval:: types.<id>.partial
    :name: conf-type-partial
    :type: string
    :default: ``Card``

    Default partial for record cards.

..  confval:: types.<id>.templateRootPath
    :name: conf-type-templatepath
    :type: string
    :default: *(extension default)*

    Custom path to Fluid templates.

..  confval:: types.<id>.partialRootPath
    :name: conf-type-partialpath
    :type: string
    :default: *(extension default)*

    Custom path to Fluid partials.

..  confval:: types.<id>.css
    :name: conf-type-css
    :type: string
    :default: *(none)*

    CSS file to load. Use ``EXT:`` syntax.

..  confval:: types.<id>.js
    :name: conf-type-js
    :type: string
    :default: *(none)*

    JavaScript module to load. Use ``@vendor/module.js`` syntax.

..  confval:: types.<id>.columnsFromTCA
    :name: conf-type-columnsfromtca
    :type: boolean
    :default: ``1``

    Use the user's "Show columns" selection from TCA. Set to ``0``
    to use the explicit :confval:`types.<id>.displayColumns
    <conf-type-displaycolumns>` list instead.

..  confval:: types.<id>.displayColumns
    :name: conf-type-displaycolumns
    :type: string (comma-separated)
    :default: *(empty)*

    Explicit list of fields to display. Supports special names:
    ``label`` (TCA label field), ``datetime`` (first date field),
    ``teaser`` (first description field).

..  confval:: types.<id>.itemsPerPage
    :name: conf-type-itemsperpage
    :type: int
    :default: ``100`` (``300`` for compact)

    Number of records displayed per page. Set to ``0`` to disable
    pagination and show all records at once.

    ..  code-block:: typoscript
        :caption: Page TSconfig

        mod.web_list.viewMode.types.kanban.itemsPerPage = 50

.. _custom-view-types-template:

Creating a template
===================

Copy the generic template from
:file:`EXT:records_list_types/Resources/Private/Templates/GenericView.html`
to your extension and customize it.

Template variables
------------------

Your template receives these variables:

-   ``pageId`` -- current page ID
-   ``tableData`` -- array of table data objects
-   ``currentTable`` -- filtered table name (or empty)
-   ``searchTerm`` -- current search term
-   ``viewMode`` -- view type identifier
-   ``viewConfig`` -- view type configuration

Each item in ``tableData`` provides:

-   ``tableName``, ``tableLabel``, ``tableIcon``
-   ``records`` -- array of enriched record objects
-   ``recordCount``, ``actionButtons``
-   ``displayColumns``, ``sortableFields``
-   ``sortField``, ``sortDirection``
-   ``paginator`` -- :php:`DatabasePaginator` implementing
    :php:`TYPO3\CMS\Core\Pagination\PaginatorInterface`
-   ``pagination`` -- :php:`SlidingWindowPagination` implementing
    :php:`TYPO3\CMS\Core\Pagination\PaginationInterface`
-   ``paginationUrl`` -- base URL for building pagination links

Each record in ``records`` provides:

-   ``uid``, ``pid``, ``tableName``, ``title``
-   ``iconIdentifier``, ``hidden``
-   ``rawRecord`` -- full database row
-   ``displayValues`` -- formatted field values with ``field``,
    ``label``, ``type``, ``raw``, ``formatted``, ``isEmpty``
