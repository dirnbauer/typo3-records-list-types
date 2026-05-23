..  include:: /Includes.rst.txt

.. _architecture:

============
Architecture
============

The extension hooks into the TYPO3 v14 Records module using PSR-14
events and an XClass. It does not modify the core module but augments
it with alternative visualizations.

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
            user prefs > TSconfig

    *   -   :php:`GridConfigurationService`
        -   Parses TSconfig for per-table field mappings, caches results

    *   -   :php:`RecordGridDataProvider`
        -   Fetches records with resolved FAL references for thumbnails

    *   -   :php:`RecordFilterConfigurationService`
        -   Resolves TSconfig and TCA metadata for configurable filters

    *   -   :php:`RecordFilterStateService`
        -   Reads filter visibility and selected values from request/module data

    *   -   :php:`RecordFilterQueryService`
        -   Applies active filters to TYPO3 record-list query builders and,
            in workspace-aware alternative views, to overlaid effective rows

    *   -   :php:`RecordFilterViewDataFactory`
        -   Builds Fluid-ready filter panel data

    *   -   :php:`ThumbnailService`
        -   Generates backend thumbnails using TYPO3's ProcessedFile API

    *   -   :php:`ViewTypeRegistry`
        -   Manages built-in and custom view types from TSconfig/events

    *   -   :php:`MiddlewareDiagnosticService`
        -   Detects middleware configurations that could break rendering

    *   -   :php:`ArrayUtility`
        -   Normalizes TYPO3 TSconfig, request, and TCA arrays at typed
            boundaries for PHPStan max

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

    *   -   :php:`GridViewQueryListener`
        -   ``ModifyDatabaseQueryForRecordListingEvent``
        -   Caches query modifications made by other record-list listeners

    *   -   :php:`GridViewRecordActionsListener`
        -   ``ModifyRecordListRecordActionsEvent``
        -   Observes Core record-action events and keeps helper access for custom templates

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

AJAX preference persistence
============================

When a user clicks a view mode button, JavaScript sends an AJAX
request to :php:`ViewModeController::setViewModeAction()` which
stores the preference in the backend user configuration. The page
then reloads to show the selected view.

.. _architecture-css:

CSS architecture
=================

The extension uses a **base + view-specific** CSS pattern.
``base.css`` is loaded automatically for all view modes (including
custom types) and contains shared components:

-   **Recordlist heading** -- table header bar with title and action
    buttons
-   **Pagination** -- Core list view navigation (record range, page
    input, first/prev/next/last buttons)
-   **Sorting mode toggle** -- segmented control for manual vs.
    field-based sorting
-   **Sorting dropdown** -- field sorting dropdown and disabled state

View-specific files only contain styles unique to that view:

-   ``grid-view.css`` -- card layout, drag-drop, field type formatting,
    workspace state indicators
-   ``compact-view.css`` -- table structure, sticky columns, zebra
    striping, scroll shadows
-   ``teaser-view.css`` -- teaser cards, status badges, meta information

``base.css`` is prepended by :php:`ViewTypeRegistry::getCssFiles()`.
Custom view types receive it automatically -- they only need to provide
their own view-specific CSS.

.. _architecture-bootstrap:

Bootstrap 5 integration
========================

The Grid View uses Bootstrap 5 components included in the TYPO3 v14
backend. The responsive grid uses ``row-cols-*`` classes:

-   ``xs`` (<576px): 1 column (stacked)
-   ``md`` (>=768px): 2 columns
-   ``lg`` (>=992px): 3 columns
-   ``xl`` (>=1200px): configurable (default 4)

All CSS uses TYPO3's CSS custom properties (``--bs-body-bg``,
``--bs-body-color``, ``--bs-border-color``) for automatic dark mode
compatibility.

.. _architecture-native-actions:

Native TYPO3 14 action controls
================================

The alternative record list views deliberately reuse TYPO3 14's native
backend action components instead of inventing a parallel edit-dialog
system.

Visible record edit affordances (title links, edit icons, translation
edit links) use TYPO3's native contextual record edit web component:

-   ``typo3-backend-contextual-record-edit-trigger``

That component receives two URLs:

-   ``edit-url`` -- the full FormEngine route (``record_edit``)
-   ``url`` -- the contextual edit route (``record_edit_contextual``)

This matches TYPO3 core behavior:

-   if the user preference for contextual editing is enabled, TYPO3 opens
    the edit form in the native sheet-style contextual editor
-   if the preference is disabled, TYPO3 falls back to the regular content
    frame edit view

The controller precomputes both URLs in PHP for each record and grouped
translation and exposes them as:

-   ``record.editUrl``
-   ``record.contextualEditUrl``
-   ``translation.editUrl``
-   ``translation.contextualEditUrl``

This avoids fragile inline Fluid route construction for nested
``edit[table][uid]=edit`` parameters.

Additional modal-style actions use TYPO3-native backend controls as well:

-   ``typo3-backend-dispatch-modal-button`` for iframe-based modal tools
    such as move/reposition dialogs
-   standard TYPO3 dispatch actions for info/history/window-manager
    interactions

Visibility toggles
------------------

Inline hide/show buttons do not submit handcrafted DataHandler
``data[table][uid][hidden]`` payloads. They call TYPO3's core
``record_toggle_visibility`` AJAX endpoint through
``@typo3/core/ajax/ajax-request.js``.

Before the POST request is sent, the shared action component adds TYPO3's
``sudoModeInterceptor`` from
``@typo3/backend/security/sudo-mode-interceptor.js``. This keeps the
alternative views aligned with Core record-list behavior for protected
tables or fields. Sensitive records such as backend users and backend user
groups can therefore trigger TYPO3's password/sudo confirmation before the
visibility toggle is executed.

The request body contains only the Core endpoint's expected values:

-   ``table`` -- the table name
-   ``uid`` -- the record UID
-   ``action`` -- either ``hide`` or ``show``

On success the module reloads. For ``pages`` records the shared action
component also dispatches the page-tree refresh event before the reload, so
the backend navigation reflects the changed visibility state.

The action dropdown positioning logic is shared across compact, grid, and
teaser. All variants use the same dropdown hook plus the same
teleport-to-``<body>`` mechanism so TYPO3/Bootstrap menus behave
consistently inside backend overflow and stacking contexts.

.. _architecture-files:

File structure
==============

..  code-block:: text

    Classes/
    ├── Constants.php
    ├── Controller/
    │   ├── Ajax/ViewModeController.php
    │   └── RecordListController.php
    ├── Event/
    │   └── RegisterViewModesEvent.php
    ├── EventListener/
    │   ├── GridViewButtonBarListener.php
    │   ├── GridViewQueryListener.php
    │   ├── GridViewRecordActionsListener.php
    │   ├── RecordFilterAdditionalContentListener.php
    │   ├── RecordFilterButtonBarListener.php
    │   └── RecordFilterQueryListener.php
    ├── Html/
    │   └── BackendFragmentSanitizerBuilder.php
    ├── Pagination/
    │   └── DatabasePaginator.php
    ├── Service/
    │   ├── GridConfigurationService.php
    │   ├── MiddlewareDiagnosticService.php
    │   ├── RecordFilterConfigurationService.php
    │   ├── RecordFilterQueryService.php
    │   ├── RecordFilterStateService.php
    │   ├── RecordFilterViewDataFactory.php
    │   ├── RecordGridDataProvider.php
    │   ├── ThumbnailService.php
    │   ├── ViewModeResolver.php
    │   └── ViewTypeRegistry.php
    ├── Utility/
    │   └── ArrayUtility.php
    └── ViewHelpers/
        └── RecordActionsViewHelper.php

    Configuration/
    ├── Backend/AjaxRoutes.php
    ├── Icons.php
    ├── JavaScriptModules.php
    ├── Services.yaml
    └── page.tsconfig

    Resources/
    ├── Private/
    │   ├── Language/
    │   ├── Layouts/
    │   ├── Partials/
    │   └── Templates/
    └── Public/
        ├── Css/
        └── JavaScript/
