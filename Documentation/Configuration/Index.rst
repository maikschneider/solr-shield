..  _configuration:

=============
Configuration
=============

All settings are site settings. Edit them in
:guilabel:`Site Management > Settings`, or in the site's ``settings.yaml``:

..  code-block:: yaml
    :caption: config/sites/main/settings.yaml

    solrShield:
      uriObfuscation:
        enabled: true
        rejectAction: redirect

..  _configuration-uri-obfuscation:

URI obfuscation
===============

..  confval:: solrShield.uriObfuscation.enabled
    :name: setting-uriObfuscation-enabled
    :type: bool
    :default: true

    Encode ``tx_solr`` parameters into ``_ss``, rewrite search forms and
    reject unsigned requests. When disabled, Solr Shield leaves links, forms
    and requests alone.

..  confval:: solrShield.uriObfuscation.rejectAction
    :name: setting-uriObfuscation-rejectAction
    :type: string
    :default: redirect

    What happens to a rejected request:

    ``redirect``
        ``302`` to the ``Referer`` of the request, or to ``/`` without one.

    ``403``
        An empty ``403 Forbidden`` response.

..  _configuration-bot-detection:

Bot detection
=============

..  confval:: solrShield.botDetection.enabled
    :name: setting-botDetection-enabled
    :type: bool
    :default: false

    Include the bot-detection script and reject Solr requests whose
    behavioural payload is missing or scores too low (see
    :ref:`how-it-works-bot-detection`). Autocomplete requests are exempt.

    ..  attention::
        Visitors without JavaScript can no longer search or follow filter
        links once this is enabled.

..  confval:: solrShield.botDetection.minFormTimeout
    :name: setting-botDetection-minFormTimeout
    :type: int
    :default: 3

    Seconds between page load and a search. Faster requests fail one check.

..  confval:: solrShield.botDetection.securityLevel
    :name: setting-botDetection-securityLevel
    :type: int
    :default: 7

    How many of the ten checks must pass. Values outside 0–10 are clamped,
    so a negative value requires no counted check; ``webdriver`` and the
    800×600 screen still reject on their own.

..  confval:: solrShield.botDetection.requireInteraction
    :name: setting-botDetection-requireInteraction
    :type: bool
    :default: true

    When enabled, a request without any mouse or touch event fails one check.
