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

Redirects follow the ``Referer``
    With ``rejectAction: redirect``, a rejected request is redirected to
    whatever its ``Referer`` says, including other hosts. Use ``403`` if
    that matters to you
    (`#7 <https://github.com/maikschneider/solr-shield/issues/7>`__).

Bot detection is not usable yet
    The client-side script for the behavioural layer is not part of the
    extension. Keep :confval:`setting-botDetection-enabled` disabled
    (`#8 <https://github.com/maikschneider/solr-shield/issues/8>`__).
