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

Bot detection excludes visitors without JavaScript
    With :confval:`setting-botDetection-enabled`, a search without the
    behavioural payload is rejected. That includes visitors who block
    JavaScript.

Custom range facet scripts are not patched
    Only EXT:solr's own ``facet_daterange_controller.js`` and
    ``facet_numericrange_controller.js`` are swapped for the patched copies
    (see :ref:`how-it-works-range-facets`). Range filters in your own,
    modified or bundled copies break with URI obfuscation until you port the
    changes (see :ref:`integration-copied-scripts`).

Bot detection payloads can be replayed
    The payload is generated in the browser and is not signed. A bot that
    records one from a real browser can send it again for up to ten minutes,
    or fake one altogether. Bot detection raises the cost of scripted
    crawling; it does not prove a human is present.
