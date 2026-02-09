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
