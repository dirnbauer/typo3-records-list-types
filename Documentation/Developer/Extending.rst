..  include:: /Includes.rst.txt

.. _extending:

=========
Extending
=========

This chapter explains how developers can customize and extend the
Records List Types extension.

.. _extending-actions:

Custom record actions
=====================

The built-in card templates render TYPO3 v14 edit URLs and DataHandler based
actions from the view data prepared by
:php:`Webconsulting\\RecordsListTypes\\Controller\\RecordListController`.

For TYPO3 Core list-module actions, use
``ModifyRecordListRecordActionsEvent`` as usual:

..  code-block:: php
    :caption: Classes/EventListener/CustomRecordActionListener.php

    <?php

    declare(strict_types=1);

    namespace YourVendor\YourExtension\EventListener;

    use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
    use TYPO3\CMS\Core\Attribute\AsEventListener;

    #[AsEventListener]
    final class CustomRecordActionListener
    {
        public function __invoke(
            ModifyRecordListRecordActionsEvent $event,
        ): void {
            if ($event->getTable() === 'tx_yourext_domain_model_item') {
                $event->setAction(
                    action: '<a href="..." title="My Action">...</a>',
                    actionName: 'myCustomAction',
                    group: 'primary',
                    after: 'edit',
                );
            }
        }
    }

..  note::

    ``ModifyRecordListRecordActionsEvent`` customizes the standard List View.
    Grid, Compact, and Teaser do not render arbitrary Core action fragments.
    Add custom card actions in a custom Fluid template configured via Page
    TSconfig, or expose the needed action URLs in your own view data.

.. _extending-thumbnails:

Custom thumbnail logic
======================

Configure the FAL field used for previews in Page TSconfig:

..  code-block:: typoscript
    :caption: Page TSconfig

    mod.web_list.gridView.table.tx_yourext_domain_model_item {
        imageField = image
        preview = 1
    }

For different presentation, register a :ref:`custom view type
<custom-view-types>` with your own Fluid template. The internal
:php:`ThumbnailService` is final and cannot be replaced by a subclass.

.. _extending-disable:

Disable views for specific pages
=================================

Via TSconfig
------------

..  code-block:: typoscript
    :caption: Page TSconfig

    [page["uid"] == 123]
        mod.web_list.viewMode.allowed = list
    [end]

Via event listener
------------------

Listen to ``ModifyButtonBarEvent`` and conditionally remove the
toggle buttons:

..  code-block:: php
    :caption: Classes/EventListener/ConditionalGridViewListener.php

    <?php

    declare(strict_types=1);

    namespace YourVendor\YourExtension\EventListener;

    use TYPO3\CMS\Backend\Template\Components\ButtonBar;
    use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
    use TYPO3\CMS\Core\Attribute\AsEventListener;
    use Webconsulting\RecordsListTypes\EventListener\GridViewButtonBarListener;

    #[AsEventListener(
        after: GridViewButtonBarListener::class,
    )]
    final class ConditionalGridViewListener
    {
        public function __invoke(ModifyButtonBarEvent $event): void
        {
            if ($this->shouldHideGridView()) {
                $buttons = $event->getButtons();
                unset(
                    $buttons[ButtonBar::BUTTON_POSITION_RIGHT][1],
                );
                $event->setButtons($buttons);
            }
        }

        private function shouldHideGridView(): bool
        {
            return false;
        }
    }

.. _extending-css:

CSS customization
=================

Override styles in your extension, with TYPO3's design tokens:

..  code-block:: css
    :caption: EXT:your_extension/Resources/Public/Css/custom.css

    .rlt-card {
        border-radius: var(--typo3-component-border-radius);
        box-shadow: var(--typo3-component-box-shadow-strong);
    }

..  important::

    Use the ``--typo3-*`` tokens for every colour. They follow the colour
    scheme editors choose in the backend. Hex values, ``--bs-*`` variables
    and ``prefers-color-scheme`` queries do not: the backend sets its scheme
    on ``<html>``, independent of the operating system.

    ..  code-block:: css

        .my-element {
            /* Correct: follows the backend colour scheme */
            background-color: var(--typo3-surface-container-low);
            color: var(--typo3-text-color-base);

            /* Wrong: breaks dark mode */
            /* background-color: #ffffff; */
        }

.. _extending-javascript:

JavaScript
==========

``GridViewActions.js`` registers the ``<records-list-types-actions>``
element. Wrap a custom template in it to get visibility changes in place on
cards, reordering, the page number input and the 1.x
``data-gridview-action`` buttons. Core's scripts (context menu, selection,
localization, clipboard) need no wrapper.

..  code-block:: html
    :caption: Resources/Private/Templates/MyView.html

    <records-list-types-actions class="rlt-view">
        <!-- custom view markup -->
    </records-list-types-actions>

.. _extending-troubleshooting:

Troubleshooting
===============

View dropdown not appearing
---------------------------

1.  Ensure the extension is activated
2.  Check that ``mod.web_list.viewMode.allowed`` includes at least
    two view modes
3.  Clear all caches

Thumbnails not showing
----------------------

1.  Verify the ``imageField`` in TSconfig points to a valid FAL field
2.  Ensure ``preview = 1`` is set for the table
3.  Check that the records have images attached
