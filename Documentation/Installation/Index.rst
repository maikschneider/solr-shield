..  _installation:

============
Installation
============

Install the extension via Composer:

..  code-block:: bash

    composer require maikschneider/solr-shield

Then add the site set **Solr Shield** (``maikschneider/solr-shield``) to
your site in :guilabel:`Site Management > Sites`, or declare it as a
dependency of your site package's set:

..  code-block:: yaml
    :caption: packages/my-sitepackage/Configuration/Sets/MySet/config.yaml

    dependencies:
      - maikschneider/solr-shield

Flush all caches afterwards, since cached pages still contain the old
``tx_solr`` links and form fields.

..  _installation-requirements:

Requirements
============

*   PHP 8.2 or later
*   TYPO3 13.4 LTS or 14.3
*   EXT:solr 13 (TYPO3 13.4) or 14 (TYPO3 14.3)
