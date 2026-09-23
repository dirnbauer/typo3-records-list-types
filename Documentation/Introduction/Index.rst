..  include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

The **Records List Types** extension adds view modes to the TYPO3 v14
backend Records module. Next to the table of the List View, editors can
choose layouts that suit the records on a page better.

Every view is built from the Records module's own parts: the table heading
with its actions, Core's control panel for each record (edit, visibility,
move up and down, delete, info, history, clipboard and whatever other
extensions add), the record icon with its context menu, the selection bar,
pagination, localization and workspace states. Only the arrangement of the
records changes.

Features
========

-   **Grid View**: cards with thumbnails, fields and translations, for news,
    products, team members or media
-   **Compact View**: Core's record table in a denser form, with the record
    ID and the translations of a record right below it
-   **Teaser View**: wide cards with title, dates and teaser text
-   **Generic View**: a small template to start a view of your own
-   **Custom views**: register view types with TSconfig or the PSR-14
    ``RegisterViewModesEvent`` (see `Records List Examples
    <https://github.com/dirnbauer/typo3-records-list-examples>`__ for six
    ready-to-use ones)
-   **Reordering**: drag and drop, the keyboard, or Core's "Move up" and
    "Move down" buttons
-   **Record filters**: text, yes/no, select, category and date range
    filters per table, also above the List View
-   **Preferences**: the chosen view is remembered per user and table
-   **Light and dark mode**: styled with TYPO3's design tokens only, so the
    views follow the colour scheme chosen in the backend
-   **Accessible**: keyboard operable, labelled controls, text for every
    state, announcements for changes that happen in place

Requirements
============

-   TYPO3 14.3.7 or later in the v14 series
-   PHP 8.4 or 8.5

View modes
==========

.. _introduction-list-view:

List View
---------

The standard TYPO3 table view. It is always available and unchanged.

.. _introduction-grid-view:

Grid View
---------

One card per record: optional image, title, state badges, the configured
fields, the translations, and Core's controls in the footer.

Best suited for: news articles, products, team members, media assets.

.. _introduction-compact-view:

Compact View
------------

The record table of the List View, denser and with one line per record.
Columns can be sorted from their headers; missing translations are listed
with a button that starts Core's localization wizard.

Best suited for: large datasets, system records, data management.

.. _introduction-teaser-view:

Teaser View
-----------

Wide cards with title, dates and teaser text. Designed for records where a
quick preview helps more than a table.

Best suited for: news, blog posts, events, press releases.
