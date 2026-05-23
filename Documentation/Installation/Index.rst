..  include:: /Includes.rst.txt

.. _installation:

============
Installation
============

The extension is installed via Composer. If Composer cannot find the
package on Packagist, add the GitHub repository as a VCS repository in
your TYPO3 project's root :file:`composer.json`.

.. _installation-composer:

Installation via Composer
=========================

Run the following commands in your TYPO3 project root:

..  code-block:: bash

    composer config repositories.records-list-types vcs https://github.com/dirnbauer/typo3-records-list-types.git
    composer require webconsulting/records-list-types:dev-main

Then set up the extension and clear caches:

..  code-block:: bash

    vendor/bin/typo3 extension:setup -e records_list_types
    vendor/bin/typo3 cache:flush

..  tip::

    In TYPO3 v14 with Composer mode, extensions installed via Composer
    are activated automatically. The ``extension:setup`` command
    performs extension setup such as database migrations.

.. _installation-verification:

Verification
============

After installation, navigate to :guilabel:`Content > Records` in the
TYPO3 backend. You should see view mode toggle buttons (List, Grid,
Compact, Teaser) in the module header.

If the toggle buttons do not appear:

1.  Ensure the extension is activated in
    :guilabel:`Admin Tools > Extensions`
2.  Clear all caches via :guilabel:`Admin Tools > Maintenance > Flush
    Caches`
3.  Check that :typoscript:`mod.web_list.viewMode.allowed` includes
    at least two view modes

.. _installation-default-config:

Default configuration
=====================

The extension ships a default :file:`Configuration/page.tsconfig` that
is loaded automatically in TYPO3 v14. It provides:

-   All four view modes enabled (list, grid, compact, teaser)
-   Default view mode: ``list``
-   Grid layout with 4 columns
-   Pre-configured field mappings for ``pages``, ``tt_content``,
    ``fe_users``, and ``tx_news_domain_model_news``

You can override any of these settings via Page TSconfig. See the
:ref:`configuration` chapter for details.
