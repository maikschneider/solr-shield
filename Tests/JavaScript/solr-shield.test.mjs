import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../Resources/Public/JavaScript/solr-shield.js', import.meta.url), 'utf8');

// Runs solr-shield.js against a minimal browser stand-in on https://example.com
// and records the URLs that reach the native XMLHttpRequest.open() and fetch().
const load = (baseURI = 'https://example.com/news/page') => {
  const opened = [];
  const fetched = [];
  const listeners = [];
  class XMLHttpRequest {
    open(method, url, ...rest) {
      opened.push({ method, url: String(url), rest });
    }
  }
  class HTMLFormElement {
    get elements() {
      return this.fields;
    }

    submit() {}
  }
  const window = {
    location: new URL('https://example.com/news/page'),
    screen: { width: 1920, height: 1080 },
    innerWidth: 1280,
    innerHeight: 800,
    fetch: (input, init) => {
      fetched.push({ input, init });
      return Promise.resolve();
    },
  };
  const context = vm.createContext({
    window,
    document: {
      addEventListener: (type, listener) => listeners.push({ type, listener }),
      createElement: () => element(),
      baseURI,
    },
    navigator: { webdriver: false },
    XMLHttpRequest,
    HTMLFormElement,
    Element: class {},
    Request,
    URL,
    btoa,
  });
  vm.runInContext(script, context);

  const submit = (form) => listeners.filter(({ type }) => type === 'submit').forEach(({ listener }) => listener({ target: form }));

  return { window, xhr: () => new XMLHttpRequest(), opened, fetched, HTMLFormElement, submit };
};

// A form field with its attributes; after() records the node inserted next to it.
// `disabled` stands for being disabled itself or through a fieldset.
const element = (attributes = {}) => ({
  name: attributes.name ?? '',
  value: '',
  attributes: { ...attributes },
  hasAttribute(name) {
    return name in this.attributes;
  },
  getAttribute(name) {
    return this.attributes[name] ?? null;
  },
  setAttribute(name, value) {
    this.attributes[name] = value;
  },
  matches(selector) {
    return selector === ':disabled' && 'disabled' in this.attributes;
  },
  after(node) {
    this.next = node;
  },
});

// A form whose fields are associated with it, but none of them is a descendant.
const formWith = (HTMLFormElement, fields) => Object.assign(new HTMLFormElement(), {
  fields,
  querySelector: () => null,
});

const botParam = (url) => new URL(url).searchParams.get('_solr_bot');
const payload = (url) => JSON.parse(atob(botParam(url)));

test('XHR to the suggest endpoint gets a fresh payload', () => {
  const { xhr, opened } = load();

  xhr().open('GET', '/?type=7384&_ss=abc&tx_solr%5BqueryString%5D=fo');

  const url = new URL(opened[0].url);
  assert.equal(url.origin, 'https://example.com');
  assert.equal(url.searchParams.get('tx_solr[queryString]'), 'fo');
  assert.equal(url.searchParams.get('_ss'), 'abc');
  assert.equal(payload(opened[0].url).screenWidth, 1920);
});

test('XHR keeps the remaining open() arguments', () => {
  const { xhr, opened } = load();

  xhr().open('GET', '/?type=7384', false, 'user', 'secret');

  assert.deepEqual(opened[0].rest, [false, 'user', 'secret']);
});

test('XHR to a signed result URL replaces an outdated payload', () => {
  const { xhr, opened } = load();

  xhr().open('GET', 'https://example.com/search?_ss=abc&_solr_bot=old');

  assert.notEqual(botParam(opened[0].url), 'old');
  assert.equal(typeof payload(opened[0].url).elapsed, 'number');
});

test('XHR to other origins or unrelated URLs stays untouched', () => {
  const { xhr, opened } = load();

  xhr().open('GET', 'https://other.example/?type=7384&_ss=abc');
  xhr().open('GET', '/api/data?type=1');

  assert.deepEqual(opened.map((call) => call.url), ['https://other.example/?type=7384&_ss=abc', '/api/data?type=1']);
});

test('fetch signs string, URL and Request inputs', () => {
  const { window, fetched } = load();
  const init = { headers: { Accept: 'application/json' } };

  window.fetch('/?type=7384&tx_solr%5BqueryString%5D=fo', init);
  window.fetch(new URL('https://example.com/search?_ss=abc'));
  window.fetch(new Request('https://example.com/?type=7384', { method: 'GET', headers: { 'X-Test': '1' } }));

  assert.ok(botParam(fetched[0].input));
  assert.equal(fetched[0].init, init);
  assert.ok(botParam(fetched[1].input));
  assert.ok(fetched[2].input instanceof Request);
  assert.ok(botParam(fetched[2].input.url));
  assert.equal(fetched[2].input.headers.get('X-Test'), '1');
});

test('relative URLs resolve against the document base like native requests', () => {
  const { xhr, window, opened, fetched } = load('https://example.com/');

  xhr().open('GET', 'index.php?type=7384');
  window.fetch('index.php?_ss=abc');

  assert.equal(new URL(opened[0].url).pathname, '/index.php');
  assert.equal(new URL(fetched[0].input).pathname, '/index.php');
});

test('fetch leaves a Request with a body as it is', () => {
  const { window, fetched } = load();
  const request = new Request('https://example.com/search?_ss=abc', { method: 'POST', body: 'q=bike', keepalive: true });

  window.fetch(request);

  assert.equal(fetched[0].input, request);
});

test('fetch to other origins or unrelated URLs stays untouched', () => {
  const { window, fetched } = load();
  const request = new Request('https://other.example/?type=7384');

  window.fetch('/api/data');
  window.fetch(request);

  assert.equal(fetched[0].input, '/api/data');
  assert.equal(fetched[1].input, request);
});

test('a form gets the payload next to its token, also when the token is linked from outside', () => {
  const { HTMLFormElement, submit } = load();
  const token = element({ name: '_ss', form: 'search' });

  submit(formWith(HTMLFormElement, [element({ name: '_s[q]', form: 'search' }), token]));

  assert.equal(token.next.name, '_solr_bot');
  assert.equal(token.next.getAttribute('form'), 'search');
  assert.equal(typeof JSON.parse(atob(token.next.value)).elapsed, 'number');
});

test('a form reuses its payload field and leaves forms without a token alone', () => {
  const { HTMLFormElement, submit } = load();
  const bot = element({ name: '_solr_bot', form: 'search' });
  const unsigned = element({ name: 'q' });

  submit(formWith(HTMLFormElement, [element({ name: '_ss', form: 'search' }), bot]));
  submit(formWith(HTMLFormElement, [unsigned]));

  assert.notEqual(bot.value, '');
  assert.equal(unsigned.next, undefined);
});

test('a form does not fill a disabled payload field, which the browser would not submit', () => {
  const { HTMLFormElement, submit } = load();
  const disabled = element({ name: '_solr_bot', form: 'search', disabled: '' });
  const token = element({ name: '_ss', form: 'search' });

  submit(formWith(HTMLFormElement, [disabled, token]));

  assert.equal(disabled.value, '');
  assert.equal(token.next.name, '_solr_bot');
  assert.notEqual(token.next.value, '');
});

test('a field named elements does not hide the token', () => {
  const { HTMLFormElement, submit } = load();
  const shadowing = element({ name: 'elements' });
  const token = element({ name: '_ss' });
  const form = formWith(HTMLFormElement, [shadowing, token]);
  // Like the browser's named property of a form.
  Object.defineProperty(form, 'elements', { value: shadowing });

  submit(form);

  assert.equal(token.next.name, '_solr_bot');
});
