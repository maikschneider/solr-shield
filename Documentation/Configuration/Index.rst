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

    Leave this disabled for now. The client-side script that collects the
    signals is not part of the extension yet, so enabling it rejects every
    signed search request
    (`#8 <https://github.com/maikschneider/solr-shield/issues/8>`__).

``solrShield.botDetection.minFormTimeout``, ``securityLevel`` and
``requireInteraction`` only take effect together with bot detection.
