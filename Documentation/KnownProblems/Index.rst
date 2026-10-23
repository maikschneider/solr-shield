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

Bot detection is not usable yet
    The client-side script for the behavioural layer is not part of the
    extension. Keep :confval:`setting-botDetection-enabled` disabled
    (`#8 <https://github.com/maikschneider/solr-shield/issues/8>`__).
