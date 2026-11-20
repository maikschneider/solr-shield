import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const read = (version) => readFileSync(new URL(`../../Resources/Public/JavaScript/Solr/${version}/facet_daterange_controller.js`, import.meta.url), 'utf8');
const SIGNED = 'https://example.com/search?_ss=c2lnbmVk';
const UNSIGNED = 'https://example.com/search?tx_solr%5Bfilter%5D%5B0%5D=created%3A___FROM___-___TO___';

// Runs a patched date range script against a minimal browser stand-in and
// records the URLs it navigates to and the alerts it shows.
const browser = () => {
  const visited = [];
  const alerts = [];
  const globals = {
    window: { location: new URL('https://example.com/search') },
    document: {
      body: { appendChild: () => {} },
      createElement: () => ({ click() { visited.push(this.href); }, remove() {} }),
      addEventListener: () => {},
    },
    alert: (text) => alerts.push(text),
    URL,
  };
  return { globals, visited, alerts };
};

const input = (value, min = '2026-01-01', max = '2027-12-31') => {
  const listeners = {};
  return {
    value,
    min,
    max,
    setAttribute(name, val) { this[name] = val; },
    addEventListener(type, listener) { listeners[type] = listener; },
    change() { listeners.change(); },
  };
};

const loadV14 = (from, to, template = SIGNED) => {
  const { globals, visited, alerts } = browser();
  const min = input(from);
  const max = input(to);
  const attributes = {
    'data-facet-name': 'created',
    'data-facet-url': template,
    'data-facet-reset-url': 'https://example.com/search?_ss=cmVzZXQ',
    'data-range-gap': '+1DAY',
    'data-range-start-validation-text': 'start',
    'data-range-end-validation-text': 'end',
  };
  const facet = {
    getAttribute: (name) => attributes[name] ?? null,
    querySelector: (selector) => ({ '#created_min': min, '#created_max': max })[selector],
  };
  globals.document.querySelectorAll = () => [facet];
  vm.runInContext(read('v14'), vm.createContext(globals));
  // Reset min/max set by init(), as the date picker would offer the full range again.
  Object.assign(min, { min: '2026-01-01', max: '2027-12-31' });
  Object.assign(max, { min: '2026-01-01', max: '2027-12-31' });
  min.change();
  return { visited, alerts };
};

const loadV13 = (from, to, template = SIGNED) => {
  const { globals, visited } = browser();
  const fields = { '#start_date_created': from, '#end_date_created': to };
  const listeners = [];
  const jQuery = (selector) => ({
    ready: (callback) => callback(),
    on: () => {},
    datepicker: (method) => (method === 'getDate' ? new Date(`${fields[selector]}T00:00:00`) : undefined),
    change: (listener) => listeners.push(listener),
    val: () => (selector === '#created_url' ? template : fields[selector]),
  });
  jQuery.datepicker = {
    formatDate: (format, date) => [date.getFullYear(), date.getMonth() + 1, date.getDate()].map((part) => String(part).padStart(2, '0')).join(''),
  };
  globals.jQuery = jQuery;
  vm.runInContext(read('v13'), vm.createContext(globals));
  listeners.forEach((listener) => listener());
  return { visited };
};

const range = (url) => {
  const params = new URL(url).searchParams;
  return [params.get('_sr[from]'), params.get('_sr[to]')];
};

test('v14: a start date alone sends an empty end bound', () => {
  const { visited, alerts } = loadV14('2026-12-01', '');
  assert.deepEqual(visited.map(range), [['202612010000', '']]);
  assert.deepEqual(alerts, []);
});

test('v14: an end date alone sends an empty start bound', () => {
  const { visited, alerts } = loadV14('', '2026-12-31');
  assert.deepEqual(visited.map(range), [['', '202612312359']]);
  assert.deepEqual(alerts, []);
});

test('v14: both dates send both bounds', () => {
  assert.deepEqual(loadV14('2026-12-01', '2026-12-31').visited.map(range), [['202612010000', '202612312359']]);
});

test('v14: no date follows the reset URL', () => {
  assert.deepEqual(loadV14('', '').visited, ['https://example.com/search?_ss=cmVzZXQ']);
});

test('v14: a date outside the range is still rejected', () => {
  const { visited, alerts } = loadV14('2025-12-01', '');
  assert.deepEqual(visited, []);
  assert.deepEqual(alerts, ['start']);
});

test('v14: without obfuscation the placeholder is replaced by an empty bound', () => {
  const [url] = loadV14('2026-12-01', '', UNSIGNED).visited;
  assert.equal(new URL(url).searchParams.get('tx_solr[filter][0]'), 'created:202612010000-');
});

test('v13: a start date alone sends an empty end bound', () => {
  assert.deepEqual(loadV13('2026-12-01', '').visited.map(range), [['202612010000', '']]);
});

test('v13: an end date alone sends an empty start bound', () => {
  assert.deepEqual(loadV13('', '2026-12-31').visited.map(range), [['', '202612310000']]);
});

test('v13: both dates send both bounds', () => {
  assert.deepEqual(loadV13('2026-12-01', '2026-12-31').visited.map(range), [['202612010000', '202612310000']]);
});

test('v13: no date sends no request', () => {
  assert.deepEqual(loadV13('', '').visited, []);
});
