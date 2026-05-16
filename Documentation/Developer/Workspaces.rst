..  include:: /Includes.rst.txt

.. _workspaces:

==========
Workspaces
==========

The extension is workspace-aware. Every record fetched by the alternative
view modes is filtered through a :php:`WorkspaceRestriction` and overlaid
with :php:`BackendUtility::workspaceOL()` before the template renders it.
Workspace state is propagated to the grid as a colour hint on each card.

.. _workspaces-state-mapping:

State mapping
=============

TYPO3 v14 uses three :sql:`t3ver_state` values for workspace versions.
The extension maps them to UI states as follows:

.. list-table::
    :header-rows: 1
    :widths: 20 40 40

    *   -   :sql:`t3ver_state`
        -   Core meaning
        -   UI hint

    *   -   :sql:`1`
        -   New record created in the workspace
        -   :guilabel:`new` — blue badge

    *   -   :sql:`2`
        -   Delete placeholder
        -   :guilabel:`deleted` — red badge

    *   -   :sql:`4`
        -   Move pointer
        -   :guilabel:`move` — cyan badge

    *   -   :sql:`0` with :sql:`t3ver_oid > 0`
        -   Modified record (workspace version of live)
        -   :guilabel:`changed` — purple badge

The legacy :sql:`t3ver_state = 3` (old "move placeholder") was removed in
TYPO3 v11 and is not handled.

.. _workspaces-api:

Canonical API usage
===================

The service resolves the active workspace through the
:php:`\TYPO3\CMS\Core\Context\Context` aspect — not via the historical
:php:`$GLOBALS['BE_USER']->workspace` property:

..  code-block:: php
    :caption: Classes/Service/RecordGridDataProvider.php

    $workspaceId = $this->context->getPropertyFromAspect('workspace', 'id', 0);

The same workspace id is used for the restriction:

..  code-block:: php
    :caption: Applying the restriction

    $queryBuilder->getRestrictions()
        ->removeAll()
        ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
        ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

.. _workspaces-known-limitations:

Known limitations
=================

..  warning::

    Physical files under :file:`fileadmin/` are **not** versioned by
    TYPO3. Thumbnails shown in workspace mode are always the live binary.
    Upload new files with unique names when preparing workspace changes
    rather than overwriting existing files.

..  note::

    The extension does not expose publish or stage buttons. Records are
    published through the standard TYPO3 Workspaces module.
