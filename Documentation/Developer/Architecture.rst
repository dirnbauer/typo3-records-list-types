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

    *   -   :php:`ThumbnailService`
        -   Generates backend thumbnails using TYPO3's ProcessedFile API

    *   -   :php:`ViewTypeRegistry`
        -   Manages built-in and custom view types from TSconfig/events

    *   -   :php:`MiddlewareDiagnosticService`
        -   Detects middleware configurations that could break rendering

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
        -   Injects List/Grid/Compact/Teaser toggle into DocHeader

    *   -   :php:`GridViewQueryListener`
        -   ``ModifyDatabaseQueryForRecordListingEvent``
        -   Ensures Grid View respects query modifications

    *   -   :php:`GridViewRecordActionsListener`
        -   ``ModifyRecordListRecordActionsEvent``
        -   Bridges record actions to card footers

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
    │   └── GridViewRecordActionsListener.php
    ├── Service/
    │   ├── GridConfigurationService.php
    │   ├── MiddlewareDiagnosticService.php
    │   ├── RecordGridDataProvider.php
    │   ├── ThumbnailService.php
    │   ├── ViewModeResolver.php
    │   └── ViewTypeRegistry.php
    └── ViewHelpers/
        └── RecordActionsViewHelper.php
