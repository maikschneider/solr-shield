..  _known-problems:

==============
Known problems
==============

Signed links can still be crawled
    Every link Solr Shield signs stays valid, and each result page links to
    further filter combinations. A crawler that follows links reaches every
    combination the site renders, just no others. Free form fields, the
    search term above all, accept any value. Bot detection limits the
    former; caching or rate limiting the search page limits both.

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
