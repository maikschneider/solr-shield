..  _integration:

===========
Integration
===========

Solr Shield needs no configuration as long as a site uses EXT:solr's own
templates, partials and scripts. Once you customise them, keep the points
below in mind: it is easy to reintroduce plain ``tx_solr`` URLs, which
Solr Shield then rejects.

..  _integration-templates:

Templates and partials
======================

URLs built with EXT:solr's view helpers (``s:uri.*``) or through
``typolink()``, for example with ``f:link.page`` or ``f:uri.page`` and
``additionalParams``, are encoded into ``_ss`` (see
:ref:`how-it-works-links`).

Hand-written URLs are not:

..  code-block:: html
    :caption: Rejected when followed

    <a href="/search?tx_solr[filter][0]=type:pages">Pages only</a>

The same goes for URLs assembled in ``f:format.raw`` output, data attributes
or inline JSON. Build every Solr URL with a view helper.

..  _integration-forms:

Forms
=====

The HTML middleware renames ``name="tx_solr[…]"`` fields in the rendered
HTML to ``_s[…]`` and adds the signed ``_ss`` field to their form (see
:ref:`how-it-works-forms`). This only covers markup that is in the response:

*   Fields that JavaScript adds or renames after the page has loaded keep
    their ``tx_solr[…]`` name, and the request is rejected. Render the
    fields on the server and show or hide them instead.
*   A form that only gets Solr fields through JavaScript has no ``_ss``.
*   Fields outside the form, for example a facet in a sidebar, are signed for
    the form whose ``id`` their ``form`` attribute names:

    ..  code-block:: html

        <form id="tx-solr-search-form-pi-results" action="/search">
            <input type="text" name="tx_solr[q]">
        </form>
        <input type="hidden" name="tx_solr[filter][]" form="tx-solr-search-form-pi-results">

..  _integration-autocomplete:

Autocomplete
============

Keep EXT:solr's ``data-suggest`` attribute on the search form. Its URL gets
a signed ``_ss`` on the server, and a suggest request (``type=7384``)
without one is rejected. Suggest requests accept the typed
``tx_solr[queryString]`` as it is.

With :confval:`setting-botDetection-enabled`, suggest requests are scored
like searches. ``solr-shield.js`` adds ``_solr_bot`` when the autocomplete
script sends the request through ``XMLHttpRequest`` or ``fetch()``, so
EXT:solr's suggest controllers and custom autocomplete scripts need no
changes. Load the request URL from ``data-suggest`` at request time or
store it once, both work: the payload is added to the request, not to the
attribute.

..  _integration-javascript:

JavaScript
==========

Following a real link that carries ``_ss`` or submitting a form with the
``_ss`` field works without extra code: ``solr-shield.js`` attaches the bot
detection payload ``_solr_bot`` right before the browser sends the request,
including for ``form.submit()``. Scripts that read a link's ``href`` in a
click handler, like EXT:solr's Ajaxify example, get the signed URL.

Code that builds a search URL itself or loads one without a link click
needs care:

*   Never build ``tx_solr`` parameters in the browser. Take the URL from a
    link or data attribute rendered on the server, so it already carries
    ``_ss``, and keep that parameter.
*   ``fetch()`` and ``XMLHttpRequest`` calls to a same-origin URL that
    carries ``_ss`` get a fresh ``_solr_bot`` from ``solr-shield.js``, so
    loading a signed result URL via AJAX (for example ``$.ajax`` for a map
    of the results) works. Requests to other origins and ``Request`` objects
    with a body are left alone.
*   ``window.location`` assignments bypass ``solr-shield.js``. With
    :confval:`setting-botDetection-enabled`, such a request has no
    ``_solr_bot`` and is rejected. Navigate through a link click instead:

    ..  code-block:: javascript

        function solrShieldNavigate(url) {
          const link = document.createElement('a');
          link.href = url;
          link.hidden = true;
          document.body.appendChild(link);
          link.click();
          link.remove();
        }

..  _integration-placeholders:

Placeholders and custom facet types
===================================

Some URLs are templates that the browser completes, for example the
``___FROM___`` and ``___TO___`` placeholders of range facets. With URI
obfuscation these placeholders sit inside the base64-encoded ``_ss``
payload, and its signature breaks as soon as the payload changes. Leave
``_ss`` as it is and send the values as ``_sr[from]`` and ``_sr[to]``, as the
patched range facet scripts do. The middleware fills them into the
``___FROM___`` and ``___TO___`` placeholders of the signed parameters and
accepts numbers and empty values only. An empty value leaves that end of the
range open, as EXT:solr's date range decoder turns it into ``*``
(``_sr[to]=`` yields ``202612010000-``). EXT:solr's numeric range decoder
needs both bounds:

..  code-block:: javascript

    function solrShieldFillUrl(template, values) {
      const url = new URL(template, window.location.href);
      if (url.searchParams.get('_ss') === null) {
        return Object.keys(values).reduce(
          (result, placeholder) => result.replace(placeholder, String(values[placeholder])),
          template
        );
      }
      url.searchParams.set('_sr[from]', String(values.___FROM___));
      url.searchParams.set('_sr[to]', String(values.___TO___));
      return url.toString();
    }

Other placeholders cannot be filled in the browser with URI obfuscation.

EXT:solr's own ``###tx_solr:…###`` link templates are filled in on the
server before encoding and need nothing.

..  _integration-copied-scripts:

Copied or bundled EXT:solr scripts
==================================

Only EXT:solr's own ``facet_daterange_controller.js`` and
``facet_numericrange_controller.js`` are swapped for the patched copies
(see :ref:`how-it-works-range-facets`). A copy in your site package, a
modified version or a bundle that contains them keeps the original code:
port the changes marked ``Solr Shield`` in
``EXT:solr_shield/Resources/Public/JavaScript/Solr/`` to your script, and
compare them again after updating EXT:solr.

..  _integration-turbo:

Turbo and other SPA navigation
==============================

`Turbo Drive <https://turbo.hotwired.dev/>`__ and similar libraries swap
the page body without a page load and run its scripts again. Solr Shield
supports this:

*   ``solr-shield.js`` sets itself up once per document. Every visit
    restarts the elapsed time, as a page load would; the interaction
    signals keep counting.
*   Turbo's hover prefetch is cancelled for links that carry ``_ss``. The
    prefetch would have no current ``_solr_bot`` and be rejected, and the
    click loads the freshly signed URL anyway.
*   The patched range facet scripts bind to the new page on every visit.

Keep the scripts in the page footer, where EXT:solr's examples include
them, so they run on every visit.

EXT:solr's own ``suggest_controller.js`` fails when it runs a second time
(``Identifier 'SuggestController' has already been declared``), so
autocomplete only works on pages loaded in full. Add
``data-turbo="false"`` to links and forms that lead to pages with a search
form if you need it there.

..  _integration-other-formats:

Responses that are not HTML
===========================

The HTML middleware only rewrites responses with a ``text/html`` content
type. Search URLs and form fields in JSON, XML or other responses keep
their ``tx_solr`` parameters. Generate such URLs with a view helper or
``typolink()`` so the link listeners encode them, and do not render
``tx_solr[…]`` field names into non-HTML output.

..  _integration-checklist:

Checklist
=========

Test every customised search page twice, once with
:confval:`setting-botDetection-enabled` and once with JavaScript disabled
and bot detection off:

*   The page source contains no ``tx_solr``.
*   Search form, facets, pagination, sorting and results per page work.
*   Date and numeric range facets work.
*   Autocomplete shows suggestions.
*   Requests in the browser's network panel carry ``_ss``, and with bot
    detection also ``_solr_bot``.
*   With Turbo or a similar library, repeat these checks on a page reached
    through a visit (see :ref:`integration-turbo` for autocomplete).
