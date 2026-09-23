..  include:: /Includes.rst.txt

.. _usage:

=====
Usage
=====

After installation, open :guilabel:`Content > Records` in the TYPO3
backend. The extension adds a :guilabel:`View` dropdown to the module
header when at least two view modes are allowed for the current page.

.. _usage-switch-view:

Switch view mode
================

Choose List view, Grid view, Compact view, Teaser view or a custom view in
the :guilabel:`View` dropdown. The choice is remembered per user and, in
the single-table view, per table. Bookmarks of a view open that view again.

.. _usage-record-actions:

Work with records
=================

Every record offers the actions of the List View, rendered by TYPO3 itself:
edit, hide or unhide, move up and down, delete, and in the :guilabel:`More`
menu info, history, new record after, copy and cut, plus the actions of
other installed extensions. Clicking the record icon opens its context
menu. The title opens the record in the contextual editor.

Hidden records, workspace changes and free-mode translations carry a text
badge in addition to the icon overlay. Records another user is editing show
Core's lock symbol next to the title.

Select records with their checkbox, or with the selection menu above the
records (check all, uncheck all, toggle). The selection bar then offers
Core's bulk actions: edit, edit columns, delete, and clipboard transfer.

.. _usage-filter-records:

Filter records
==============

Select a table and enable :guilabel:`View > Show filters` to display the
configured filter panel. A text filter names the fields it searches below
its input. In LIVE, filters use TYPO3's normal record-list query path. In
workspaces, alternative view modes evaluate the module search term and
active filters after :php:`BackendUtility::workspaceOL()` so draft text,
visibility, date, select, and category changes can be found before
publishing.

.. _usage-toggle-visibility:

Toggle visibility
=================

The visibility button is Core's, and so is the endpoint it calls
(``record_toggle_visibility``, protected by the backend's sudo mode where
TYPO3 requires a password confirmation). On cards the button, the record
icon and the hidden badge change in place, and screen readers announce the
new state. Changes to page visibility also refresh the page tree.

.. _usage-sort-records:

Sort and reorder records
========================

Tables with a TCA ``sortby`` field are listed in manual order by default.
In the Grid View, drag a card to its new place, or use the handle next to
the checkbox with the keyboard: :kbd:`Space` or :kbd:`Enter` grabs the
record, the arrow keys move it, :kbd:`Space` or :kbd:`Enter` drops it and
:kbd:`Escape` cancels. Core's "Move up" and "Move down" buttons work in
every view and need no dragging.

Switch the sorting mode to :guilabel:`By column` to order records by a
field instead; the move buttons are hidden then, as in the List View.

.. _usage-translations:

Translations
============

Select languages in the DocHeader language menu. Cards list one entry per
selected language: existing translations with their icon (context menu),
title and state, missing ones with a button that opens Core's localization
wizard. The Compact View shows the same as indented rows below the record.

.. _usage-workspaces:

Work in workspaces
==================

The alternative views respect TYPO3 workspace restrictions and overlay
records with :php:`BackendUtility::workspaceOL()` before rendering. New,
changed, moved and deleted records show their state as a badge and in the
record icon; records deleted in the workspace are struck through. Tables
that cannot be versioned show Core's notice above their records.

Search and configured filters in alternative views also run after this
overlay when a workspace is active. A word added only in a draft record, for
example, is searchable in the workspace even though the live row does not
contain it yet.

Physical files remain a TYPO3 platform limitation: FAL binaries are not
workspace-versioned. Upload new files with unique names when preparing
workspace changes that involve images or documents.
