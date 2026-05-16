..  include:: /Includes.rst.txt

.. _configuration-filters:

==============
Record filters
==============

Record filters add an optional filter panel to the TYPO3 Records module.
Editors enable the panel through :guilabel:`View > Show filters` after a
specific table has been selected. The panel is intentionally table-scoped:
multi-table page overviews keep their compact overview, while single-table
views can expose fields that fit the selected record type. Its visibility is
stored in the user's Records module settings, like search and clipboard.

Filters are configured with Page TSconfig and use TCA as metadata for labels,
field aliases, and field availability. The submitted UI state is applied in
the query layer before records are fetched. This means the same filtered result
set is used by the classic List View and by Grid, Compact, Teaser, and custom
view types.

If filters or search return no records, the selected table section and filter
panel remain visible with an empty-result notice.

.. _configuration-filters-minimal:

Minimal setup
=============

The extension ships useful defaults, so most installations do not need
table-specific TSconfig:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.filters {
        enabled = 1
        autoDefaults = title,dateRange,hidden,categories
    }

The default IDs are resolved against each selected table. Filters whose backing
TCA fields do not exist are skipped automatically. This keeps the setup useful
for new tables added later without requiring a matching TSconfig block.

.. _configuration-filters-table:

Table-specific filters
======================

Use :typoscript:`mod.web_list.filters.table.<table>` only when a table needs
different filters, better labels, or field-specific search configuration.

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.filters.table.tx_news_domain_model_news {
        fields = title,dateRange,categories,topNews,hidden

        title {
            type = text
            fields = title,teaser
        }

        dateRange {
            field = datetime
        }

        topNews {
            type = boolean
            field = istopnews
            label = Top News
            falseLabel = No
            trueLabel = Yes
        }
    }

.. _configuration-filters-reference:

TSconfig reference
==================

..  confval:: mod.web_list.filters.enabled
    :name: conf-filters-enabled
    :type: boolean
    :default: ``1``

    Enables or disables the filter feature for the current Page TSconfig
    scope.

..  confval:: mod.web_list.filters.autoDefaults
    :name: conf-filters-autodefaults
    :type: string (comma-separated)
    :default: ``title,dateRange,hidden,categories``

    Filter IDs used when a table has no explicit
    :typoscript:`mod.web_list.filters.table.<table>.fields` setting.

..  confval:: mod.web_list.filters.table.<table>.enabled
    :name: conf-filters-table-enabled
    :type: boolean
    :default: ``1``

    Enables or disables filters for a single table.

..  confval:: mod.web_list.filters.table.<table>.fields
    :name: conf-filters-table-fields
    :type: string (comma-separated)
    :default: inherited from :typoscript:`autoDefaults`

    Controls which filters are displayed for a table. Use ``none`` to hide all
    filters for one table.

..  confval:: mod.web_list.filters.table.<table>.<filter>.enabled
    :name: conf-filters-filter-enabled
    :type: boolean
    :default: ``1``

    Enables or disables a single filter.

..  confval:: mod.web_list.filters.table.<table>.<filter>.type
    :name: conf-filters-filter-type
    :type: string
    :default: derived from the filter ID

    Supported types are ``text``, ``boolean``, ``dateRange``, ``select``,
    and ``category``.

..  confval:: mod.web_list.filters.table.<table>.<filter>.field
    :name: conf-filters-filter-field
    :type: string
    :default: derived from the filter ID

    Database/TCA field used by ``boolean``, ``dateRange``, ``select``, and
    ``category`` filters. Field aliases are resolved before use.

..  confval:: mod.web_list.filters.table.<table>.<filter>.fields
    :name: conf-filters-filter-fields
    :type: string (comma-separated)
    :default: derived from the filter ID

    Fields searched by ``text`` filters. Non-existing fields are skipped.

.. _configuration-filters-aliases:

Built-in aliases
================

..  list-table::
    :header-rows: 1
    :widths: 20 30 50

    *   -   Alias
        -   Type
        -   Behavior

    *   -   ``title`` or ``label``
        -   ``text``
        -   Searches the TCA ``ctrl.label`` field, or the configured
            ``fields`` list.

    *   -   ``hidden``
        -   ``boolean``
        -   Uses TCA ``ctrl.enablecolumns.disabled`` and shows
            :guilabel:`Any`, :guilabel:`Visible`, and :guilabel:`Hidden`.

    *   -   ``date`` or ``dateRange``
        -   ``dateRange``
        -   Uses common date fields such as ``datetime``, ``date``,
            ``starttime``, or TCA ``ctrl.crdate``.

    *   -   ``category`` or ``categories``
        -   ``category``
        -   Uses the first TYPO3 many-to-many category field found on the
            table.

.. _configuration-filters-category:

Category filter
===============

The category filter is generic for TYPO3 tables that use a many-to-many
category field with ``sys_category_record_mm``. It ignores ``sys_category``
itself and skips tables without a compatible category field.

Category options are grouped by default-language category. Available
translations are appended in brackets. Selecting one option searches for the
default category UID and all translated category UIDs belonging to that
category. Long translated labels are shortened visually, but the full label is
available through the option title.
