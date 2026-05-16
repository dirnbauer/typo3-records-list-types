..  include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

The **Records List Types** extension adds multiple view modes to the
TYPO3 v14 backend Records module. Instead of only the traditional
table-based List View, editors can choose from several layouts
optimized for different content types.

Features
========

-   **Grid View**: Card-based layout with thumbnails for visual record
    browsing (news, products, team members)
-   **Compact View**: Single-line rows for dense data display with
    sortable columns
-   **Teaser View**: News-style cards with title, date, and description
-   **Custom Views**: Register your own view types via PSR-14 events or
    TSconfig
-   **User preferences**: Selected view mode is persisted per user
-   **Dark mode**: Full compatibility with TYPO3's dark mode
-   **Per-table config**: Configure which fields to display via TSconfig
-   **Bootstrap 5**: Uses TYPO3's built-in Bootstrap 5 components

Requirements
============

-   TYPO3 v14.0 or higher
-   PHP 8.3 or higher

View modes
==========

.. _introduction-list-view:

List View
---------

The standard TYPO3 table view. This is the default view and is always
available. All existing RecordList features work unchanged.

.. _introduction-grid-view:

Grid View
---------

Card-based layout with thumbnails. Each record is displayed as a
Bootstrap 5 card showing the title, description, and image (if
configured). Cards include record action buttons in the footer.

Best suited for: news articles, products, team members, media assets.

.. _introduction-compact-view:

Compact View
------------

Single-line table view with sortable columns. Shows key fields in a
dense layout. Supports column sorting via dropdown.

Best suited for: large datasets, system records, data management.

.. _introduction-teaser-view:

Teaser View
-----------

Minimal cards with title, date, and teaser text. Designed for
content-oriented records where a quick preview is more useful than
a full data table.

Best suited for: news, blog posts, events, press releases.
