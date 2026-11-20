// Solr Shield bot detection.
//
// Collects behavioural signals and sends them as `_solr_bot` with every Solr
// request the visitor triggers: submitting a form that carries the signed
// `_ss` field, or following a link whose URL carries `_ss`. The server scores
// the payload (BotDetectionService) and rejects requests without one.
//
// Autocomplete requests and AJAX requests to `_ss` URLs get the payload when
// a script opens them, see signRequestUrl().
(() => {
  'use strict';

  // Turbo Drive and similar libraries run body scripts again on every visit,
  // while the document and its listeners stay. Set up once per document, and
  // restart the clock on every visit as a page load would.
  if (window.solrShieldRestart) {
    window.solrShieldRestart();
    return;
  }

  let startTime = Date.now();
  window.solrShieldRestart = () => {
    startTime = Date.now();
  };
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

  // A field may be linked to its form through the `form` attribute from
  // outside, e.g. the token of a form inside a disabled fieldset. The native
  // getter still works when a field named `elements` shadows `form.elements`.
  // A disabled field, also one disabled by its fieldset, is not submitted.
  const formElements = Object.getOwnPropertyDescriptor(HTMLFormElement.prototype, 'elements').get;
  const formField = (form, name) => Array.from(formElements.call(form))
    .find((element) => element.name === name && !element.matches(':disabled'));

  const signForm = (form) => {
    const token = form instanceof HTMLFormElement ? formField(form, '_ss') : undefined;
    if (!token) {
      return;
    }
    let field = formField(form, '_solr_bot');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = '_solr_bot';
      // Next to the token, it is submitted whenever the token is.
      if (token.hasAttribute('form')) {
        field.setAttribute('form', token.getAttribute('form'));
      }
      token.after(field);
    }
    field.value = payload();
  };
  document.addEventListener('submit', (event) => signForm(event.target), true);

  // form.submit() fires no submit event; EXT:solr's results-per-page select uses it.
  const nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function submit() {
    signForm(this);
    nativeSubmit.call(this);
  };

  const searchUrl = (target) => {
    const link = target instanceof Element ? target.closest('a[href]') : null;
    if (!link) {
      return null;
    }
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || !url.searchParams.has('_ss')) {
      return null;
    }
    // A fragment link inherits the page's query; changing it would reload the search.
    if (url.hash && url.href.split('#')[0] === window.location.href.split('#')[0]) {
      return null;
    }
    return { link, url };
  };

  // The href is rewritten at the last moment, right before the browser (or a
  // script that loads the link via AJAX) reads it, so the payload is fresh.
  const signLink = (event) => {
    const search = searchUrl(event.target);
    if (!search) {
      return;
    }
    search.url.searchParams.set('_solr_bot', payload());
    search.link.href = search.url.toString();
  };
  ['pointerdown', 'click', 'auxclick', 'contextmenu'].forEach((type) => {
    document.addEventListener(type, signLink, true);
  });

  // Turbo prefetches links on hover, before any of the events above. The
  // request would carry no payload or an outdated one, and the click fetches
  // the freshly signed URL anyway.
  document.addEventListener('turbo:before-prefetch', (event) => {
    if (searchUrl(event.target)) {
      event.preventDefault();
    }
  });

  // Autocomplete scripts (EXT:solr's suggest controllers or custom ones) and
  // scripts that load a signed URL via AJAX send their request without any of
  // the events above. Their same-origin request URLs get a fresh payload.
  const signRequestUrl = (input) => {
    let url;
    try {
      url = new URL(String(input), document.baseURI);
    } catch {
      return null;
    }
    if (url.origin !== window.location.origin
      || (url.searchParams.get('type') !== '7384' && !url.searchParams.has('_ss'))
    ) {
      return null;
    }
    url.searchParams.set('_solr_bot', payload());
    return url.toString();
  };

  const nativeOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function open(method, url, ...rest) {
    nativeOpen.call(this, method, signRequestUrl(url) ?? url, ...rest);
  };

  const nativeFetch = window.fetch;
  if (nativeFetch) {
    window.fetch = (input, init) => {
      const isRequest = input instanceof Request;
      // A Request with a body cannot always be copied (keepalive), so it stays as it is.
      const signed = isRequest && input.body !== null ? null : signRequestUrl(isRequest ? input.url : input);
      if (signed) {
        input = isRequest ? new Request(signed, input) : signed;
      }
      return nativeFetch.call(window, input, init);
    };
  }
})();
