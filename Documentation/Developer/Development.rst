.. include:: /Includes.rst.txt

.. _development:

=================
Local development
=================

.. _development-backend:

Run the backend
===============

The repository includes a DDEV configuration and an isolated Composer project
under :file:`.Build/v14`. The extension is linked from the checkout. Generated site configuration,
dependencies, data and credentials stay outside version control.

.. code-block:: bash
   :caption: From the repository root

   ddev install-v14

On the first run TYPO3 asks for the backend administrator credentials. Open
`the local TYPO3 backend <https://v14.records-list-types.ddev.site/typo3/>`__.
The installation uses TYPO3 14.3.6 or later in v14, PHP 8.3 and MariaDB 10.11.

After changing services or JavaScript, flush caches and reload the backend.
TYPO3 caches service definitions and JavaScript import-map cache-busting URLs.

.. code-block:: bash
   :caption: Refresh the running demo after code changes

   ddev exec .Build/v14/vendor/bin/typo3 cache:flush

.. _development-tests:

Run checks
==========

The extension's development dependencies belong in the repository root;
the demo site's Composer project lives separately under :file:`.Build/v14`.

.. code-block:: bash
   :caption: Extension checks inside DDEV

   ddev exec composer install
   ddev exec Build/Scripts/runTests.sh -s ci
   ddev exec env typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional
   ddev exec vendor/bin/rector process --dry-run --no-progress-bar

For functional tests against MariaDB instead of SQLite:

.. code-block:: bash
   :caption: MariaDB functional tests

   ddev exec env typo3DatabaseHost=db typo3DatabaseName=db \
       typo3DatabaseUsername=root typo3DatabasePassword=root \
       Build/Scripts/runTests.sh -s functional

Use DDEV's local root account because the framework must create databases.
The testing framework creates isolated test databases; it does not use the
demo site's tables. The functional suite includes real backend users, table
visibility checks and rendered Records-module views.

.. _development-updates:

Update dependencies
===================

.. code-block:: bash
   :caption: Update the extension lock and demo site

   ddev exec composer update 'typo3/*' --with-all-dependencies
   ddev composer update 'typo3/*' --with-all-dependencies
   ddev exec .Build/v14/vendor/bin/typo3 extension:setup
   ddev exec .Build/v14/vendor/bin/typo3 cache:flush

After changing templates or JavaScript, check Grid, Compact and Teaser in a
browser as well as running the PHP checks. Test filters, pagination, sorting,
visibility, clipboard actions and keyboard reordering with real records.
