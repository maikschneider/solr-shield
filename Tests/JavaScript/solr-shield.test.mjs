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
  class XMLHttpRequest {
    open(method, url, ...rest) {
      opened.push({ method, url: String(url), rest });
    }
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
    document: { addEventListener: () => {}, baseURI },
    navigator: { webdriver: false },
    XMLHttpRequest,
    HTMLFormElement: class { submit() {} },
    Element: class {},
    Request,
    URL,
    btoa,
  });
  vm.runInContext(script, context);

  return { window, xhr: () => new XMLHttpRequest(), opened, fetched };
};

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
