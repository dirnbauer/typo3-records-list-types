..  include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

All configuration is done via Page TSconfig under
:typoscript:`mod.web_list`.

.. _configuration-view-mode:

View mode settings
==================

.. _configuration-view-mode-default:

..  confval:: mod.web_list.viewMode.default
    :name: conf-viewmode-default
    :type: string
    :default: ``list``

    The default view mode when no user preference exists.

    Available options: ``list``, ``grid``, ``compact``, ``teaser``,
    or any custom type registered via TSconfig or PSR-14 event.

    ..  code-block:: typoscript
        :caption: Page TSconfig

        mod.web_list.viewMode.default = grid

.. _configuration-view-mode-allowed:

..  confval:: mod.web_list.viewMode.allowed
    :name: conf-viewmode-allowed
    :type: string (comma-separated)
    :default: ``list,grid,compact,teaser``

    Comma-separated list of allowed view modes. Setting only one value
    hides the view mode toggle entirely.

    ..  code-block:: typoscript
        :caption: Page TSconfig

        # Only allow list and grid
        mod.web_list.viewMode.allowed = list,grid

        # Force list view only (hides toggle)
        mod.web_list.viewMode.allowed = list

.. _configuration-pagination:

Pagination
==========

Pagination matches TYPO3 Core List View behavior:

-   **Multi-table mode** (default page view): Shows a limited number
    of records per table (default 20). If a table has more records, an
    "Expand table" button links to single-table mode. No pagination
    controls are shown.
-   **Single-table mode** (after clicking a table name or "Expand
    table"): Full pagination at top and bottom with record range
    indicator, page input field, first/prev/next/last buttons, and
    reload button.

.. _configuration-pagination-limit-per-table:

..  confval:: mod.web_list.viewMode.itemsLimitPerTable
    :name: conf-viewmode-limitpertable
    :type: int
    :default: ``20``

    Maximum records shown per table in multi-table mode. Falls back
    to ``mod.web_list.itemsLimitPerTable`` (TYPO3 Core setting) if
    not set.

    ..  code-block:: typoscript
        :caption: Page TSconfig

        mod.web_list.viewMode.itemsLimitPerTable = 30

.. _configuration-pagination-items-per-page:

..  confval:: mod.web_list.viewMode.itemsPerPage
    :name: conf-viewmode-itemsperpage
    :type: int
    :default: ``100``

    Global default for the number of records displayed per page in all
    alternative view modes. Set to ``0`` to disable pagination entirely
    (show all records).

    ..  code-block:: typoscript
        :caption: Page TSconfig

        # Show 50 records per page in all view modes
        mod.web_list.viewMode.itemsPerPage = 50

        # Disable pagination (show all records)
        mod.web_list.viewMode.itemsPerPage = 0

.. _configuration-pagination-per-type:

..  confval:: mod.web_list.viewMode.types.<type>.itemsPerPage
    :name: conf-viewmode-type-itemsperpage
    :type: int
    :default: ``100`` (``300`` for compact)

    Override the items-per-page setting for a specific view type. The
    built-in defaults are:

    -  **grid**: ``100``
    -  **compact**: ``300`` (compact rows are denser, so more records fit)
    -  **teaser**: ``100``
    -  Custom types: ``100``

    ..  code-block:: typoscript
        :caption: Page TSconfig

        # Show 200 records per page in grid view
        mod.web_list.viewMode.types.grid.itemsPerPage = 200

        # Show 500 records per page in compact view
        mod.web_list.viewMode.types.compact.itemsPerPage = 500

        # Disable pagination for teaser view
        mod.web_list.viewMode.types.teaser.itemsPerPage = 0

        # Custom view type with 50 records per page
        mod.web_list.viewMode.types.myview.itemsPerPage = 50

.. _configuration-grid-cols:

..  confval:: mod.web_list.gridView.cols
    :name: conf-grid-cols
    :type: int
    :default: ``4``

    Number of columns in the grid layout. Uses Bootstrap's
    ``row-cols-xl-*`` classes. Valid range: 2--6.

    ..  code-block:: typoscript
        :caption: Page TSconfig

        mod.web_list.gridView.cols = 3

.. _configuration-table:

Per-table configuration
=======================

Configure how each table appears in Grid and Teaser views:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.gridView.table.tx_news_domain_model_news {
        titleField = title
        descriptionField = teaser
        imageField = fal_media
        preview = 1
    }

.. _configuration-table-titlefield:

..  confval:: mod.web_list.gridView.table.<table>.titleField
    :name: conf-table-titlefield
    :type: string
    :default: TCA ``ctrl.label`` field

    Field to use as the card title. Falls back to the TCA label field
    if not configured, or ``uid`` if no TCA label exists.

.. _configuration-table-descriptionfield:

..  confval:: mod.web_list.gridView.table.<table>.descriptionField
    :name: conf-table-descriptionfield
    :type: string
    :default: *(empty)*

    Field to display in the card body. Leave empty to hide the
    description area.

.. _configuration-table-imagefield:

..  confval:: mod.web_list.gridView.table.<table>.imageField
    :name: conf-table-imagefield
    :type: string
    :default: *(empty)*

    FAL field for the card thumbnail. Must be a field of type ``file``
    with FAL configuration (``sys_file_reference``).

.. _configuration-table-preview:

..  confval:: mod.web_list.gridView.table.<table>.preview
    :name: conf-table-preview
    :type: boolean
    :default: ``1``

    Enable or disable thumbnail previews for this table. Set to ``0``
    to always show a placeholder icon instead of the image.

.. _configuration-filters:

Record filters
==============

Filters are configured in Page TSconfig and applied to the record-list
query through TYPO3's record-list query event. The UI only submits filter
state; the filtering itself is handled before records are fetched.

TCA is used as metadata for labels and field aliases, while Page TSconfig
decides which filters are visible for each page tree and table.

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.filters {
        enabled = 1
        autoDefaults = title,dateRange,hidden,categories,llm

        table.tx_news_domain_model_news {
            fields = title,dateRange,categories,topNews,hidden,llm

            title {
                type = text
                fields = title,teaser
            }

            topNews {
                type = boolean
                field = istopnews
                label = Top News
                falseLabel = LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:filter.option.no
                trueLabel = LLL:EXT:records_list_types/Resources/Private/Language/locallang.xlf:filter.option.yes
            }
        }
    }

..  confval:: mod.web_list.filters.enabled
    :name: conf-filters-enabled
    :type: boolean
    :default: ``1``

    Enables or disables the filter feature for the current Page TSconfig
    scope.

..  confval:: mod.web_list.filters.autoDefaults
    :name: conf-filters-autodefaults
    :type: string (comma-separated)
    :default: ``title,dateRange,hidden,categories,llm``

    Filter IDs used when a table has no explicit
    :typoscript:`mod.web_list.filters.table.<table>.fields` setting.
    Filters whose backing TCA fields do not exist are skipped
    automatically, so newly added tables usually get useful defaults
    without table-specific TSconfig.

..  confval:: mod.web_list.filters.table.<table>.fields
    :name: conf-filters-table-fields
    :type: string (comma-separated)
    :default: inherited from :typoscript:`autoDefaults`

    Controls which filters are displayed for a table. Use ``none`` to hide
    all filters for a table.

    Built-in aliases:

    -   ``title`` or ``label``: TCA ``ctrl.label`` text filter
    -   ``hidden``: TCA ``ctrl.enablecolumns.disabled`` boolean filter
    -   ``date`` or ``dateRange``: date range filter using common date
        fields or TCA ``ctrl.crdate``
    -   ``category`` or ``categories``: category filter for TYPO3
        many-to-many TCA category fields
    -   ``llm``: optional nr_llm search over resolvable text fields

..  confval:: mod.web_list.filters.table.<table>.<filter>.type
    :name: conf-filters-filter-type
    :type: string
    :default: derived from the filter ID

    Supported types are ``text``, ``boolean``, ``dateRange``, ``select``,
    ``category``, and ``llm``.

LLM search filter
-----------------

The ``llm`` filter is optional and integrates with EXT:nr_llm when that
extension is installed. It sends the current table's candidate records and
the editor's question to the configured nr-llm configuration, then applies
the returned UID list to the record-list query.

By default, the bundled TSconfig uses
``configurationIdentifier = record-list-search``. This must reference the
``identifier`` field of an EXT:nr_llm LLM configuration record. The older
:typoscript:`configuration` key is accepted as an alias.
If EXT:nr_llm is not installed, the identifier is missing, the referenced
configuration is missing or inactive, or no provider is available, the LLM
filter is hidden and the filter panel shows a backend warning explaining
the reason.

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.filters.table.tx_news_domain_model_news {
        fields = title,dateRange,categories,topNews,hidden,llm

        llm {
            type = llm
            configurationIdentifier = record-list-search
            fields = title,teaser,bodytext
            candidateLimit = 80
            resultLimit = 25
        }
    }

.. _configuration-examples:

Common configurations
=====================

News records
------------

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.gridView.table.tx_news_domain_model_news {
        titleField = title
        descriptionField = teaser
        imageField = fal_media
        preview = 1
    }

Frontend users
--------------

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.gridView.table.fe_users {
        titleField = name
        descriptionField = email
        imageField = image
        preview = 1
    }

Content elements
----------------

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.gridView.table.tt_content {
        titleField = header
        descriptionField = bodytext
        imageField = image
        preview = 1
    }

.. _configuration-user-tsconfig:

User TSconfig
=============

Control view mode behavior per user or user group.

.. _configuration-force-view:

Force a specific view
---------------------

Disable the toggle and force a specific view for a user group:

..  code-block:: typoscript
    :caption: User TSconfig

    options.layout.records {
        forceView = grid
    }

Disable alternative views for a user group
------------------------------------------

..  code-block:: typoscript
    :caption: User TSconfig

    mod.web_list.viewMode.allowed = list

.. _configuration-advanced:

Advanced configuration
======================

Disable grid view for specific pages
-------------------------------------

..  code-block:: typoscript
    :caption: Page TSconfig

    [page["uid"] == 123 || page["pid"] == 123]
        mod.web_list.viewMode.allowed = list
    [end]

Different column counts per page type
--------------------------------------

..  code-block:: typoscript
    :caption: Page TSconfig

    # More columns for media folders
    [page["doktype"] == 254]
        mod.web_list.gridView.cols = 6
    [end]

    # Fewer columns for complex records
    [page["module"] == "events"]
        mod.web_list.gridView.cols = 3
    [end]

.. _configuration-resolution:

View mode resolution precedence
================================

The active view mode is determined in this order:

1.  **Request parameter** (:typoscript:`?displayMode=grid`) --
    highest priority
2.  **User preference** (stored in backend user settings)
3.  **Page TSconfig** (:typoscript:`mod.web_list.viewMode.default`)
4.  **Fallback**: ``list``

This means:

-   URL parameters always win (useful for sharing links)
-   User preferences are remembered across sessions
-   TSconfig defines the default for new users
