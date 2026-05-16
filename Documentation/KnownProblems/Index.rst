..  include:: /Includes.rst.txt

.. _known-problems:

==============
Known Problems
==============

.. _known-problems-move-page-into-own-rootline:

"Attempt to move pages to inside of its own rootline"
=====================================================

When attempting to move a page into one of its own subpages via the
Records module, TYPO3 displays the following error:

.. code-block:: text

   This operation could not be completed.
   Technical details: 1: Attempt to move pages:<uid> to inside of its own rootline

.. note::

   In German TYPO3 installations, the message reads:
   *"Dieser Vorgang konnte nicht abgeschlossen werden.
   Technische Details: 1: Attempt to move pages:<uid> to inside of its own rootline"*

**This is not a bug** — it is a technical limitation of TYPO3's page tree
architecture. A page cannot be moved into its own subtree because this
would create a circular reference and break the page hierarchy.

Example
-------

Given a page tree like this:

.. code-block:: text

   Page A (uid=27)
     └── Page B
           └── Page C

Moving *Page A* into *Page B* or *Page C* is impossible because
*Page A* would become a descendant of itself.

Workaround
----------

To restructure the page tree, first move the child pages to a temporary
location, then move the parent page to the desired position, and finally
move the children back underneath it.

.. note::

   This limitation applies to TYPO3 Core regardless of whether this
   extension is installed. It is documented here because the error may
   surface while using the alternative view modes provided by this
   extension.

.. _known-problems-pages-vs-records:

Pages vs. records in Grid View
==============================

In TYPO3, pages are a special kind of record. While "normal" records
(e.g. content elements, news, addresses) have a :sql:`uid` and a
:sql:`pid` that refers to the **page** they are stored on, page records
use :sql:`pid` to refer to their **parent page** in the page tree
hierarchy.

This distinction affects how records are displayed in Grid View:

- **Normal records** show their :sql:`uid` and the page where they are
  located (the :sql:`pid` points to the containing page).
- **Page records** only show their :sql:`uid`, since their :sql:`pid`
  represents the parent page in the tree structure, not a storage
  location.

This is not a limitation of the extension but reflects TYPO3's
underlying data model where pages serve a dual role as both content
containers and tree nodes.

.. _known-problems-grid-drag-pagination:

Drag-and-drop with pagination in Grid View
==========================================

When pagination is active and records span multiple pages, **drag-and-drop
reordering in Grid View cannot move a record to a position that is on a
different pagination page**. Only records visible on the current page can
be reordered relative to each other.

This is an inherent limitation: the drag-and-drop interaction works on
DOM elements that are currently rendered, and records on other pagination
pages are not present in the DOM.

Workaround
----------

-  **Increase items per page** to show all records on a single page:

   ..  code-block:: typoscript

       mod.web_list.viewMode.types.grid.itemsPerPage = 0

   Setting ``itemsPerPage`` to ``0`` disables pagination entirely for
   the Grid View, showing all records at once and enabling unrestricted
   drag-and-drop reordering.

-  **Use the standard List View** for reordering tasks that require
   moving records across large distances in the sort order.

-  **Switch to manual sorting mode** and use the "move after" context
   menu action available in the classic List View, which allows
   targeting any record regardless of pagination.

.. note::

   This limitation applies only to the Grid View, where drag-and-drop
   is the primary reordering mechanism. Compact, Teaser, and custom
   view types do not offer drag-and-drop reordering across pagination
   boundaries either, but their reordering is less prominent in the UI.

.. _known-problems-workspace-support:

Workspace support is experimental
=================================

While the extension includes visual indicators for workspace states
(new, modified, moved, deleted records) and applies workspace overlays
via :php:`BackendUtility::workspaceOL()`, **workspace integration has
not been extensively tested** across all view modes and edge cases.

Potential areas where issues may occur:

- Drag-and-drop reordering of records within a workspace
- Display of workspace-specific record states in Compact and Teaser views
- Publishing or discarding changes made through alternative view modes
- Records with complex versioning histories (multiple successive edits)

If you encounter unexpected behaviour when using this extension in a
workspace environment, please report it as an issue on the
`GitHub issue tracker <https://github.com/dirnbauer/typo3-records-list-types/issues>`__.

Include the following information in your report:

- TYPO3 version and PHP version
- Active view mode (Grid, Compact, Teaser)
- Steps to reproduce the issue
- Expected vs. actual behaviour
- Any error messages from the TYPO3 log or browser console

.. _known-problems-accessibility:

Accessibility of drag-and-drop reordering
=========================================

The Grid View provides keyboard-based drag-and-drop reordering that
aims for WCAG 2.1 compliance. However, **accessibility of the drag-and-drop
interaction has not been tested with a wide range of assistive
technologies** and should be considered a best-effort implementation.

What is implemented
-------------------

- **Keyboard support**: Press :kbd:`Space` or :kbd:`Enter` on a drag
  handle to grab a record, use arrow keys to move it, press
  :kbd:`Space` or :kbd:`Enter` to drop, or :kbd:`Escape` to cancel.
- **ARIA attributes**: ``role="listbox"`` on the grid container,
  ``role="option"`` on cards, ``role="button"`` on drag handles,
  and ``aria-grabbed`` state tracking.
- **Live region announcements**: An ``aria-live="polite"`` region
  announces grab, move ("Position 3 of 12"), drop, and cancel events.
- **Focus management**: Focus returns to the drag handle after a
  completed or cancelled reorder operation.
- **Hidden instructions**: A screen-reader-only element describes the
  keyboard interaction pattern.

Known limitations
-----------------

- The drag-and-drop pattern has primarily been tested with keyboard
  navigation in modern browsers (Chrome, Firefox, Safari). Testing
  with dedicated screen readers (NVDA, JAWS, VoiceOver) has been
  limited.
- Some screen readers may not consistently announce live region
  updates during rapid keyboard navigation.
- The ``aria-grabbed`` attribute is deprecated in WAI-ARIA 1.1 but
  remains in use as a pragmatic solution until broader support for
  the ``aria-roledescription`` pattern is available.
- Touch-based assistive technologies on tablets may not trigger the
  keyboard drag-and-drop path.

Recommendations for critical workflows
---------------------------------------

If drag-and-drop reordering is essential for users who rely on
assistive technology, consider using the standard List View as a
fallback. The List View uses TYPO3 Core's native sorting mechanisms,
which have undergone more extensive accessibility testing.

Administrators can restrict available view modes per page via TSconfig
to ensure only tested views are offered:

.. code-block:: typoscript

   mod.web_list.viewMode.allowed = list,grid

If you encounter accessibility barriers, please report them on the
`GitHub issue tracker <https://github.com/dirnbauer/typo3-records-list-types/issues>`__
with the following details:

- Assistive technology and version (e.g. NVDA 2024.1, VoiceOver on macOS 15)
- Browser and version
- Description of the barrier encountered
- Expected behaviour
