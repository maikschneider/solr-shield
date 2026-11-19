..  _start:

===========
Solr Shield
===========

:Extension key:
   solr_shield

:Package name:
   maikschneider/solr-shield

:Version:
   |release|

:Language:
   en

:Author:
   Maik Schneider & Contributors

:License:
   This document is published under the
   `Open Publication License <https://www.opencontent.org/openpub/>`__.

:Rendered:
   |today|

----

Solr Shield protects the search endpoint of
`EXT:solr <https://extensions.typo3.org/extension/solr>`__ from bots and
crawlers that enumerate ``tx_solr[…]`` parameters and flood the site with
thousands of unique, uncacheable search requests.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        The problem and what Solr Shield does about it.

    ..  card:: :ref:`Installation <installation>`

        Composer and the site set.

    ..  card:: :ref:`Configuration <configuration>`

        Site settings.

    ..  card:: :ref:`How it works <how-it-works>`

        The signed ``_ss`` parameter, the form rewrite and the middleware.

    ..  card:: :ref:`Integration <integration>`

        Custom templates, forms and JavaScript.

    ..  card:: :ref:`Known problems <known-problems>`

        Current limitations.

..  Table of Contents

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    HowItWorks/Index
    Integration/Index
    KnownProblems/Index
