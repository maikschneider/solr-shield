..  _known-problems:

==============
Known problems
==============

The token does not cover the payload
    The token in ``_ss`` is the same for every link and appears on every
    search page, so a client that reads one page can build ``_ss`` values
    for any parameter combination. Solr Shield stops crawlers that follow or
    mutate ``tx_solr`` links, not a bot written against it
    (`#6 <https://github.com/maikschneider/solr-shield/issues/6>`__).

Redirects follow the ``Referer``
    With ``rejectAction: redirect``, a rejected request is redirected to
    whatever its ``Referer`` says, including other hosts. Use ``403`` if
    that matters to you
    (`#7 <https://github.com/maikschneider/solr-shield/issues/7>`__).

Bot detection excludes visitors without JavaScript
    With :confval:`setting-botDetection-enabled`, a search without the
    behavioural payload is rejected. That includes visitors who block
    JavaScript.

Custom range facet scripts are not patched
    Only EXT:solr's own ``facet_daterange_controller.js`` and
    ``facet_numericrange_controller.js`` are swapped for the patched copies
    (see :ref:`how-it-works-range-facets`). If your site package ships its
    own range facet script or a modified copy of EXT:solr's, or bundles them
    into one file, range filters break with URI obfuscation: the
    ``___FROM___`` and ``___TO___`` placeholders are inside ``_ss``, and a
    navigation through ``window.location`` carries no ``_solr_bot``. Port the
    two changes marked ``Solr Shield`` in
    ``EXT:solr_shield/Resources/Public/JavaScript/Solr/`` to your script:
    fill the range with ``solrShieldFillUrl()`` and navigate with
    ``solrShieldNavigate()``.

Bot detection payloads can be replayed
    The payload is generated in the browser and is not signed. A bot that
    records one from a real browser can send it again for up to ten minutes,
    or fake one altogether. Bot detection raises the cost of scripted
    crawling; it does not prove a human is present.
