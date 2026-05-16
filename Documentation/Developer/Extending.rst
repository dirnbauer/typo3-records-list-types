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

The Grid View automatically displays all actions registered via
``ModifyRecordListRecordActionsEvent``. To add custom actions:

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

.. _extending-thumbnails:

Custom thumbnail logic
======================

For special image handling, override the thumbnail service in
:file:`Configuration/Services.yaml`:

..  code-block:: yaml
    :caption: Configuration/Services.yaml

    services:
      Webconsulting\RecordsListTypes\Service\ThumbnailService:
        class: YourVendor\YourExtension\Service\CustomThumbnailService

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

Override styles in your extension:

..  code-block:: css
    :caption: EXT:your_extension/Resources/Public/Css/custom.css

    .recordlist-gridview-card {
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

..  important::

    Always use CSS custom properties for colors to maintain dark mode
    compatibility:

    ..  code-block:: css

        .my-element {
            /* Correct: adapts to dark mode */
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);

            /* Wrong: breaks dark mode */
            /* background-color: #ffffff; */
        }

.. _extending-javascript:

JavaScript hooks
================

The view switcher dispatches a custom event you can listen to:

..  code-block:: javascript
    :caption: Resources/Public/JavaScript/my-extension.js

    document.addEventListener(
        'recordsGridview:viewModeChanged',
        (event) => {
            console.log('View mode:', event.detail.mode);
        },
    );

.. _extending-troubleshooting:

Troubleshooting
===============

Grid View toggle not appearing
-------------------------------

1.  Ensure the extension is activated
2.  Check that ``mod.web_list.viewMode.allowed`` includes at least
    two view modes
3.  Clear all caches

Thumbnails not showing
----------------------

1.  Verify the ``imageField`` in TSconfig points to a valid FAL field
2.  Ensure ``preview = 1`` is set for the table
3.  Check that the records have images attached

Middleware warning
------------------

If you see a middleware warning, a custom middleware may be interfering
with the response. Check the middleware stack in
:guilabel:`Admin Tools > Configuration > HTTP Middlewares`.
