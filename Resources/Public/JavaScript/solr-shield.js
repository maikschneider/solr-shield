/**
* SolrShield — bot detection for Apache Solr forms.
*
* ⚠️ WORK IN PROGRESS — NOT YET OPERATIONAL.
* This client-side bot-detection layer is incomplete and does not currently
* produce a valid `_solr_bot` payload end-to-end. Run the extension with
* `solrShield.botDetection.enabled: false` until this is finished. The
* server-side URI-obfuscation layer works without this script.
*
* URI obfuscation is handled server-side (HtmlOutputObfuscationMiddleware
* renames tx_solr[…] fields to _s[…] and injects a signed _ss token).
* This script only handles:
*   1. Collecting behavioural signals for bot detection (_solr_bot field).
*   2. Patching the data-suggest URL with the server-issued _ss token.
*
* Reads configuration from window.SolrShield (injected via TypoScript):
*   window.SolrShield.botDetection.enabled    (1/0)
*   window.SolrShield.botDetection.minTimeout (seconds)
*/
(function () {
  'use strict';

  const cfg = window.SolrShield || {};
  const botDetection = cfg.botDetection || {};
  const botDetectionEnabled = String(botDetection.enabled) !== '0';

  // --- Behavioural signal collection ---
  const signals = {
    startTime: Date.now(),
    mouseMoveCount: 0,
    lastMouseX: 0,
    lastMouseY: 0,
    scrollCount: 0,
    keyCount: 0,
    spacebarPressed: false,
    touchCount: 0,
    viewportWidth: window.innerWidth || 0,
    viewportHeight: window.innerHeight || 0,
    screenWidth: window.screen ? window.screen.width : 0,
    screenHeight: window.screen ? window.screen.height : 0,
    webdriver: !!(navigator.webdriver),
  };

  document.addEventListener('mousemove', function (e) {
    signals.mouseMoveCount++;
    signals.lastMouseX = e.clientX;
    signals.lastMouseY = e.clientY;
  }, { passive: true });

  document.addEventListener('scroll', function () {
    signals.scrollCount++;
  }, { passive: true });

  document.addEventListener('keydown', function (e) {
    signals.keyCount++;
    if (e.code === 'Space' || e.key === ' ') {
      signals.spacebarPressed = true;
    }
  }, { passive: true });

  document.addEventListener('touchstart', function () {
    signals.touchCount++;
  }, { passive: true });

  // --- Bot payload ---
  function buildBotPayload() {
    return btoa(JSON.stringify({
      elapsed: Math.floor((Date.now() - signals.startTime) / 1000),
      webdriver: signals.webdriver,
      screenWidth: signals.screenWidth,
      screenHeight: signals.screenHeight,
      viewportWidth: signals.viewportWidth,
      viewportHeight: signals.viewportHeight,
      mouseMoveCount: signals.mouseMoveCount,
      lastMouseX: signals.lastMouseX,
      lastMouseY: signals.lastMouseY,
      scrollCount: signals.scrollCount,
      keyCount: signals.keyCount,
      spacebarPressed: signals.spacebarPressed,
      touchCount: signals.touchCount,
      ts: Date.now(),
    }));
  }

  // --- Form initialisation ---
  function getSolrForms() {
    return Array.from(document.querySelectorAll('form')).filter(function (form) {
      // Matches the main search form and any form with server-renamed _s[ inputs (e.g. date pickers)
      return form.hasAttribute('data-suggest') || form.querySelector('[name^="_s["]') !== null;
    });
  }

  function initForms() {
    const forms = getSolrForms();
    if (!forms.length) return;

    forms.forEach(function (form) {
      if (botDetectionEnabled) {
        var botField = document.createElement('input');
        botField.type = 'hidden';
        botField.name = '_solr_bot';
        botField.className = 'solr-shield-bot';
        form.appendChild(botField);
      }

      // Patch suggest URL with the server-issued _ss token so autocomplete
      // AJAX requests pass SolrShieldMiddleware validation.
      var suggestUrl = form.getAttribute('data-suggest');
      if (suggestUrl) {
        var ssInput = form.querySelector('input[name="_ss"]');
        if (ssInput && ssInput.value) {
          var separator = suggestUrl.includes('?') ? '&' : '?';
          form.setAttribute('data-suggest', suggestUrl + separator + '_ss=' + encodeURIComponent(ssInput.value));
        }
      }

      // Fill bot payload immediately before submit — no field manipulation needed,
      // server already renamed _s[…] fields and injected _ss.
      form.addEventListener('submit', function () {
        if (botDetectionEnabled) {
          var botField = form.querySelector('input.solr-shield-bot');
          if (botField) {
            botField.value = buildBotPayload();
          }
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initForms);
  } else {
    initForms();
  }
})();
