..  include:: /Includes.rst.txt

.. _architecture:

============
Architecture
============

The extension hooks into the TYPO3 v14 Records module with PSR-14 events and
one XClass, of :php:`TYPO3\CMS\Backend\Controller\RecordListController`.
The List View stays Core's; the other views are rendered by the same
controller flow (DocHeader, clipboard, search, page translations, record
identity) with a different Fluid template for the records.

:php:`AlternativeDatabaseRecordList` extends Core's
:php:`DatabaseRecordList` and hands the views what the List View renders:

-   the tables to list, their heading actions and the selection bar
-   per record :php:`renderRecordIcon()` (icon with state overlays and the
    context menu trigger), :php:`renderRecordControls()` (Core's
    :php:`makeControl()`, including the actions of
    ``ModifyRecordListRecordActionsEvent`` listeners) and the lock message
-   :php:`prepareManualSorting()`, the neighbour bookkeeping Core needs for
    "Move up" and "Move down"

Every Core link keeps the view, its filters and its sorting:
:php:`setOverrideUrlParameters()` adds them to :php:`listURL()`, which Core
uses for return URLs and redirects. Records are queried with Core query
builders; workspace overlays are applied before the data reaches Fluid.

.. _architecture-services:

Services
========

.. list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Service
        -   Responsibility

    *   -   :php:`ViewModeResolver`
        -   Determines active view mode based on request params >
            user prefs > TSconfig. The built-in modes come from
            :php:`ViewTypeRegistry::BUILTIN_TYPES`.

    *   -   :php:`GridConfigurationService`
        -   Parses TSconfig for per-table field mappings, caches results

    *   -   :php:`RecordGridDataProvider`
        -   Enriches Core record-list rows with thumbnails, icons and workspace state

    *   -   :php:`RecordFilterConfigurationService`
        -   Resolves TSconfig and TCA metadata for configurable filters

    *   -   :php:`RecordFilterStateService`
        -   Reads filter visibility and selected values from request/module data

    *   -   :php:`RecordFilterQueryService`
        -   Applies active filters to TYPO3 record-list query builders and,
            in workspace-aware alternative views, to overlaid effective rows

    *   -   :php:`RecordFilterViewDataFactory`
        -   Builds Fluid-ready filter panel data

    *   -   :php:`TcaTableConfigurationService`
        -   The single place that turns a TCA field into a localized column
            label, and the only one that knows how TYPO3 labels system
            columns such as ``crdate``, ``tstamp``, ``pid`` or ``sortby``

    *   -   :php:`ListSortingViewFactory`
        -   Builds the sorting-mode toggle, the field-sorting dropdown, the
            sortable column headers and the bulk-edit header

    *   -   :php:`RecordDisplayValueFormatter`
        -   Turns a field value into the text the List View shows for it
            (:php:`BackendUtility::getProcessedValueExtra()`)

    *   -   :php:`ThumbnailService`
        -   Generates backend thumbnails using TYPO3's ProcessedFile API

    *   -   :php:`ViewTypeRegistry`
        -   Manages built-in and custom view types from TSconfig/events

    *   -   :php:`ArrayUtility`
        -   Normalizes TYPO3 TSconfig, request, and TCA arrays at typed
            boundaries for PHPStan level 8

    *   -   :php:`DatabasePaginator`
        -   Paginator for pre-fetched database records, extending
            :php:`TYPO3\CMS\Core\Pagination\AbstractPaginator`

.. _architecture-listeners:

Event listeners
===============

.. list-table::
    :header-rows: 1
    :widths: 30 30 40

    *   -   Listener
        -   Event
        -   Purpose

    *   -   :php:`GridViewButtonBarListener`
        -   ``ModifyButtonBarEvent``
        -   Injects the view-mode dropdown into the DocHeader

    *   -   :php:`RecordFilterButtonBarListener`
        -   ``ModifyButtonBarEvent``
        -   Adds the :guilabel:`Show filters` menu entry

    *   -   :php:`RecordFilterAdditionalContentListener`
        -   ``RenderAdditionalContentToRecordListEvent``
        -   Renders the filter panel above the classic List View

    *   -   :php:`RecordFilterQueryListener`
        -   ``ModifyDatabaseQueryForRecordListingEvent``
        -   Applies configured filters to TYPO3 record-list queries, deferring
            workspace-sensitive evaluation for alternative view modes

.. _architecture-resolution:

View mode resolution
====================

The view mode is determined with strict precedence:

1.  Request parameter ``?displayMode=grid`` (highest priority, also
    saves the preference)
2.  User preference stored in ``$BE_USER->uc['records_view_mode']``
3.  Page TSconfig ``mod.web_list.viewMode.default``
4.  Fallback: ``list``

.. _architecture-ajax:

Preference persistence
======================

The view dropdown links to the Records module with ``displayMode`` and
the current filter, search, and sorting parameters. The controller stores
the selected mode in the backend user configuration when handling that
request. :php:`ViewModeController` also exposes AJAX endpoints for custom
integrations that need to read or change the preference.

.. _architecture-css:

CSS architecture
================

The views are built from TYPO3's own components -- ``.recordlist``,
``.card``, ``.table``, ``.badge``, ``.list-group``, ``.pagination`` -- and
style what is left with TYPO3's design tokens (``--typo3-*``) only. There is
no colour of the extension's own and no ``prefers-color-scheme`` query, so
light and dark mode follow the colour scheme chosen in the backend.
:file:`Tests/Unit/Asset/StylesheetContractTest.php` keeps it that way.

-   ``base.css`` -- loaded for every view and with the filter panel:
    toolbar, pagination bar, empty state, record parts (icon, badges,
    controls, meta line), card translations, reordering, filters
-   ``grid-view.css``, ``compact-view.css``, ``teaser-view.css`` -- the
    layout of each built-in template

:php:`ViewTypeRegistry::getCssFiles()` returns ``base.css``, then the
stylesheet of the built-in template a view type renders, then the view
type's own ``css``. A custom type that reuses ``CompactView`` therefore needs
no ``css`` setting.

.. _architecture-native-actions:

Native TYPO3 14 controls
========================

The views reuse TYPO3's backend controls instead of a parallel set:

-   record actions: Core's control panel (:php:`makeControl()`); its
    overflow menu is turned into a popover so a card cannot clip it
-   record icon: :php:`IconFactory::getIconForRecord()` wrapped in Core's
    context menu trigger
-   title: ``typo3-backend-contextual-record-edit-trigger`` with
    ``record.editUrl`` (FormEngine) and ``record.contextualEditUrl`` (the v14
    contextual editor); the preference of the editor decides which opens
-   missing translations: ``typo3-backend-localization-button``
-   selection: Core's multi-record selection markup and bulk actions
-   menus: ``.dropdown-menu[popover]`` with ``popovertarget``; Core's
    :file:`dropdown.js` positions them and handles the keyboard

On table rows Core's :file:`recordlist.js` toggles visibility. Cards are no
table rows, so :file:`GridViewActions.js` handles the visibility button there
with the same endpoint (``record_toggle_visibility``, behind the
``sudoModeInterceptor``) and updates the button, the record icon and the
hidden badge in place. The module also handles reordering (drag and drop and
keyboard), the page number input and the ``data-gridview-action`` buttons of
templates written for 1.x. Its listeners are bound to its own element, so the
records and the page translations on one screen never handle an event twice.

.. _architecture-labels:

Labels
======

All catalogs are XLIFF 2.0 under :file:`Resources/Private/Language/`, with
:file:`locallang.xlf` as the English source and locale-prefixed target files
for German, French, Spanish and Italian. References use the TYPO3 v14 domain
syntax (``records_list_types.messages:<key>``), never ``LLL:EXT:``.

Two rules decide where a label comes from:

Core owns its data model
    Column labels for system fields use core references
    (``core.general:LGL.creationDate``, ``core.core:labels.sorting``, …), so a
    column header reads the same here as in the Core list view and the record
    info panel. Those strings follow the installed core language packs; install
    the language pack for a backend language to see them translated.

This extension owns its own chrome
    Everything the extension renders itself -- the view switcher, sorting
    controls, filter panel, pagination, empty states, drag-and-drop
    announcements, workspace badges -- comes from this extension's catalog, so
    it is translated in all five languages regardless of which core language
    packs are installed.

:php:`TcaTableConfigurationService::translateTcaLabel()` is the only place that
resolves a label reference. It exists because
:php:`LanguageService::sL()` returns its input unchanged when a reference
cannot be resolved: a missing key would otherwise be printed verbatim instead
of falling back. :file:`Tests/Unit/Language/LabelCatalogTest.php` fails the
build when a referenced core label does not exist, when a target file drops a
placeholder, when two keys carry the same English text, or when a German label
outgrows the control it sits in.
