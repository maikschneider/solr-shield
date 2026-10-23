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

..  _how-it-works-range-facets:

Range facets
============

EXT:solr's date and numeric range facets build their URL in the browser:
``facet_daterange_controller.js`` and ``facet_numericrange_controller.js``
replace the ``___FROM___`` and ``___TO___`` placeholders of a URL template
and assign the result to ``window.location``. With URI obfuscation, the
template's ``tx_solr`` parameters sit inside ``_ss``, where these scripts
cannot find the placeholders, and the navigation bypasses ``solr-shield.js``.

The middleware ``maikschneider/solr-shield-html`` therefore points every
``<script>`` tag that loads EXT:solr's copy of these files to patched copies
in ``EXT:solr_shield/Resources/Public/JavaScript/Solr/``, one set for
EXT:solr 13 and one for EXT:solr 14. The patched scripts fill the range into
the ``_ss`` payload and follow the URL through a link click, which
``solr-shield.js`` signs like any other link. It does not matter how the
stock scripts are included, for example through EXT:solr's example site sets
or your own TypoScript.

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

..  _how-it-works-bot-detection:

Bot detection
=============

With :confval:`setting-botDetection-enabled`, the site set includes
``EXT:solr_shield/Resources/Public/JavaScript/solr-shield.js`` in the page
footer. The script counts mouse, touch, scroll and key events and, right
before a Solr form is submitted or a link carrying ``_ss`` is followed, adds
a base64-encoded JSON payload as ``_solr_bot``:

..  code-block:: json

    {
        "elapsed": 12, "webdriver": false,
        "screenWidth": 1920, "screenHeight": 1080,
        "viewportWidth": 1280, "viewportHeight": 800,
        "mouseMoveCount": 30, "touchCount": 0,
        "scrollCount": 4, "keyCount": 6,
        "ts": 1760000000000
    }

The middleware scores every request that carries ``_ss``, except autocomplete
requests, and removes ``_solr_bot`` before TYPO3 resolves the page:

*   Rejected outright: ``webdriver`` is set, or the screen is 800×600, the
    default of many headless browsers.
*   Otherwise ten checks are counted: the two above, at least
    :confval:`setting-botDetection-minFormTimeout` seconds since page load,
    mouse or touch activity (see
    :confval:`setting-botDetection-requireInteraction`), scrolling, key
    presses, a viewport width and height, less than an hour since page load,
    and a timestamp within ten minutes of the server time. At least
    :confval:`setting-botDetection-securityLevel` of them must pass.

A missing or failing payload is rejected like an unsigned request (see
:confval:`setting-uriObfuscation-rejectAction`).
