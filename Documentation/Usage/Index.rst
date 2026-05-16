..  include:: /Includes.rst.txt

.. _usage:

=====
Usage
=====

After installation, open :guilabel:`Content > Records` in the TYPO3
backend. The extension adds a view-mode dropdown to the module header
when at least two view modes are allowed for the current page.

.. _usage-switch-view:

Switch view mode
================

Use the view-mode dropdown in the DocHeader to switch between the
standard List View and the alternative Grid, Compact, Teaser, or custom
views. The selected mode is stored in the backend user configuration and
used again on later visits.

.. _usage-filter-records:

Filter records
==============

Select a table and enable :guilabel:`View > Show filters` to display the
configured filter panel. Filters are applied in the same query layer as
the classic List View, so all available view modes render the same
filtered result set.

.. _usage-sort-records:

Sort and reorder records
========================

Tables with a TCA ``sortby`` field support manual drag-and-drop
reordering. Use the sorting mode toggle to switch to field-based sorting
when you need to order records by a specific visible column.

.. _usage-workspaces:

Work in workspaces
==================

The alternative views respect TYPO3 workspace restrictions and overlay
records with :php:`BackendUtility::workspaceOL()` before rendering.
Workspace changes are displayed with state colors for new, modified,
moved, and deleted records.

Physical files remain a TYPO3 platform limitation: FAL binaries are not
workspace-versioned. Upload new files with unique names when preparing
workspace changes that involve images or documents.
