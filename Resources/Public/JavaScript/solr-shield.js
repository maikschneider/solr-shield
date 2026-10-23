// Solr Shield bot detection.
//
// Collects behavioural signals and sends them as `_solr_bot` with every Solr
// request the visitor triggers: submitting a form that carries the signed
// `_ss` field, or following a link whose URL carries `_ss`. The server scores
// the payload (BotDetectionService) and rejects requests without one.
//
// Autocomplete requests are exempt on the server, so they need nothing here.
(() => {
  'use strict';

  const startTime = Date.now();
  const signals = {
    mouseMoveCount: 0,
    scrollCount: 0,
    keyCount: 0,
    touchCount: 0,
  };

  const counter = (name) => () => {
    signals[name]++;
  };
  const passive = { passive: true };
  document.addEventListener('mousemove', counter('mouseMoveCount'), passive);
  document.addEventListener('scroll', counter('scrollCount'), passive);
  document.addEventListener('keydown', counter('keyCount'), passive);
  document.addEventListener('touchstart', counter('touchCount'), passive);

  const payload = () => btoa(JSON.stringify({
    elapsed: Math.floor((Date.now() - startTime) / 1000),
    webdriver: navigator.webdriver === true,
    screenWidth: window.screen ? window.screen.width : 0,
    screenHeight: window.screen ? window.screen.height : 0,
    viewportWidth: window.innerWidth || 0,
    viewportHeight: window.innerHeight || 0,
    mouseMoveCount: signals.mouseMoveCount,
    scrollCount: signals.scrollCount,
    keyCount: signals.keyCount,
    touchCount: signals.touchCount,
    ts: Date.now(),
  }));

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.querySelector('input[name="_ss"]')) {
      return;
    }
    let field = form.querySelector('input[name="_solr_bot"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = '_solr_bot';
      form.appendChild(field);
    }
    field.value = payload();
  }, true);

  // The href is rewritten at the last moment, right before the browser (or a
  // script that loads the link via AJAX) reads it, so the payload is fresh.
  const signLink = (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link) {
      return;
    }
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || !url.searchParams.has('_ss')) {
      return;
    }
    url.searchParams.set('_solr_bot', payload());
    link.href = url.toString();
  };
  ['pointerdown', 'click', 'auxclick', 'contextmenu'].forEach((type) => {
    document.addEventListener(type, signLink, true);
  });
})();
