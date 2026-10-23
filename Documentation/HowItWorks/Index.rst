..  _how-it-works:

============
How it works
============

..  _how-it-works-payload:

The ``_ss`` parameter
=====================

``_ss`` is base64-encoded JSON with two keys:

``p``
    The ``tx_solr`` part of the query string, e.g.
    ``tx_solr%5Bq%5D=foo&tx_solr%5Bpage%5D=2``.

``s``
    A token: the first 12 bytes of
    ``HMAC-SHA256(encryptionKey, "solr-shield")``, base64url-encoded
    (16 characters).

The token is stateless and does not expire, so filter and pagination links
keep working for as long as the TYPO3 ``encryptionKey`` stays the same.
Rotating the ``encryptionKey`` invalidates every link that is already out
there, including links indexed by search engines.

..  _how-it-works-links:

Links
=====

Two PSR-14 listeners rewrite generated URLs that contain ``tx_solr``
parameters:

``AfterUriIsProcessedEventListener``
    EXT:solr's ``SearchUriBuilder``: facets, pagination and sorting.

``AfterLinkIsGeneratedEventListener``
    Every other ``typolink()``. Links that still contain EXT:solr's
    ``###tx_solr:…###`` placeholders are link templates that EXT:solr fills
    in later; they are left to the first listener.

Other query parameters stay as they are:

..  code-block:: text

    Before: /search?L=1&tx_solr[q]=foo&tx_solr[filter][0]=type:pages
    After:  /search?L=1&_ss=eyJwIjoidHhfc29sciU1QnElNUQ9Zm9v…

..  _how-it-works-forms:

Forms
=====

The middleware ``maikschneider/solr-shield-html`` rewrites HTML responses.
It renames every ``name="tx_solr[`` attribute to ``name="_s[`` and adds a
hidden ``_ss`` field with an empty payload to each form that contains such a
field. Submitting the form sends ``_s[…]`` plus ``_ss``, so the search form
works without JavaScript.

..  _how-it-works-middleware:

Incoming requests
=================

The middleware ``maikschneider/solr-shield`` runs after
``typo3/cms-frontend/site`` and before
``typo3/cms-core/request-token-middleware``:

..  list-table::
    :header-rows: 1

    *   - Request
        - Result
    *   - ``_s[…]`` fields
        - Renamed back to ``tx_solr[…]``
    *   - Valid ``_ss``
        - Payload decoded into the ``tx_solr`` query parameters, ``_ss``
          removed
    *   - ``tx_solr`` parameters without ``_ss``, or an invalid ``_ss``
        - Rejected (see :confval:`setting-uriObfuscation-rejectAction`)
    *   - Suggest request (``type=7384``) without ``_ss``
        - Rejected
    *   - No Solr parameters
        - Passed through
