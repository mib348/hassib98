import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import thankYouExtension, { thankYouExtension as namedThankYou } from '../extensions/checkout-ui/src/thank-you.js';
import orderStatusExtension, { orderStatusExtension as namedOrderStatus } from '../extensions/checkout-ui/src/order-status.js';

// Only tests replace DOM/network globals. The real extension keeps Shopify's
// native components and real backend requests in both dev and production.
class TestNode {
  constructor(tagName, text = '') {
    this.tagName = tagName;
    this.text = text;
    this.attributes = new Map();
    this.children = [];
    this.listeners = new Map();
  }

  setAttribute(name, value) { this.attributes.set(name, value); }
  addEventListener(name, callback) {
    const listeners = this.listeners.get(name) || [];
    listeners.push(callback);
    this.listeners.set(name, listeners);
  }
  async dispatch(name) {
    await Promise.all((this.listeners.get(name) || [])
      .map((callback) => callback({ currentTarget: this, target: this })));
  }
  appendChild(child) { this.children.push(child); }
  replaceChildren(...children) { this.children = children; }
  get textContent() { return this.text + this.children.map((child) => child.textContent).join(''); }
}

const gid = 'gid://shopify/Order/12945248190812';
const numericId = '12945248190812';
const confirmation = 'ZX74AB80';
const appConfig = readFileSync(new URL('../shopify.app.toml', import.meta.url), 'utf8');
const apiBaseUrl = appConfig.match(/^application_url\s*=\s*"([^"]+)"/m)[1].replace(/\/$/, '');

function response(payload = { order_number: 74780, arrLocation: { name: 'Station', no_station: 'N' } }, status = 200) {
  return { ok: status >= 200 && status < 300, status, json: async () => payload };
}

function nodes(root, tagName) {
  return [root, ...root.children.flatMap((child) => nodes(child, tagName))]
    .filter((node) => node.tagName === tagName);
}

function harness(t, shopifyData = {}, fetchResponse = () => response()) {
  const body = new TestNode('body');
  const requests = [];
  const delays = [];
  const errors = [];
  const timings = [];
  const clearedTimers = [];
  const frames = [];
  const originalReplace = body.replaceChildren.bind(body);
  body.replaceChildren = (...children) => {
    originalReplace(...children);
    frames.push(children);
  };

  // Restore even previously absent globals after each test. This makes tests
  // independent and ensures no fake data can reach any production module.
  for (const [key, value] of Object.entries({
    Node: TestNode,
    document: { body, createElement: (tag) => new TestNode(tag), createTextNode: (text) => new TestNode('#text', text) },
    shopify: shopifyData
  })) {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, key);
    Object.defineProperty(globalThis, key, { configurable: true, writable: true, value });
    t.after(() => descriptor ? Object.defineProperty(globalThis, key, descriptor) : delete globalThis[key]);
  }

  t.mock.method(globalThis, 'fetch', async (url, options) => {
    requests.push({ url, options });
    return fetchResponse(requests.length);
  });
  // Preserve asynchronous sequencing while removing real readiness waits.
  // Record delays so retry count and cadence remain part of the assertions.
  t.mock.method(globalThis, 'setTimeout', (callback, milliseconds) => {
    delays.push(milliseconds);
    return setImmediate(callback);
  });
  t.mock.method(globalThis, 'clearTimeout', (timer) => {
    clearedTimers.push(timer);
    clearImmediate(timer);
  });
  t.mock.method(console, 'error', (...args) => errors.push(args));
  t.mock.method(console, 'info', (...args) => timings.push(args));
  return { body, requests, delays, errors, timings, clearedTimers, frames };
}

// A readiness event must finish without firing its two-second timer. Hold the
// timers explicitly for those tests so an eager timer mock cannot hide a bug.
function holdTimers(t, h) {
  const pending = new Set();
  t.mock.method(globalThis, 'setTimeout', (callback, milliseconds) => {
    h.delays.push(milliseconds);
    const timer = { callback, milliseconds };
    pending.add(timer);
    return timer;
  });
  t.mock.method(globalThis, 'clearTimeout', (timer) => {
    h.clearedTimers.push(timer);
    pending.delete(timer);
  });
  return pending;
}

function assertQr(h, expectedNumber = '74780') {
  const qr = nodes(h.body, 's-qr-code');
  assert.equal(qr.length, 1);
  assert.equal(qr[0].attributes.get('content'), expectedNumber);
  assert.equal(qr[0].attributes.get('size'), 'fill');
  assert.equal(nodes(h.body, 's-banner').length, 0);
  assert.equal(nodes(h.body, 's-spinner').length, 0);
}

// Entrypoints now return before the background loader finishes. Observe its
// final DOM state instead of treating the entrypoint return as network success.
async function waitForFinishedView() {
  for (let turn = 0; turn < 1000; turn++) {
    if (nodes(document.body, 's-spinner').length === 0) return;
    await new Promise(setImmediate);
  }
  assert.fail('The background loader did not finish within the test turn limit');
}

async function completeRun(run) {
  run();
  await waitForFinishedView();
}

function diagnostic(h, index = 0) {
  const serialized = h.errors[index][1];
  assert.equal(typeof serialized, 'string');
  const details = JSON.parse(serialized);
  assert.deepEqual(Object.keys(details), ['stage', 'attempt', 'status', 'reasonCode']);
  return details;
}

function assertError(h) {
  assert.equal(nodes(h.body, 's-qr-code').length, 0);
  assert.equal(nodes(h.body, 's-spinner').length, 0);
  const banner = nodes(h.body, 's-banner');
  assert.equal(banner.length, 1);
  assert.equal(banner[0].attributes.get('tone'), 'critical');
  assert.match(banner[0].textContent, /Fehler beim Laden der Bestelldetails/);
  assert.equal(h.errors.length, 1);
}

test('both target modules provide the default exports used by the 2026 runtime', () => {
  assert.equal(thankYouExtension, namedThankYou);
  assert.equal(orderStatusExtension, namedOrderStatus);
});

test('thank-you uses backend numeric order number, never alphanumeric confirmation number', async (t) => {
  const h = harness(t, { orderConfirmation: { value: { number: confirmation, order: { id: gid } } } });
  await completeRun(thankYouExtension);
  assertQr(h);
  assert.deepEqual(h.requests, [{ url: `${apiBaseUrl}/api/getordernumber/${numericId}`, options: { method: 'GET', cache: 'no-store' } }]);
  assert.equal(h.delays.length, 0);
});

test('order-status still fetches fulfillment metadata when Shopify already exposes a name', async (t) => {
  const h = harness(t, { order: { value: { id: gid, name: '#12345' } } });
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.equal(h.requests.length, 1);
});

// Reproduce the production regression in development: a real Shopify order
// name remains usable when the existing Laravel lookup returns HTTP 500.
// These are test-only responses; no fallback identifiers enter app code.
// Production's real OrderDetails response uses "56084"; the development store
// uses the optional "#" prefix. Both names identify the same numeric QR value.
for (const name of ['#56084', '56084']) {
  test(`order-status restores the legacy numeric QR after HTTP 500 using Shopify name ${name}`, async (t) => {
    const h = harness(t, { order: { value: { id: gid, name, confirmationNumber: confirmation } } },
      () => response(null, 500));
    await completeRun(orderStatusExtension);
    assertQr(h, '56084');
    assert.equal(h.requests.length, 1);
    assert.equal(h.delays.length, 0);
    assert.equal(h.errors.length, 0);
    const timings = h.timings.map((entry) => JSON.parse(entry[1]));
    assert.ok(timings.some((entry) => entry.stage === 'shopify-number-fallback'));
    assert.ok(!timings.some((entry) => entry.stage === 'metadata-ready'));
    assert.ok(timings.some((entry) => entry.status === 500 && entry.reasonCode === 'HTTP_ERROR'));
    for (const value of [gid, numericId, '56084', confirmation]) {
      assert.ok(!JSON.stringify(h.timings).includes(value));
    }
  });
}

for (const name of [undefined, null, 56084, '#0', '#056084', '#56084abc', '#56084/1', '#56084\n', ' #56084', '#56084 ', '0', '056084', '56084abc', '56084\n', ' 56084', '56084 ', '56084/1', 'EN56084', '56084-A', confirmation]) {
  test(`order-status HTTP 500 cannot use invalid Shopify name ${JSON.stringify(name)}`, async (t) => {
    const h = harness(t, { order: { value: { id: gid, name, confirmationNumber: '56084' } } },
      (attempt) => attempt === 1 ? response(null, 500) : response());
    await completeRun(orderStatusExtension);
    assertQr(h);
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.delays, [500]);
  });
}

test('order-status can use a name arriving during the failed lookup without mixing orders', async (t) => {
  const order = { id: gid };
  const h = harness(t, { order: { value: order } }, () => {
    order.name = '#56084';
    return response(null, 500);
  });
  await completeRun(orderStatusExtension);
  assertQr(h, '56084');
  assert.equal(h.requests.length, 1);
});

test('order-status never takes the fallback name from a different order after a failed lookup', async (t) => {
  const order = { id: gid, name: '#56084' };
  const h = harness(t, { order: { value: order } }, (attempt) => {
    if (attempt === 1) {
      order.id = 'gid://shopify/Order/99999';
      order.name = '#99999';
      return response(null, 500);
    }
    return response();
  });
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.equal(h.requests.length, 2);
});

test('thank-you still retries HTTP 500 even if its confirmation object contains a numeric name', async (t) => {
  const h = harness(t, { orderConfirmation: { value: { name: '#56084', number: '56084', order: { id: gid } } } },
    (attempt) => attempt === 1 ? response(null, 500) : response());
  await completeRun(thankYouExtension);
  assertQr(h);
  assert.equal(h.requests.length, 2);
  assert.deepEqual(h.delays, [500]);
});

test('order-status preserves the complete numeric name without converting it to an imprecise JavaScript number', async (t) => {
  const digits = '900719925474099312345';
  const h = harness(t, { order: { value: { id: gid, name: `#${digits}` } } }, () => response(null, 500));
  await completeRun(orderStatusExtension);
  assertQr(h, digits);
});

for (const [target, run, signal] of [
  ['thank-you', thankYouExtension, { orderConfirmation: { value: { number: confirmation, order: { id: gid } } } }],
  ['order-status', orderStatusExtension, { order: { value: { id: gid, name: '#74780' } } }]
]) {
  const makeSignal = (readId, subscribe) => target === 'thank-you'
    ? { orderConfirmation: { subscribe, get value() { return { number: confirmation, order: { id: readId() } }; } } }
    : { order: { subscribe, get value() { return { id: readId(), name: '#74780' }; } } };

  test(`${target} starts its first metadata request immediately when the signal is already ready`, async (t) => {
    const h = harness(t, makeSignal(() => gid, () => assert.fail('Ready identity must not subscribe')));
    assert.equal(run(), undefined);
    assert.equal(h.requests.length, 1);
    assert.equal(h.delays.length, 0);
    await waitForFinishedView();
    assertQr(h);
    const timings = h.timings.filter((entry) => entry[0] === '[checkout-ui] Order loading timing:')
      .map((entry) => JSON.parse(entry[1]));
    assert.deepEqual(timings.map((entry) => entry.stage), ['started', 'identity-ready', 'metadata-ready']);
    for (const entry of timings) {
      assert.deepEqual(Object.keys(entry), ['stage', 'elapsedMs']);
      assert.ok(Number.isFinite(entry.elapsedMs) && entry.elapsedMs >= 0);
    }
    const logged = JSON.stringify(h.timings);
    assert.ok(!logged.includes(gid));
    assert.ok(!logged.includes(confirmation));
    assert.ok(!logged.includes(numericId));
    assert.ok(!logged.includes('74780'));
  });

  test(`${target} wakes immediately on a valid signal event and ignores duplicate or invalid notifications`, async (t) => {
    let id;
    const listeners = new Set();
    let unsubscribed = 0;
    const h = harness(t, makeSignal(() => id, (notify) => {
      listeners.add(notify);
      return () => { listeners.delete(notify); unsubscribed++; };
    }));
    const timers = holdTimers(t, h);
    run();
    assert.equal(listeners.size, 1);
    assert.equal(h.requests.length, 0);
    const notify = [...listeners][0];
    id = confirmation;
    notify();
    assert.equal(timers.size, 1);
    assert.equal(h.requests.length, 0);
    id = gid;
    notify();
    notify();
    await waitForFinishedView();
    assertQr(h);
    assert.equal(h.requests.length, 1);
    assert.equal(timers.size, 0);
    assert.equal(listeners.size, 0);
    assert.equal(unsubscribed, 1);
    assert.equal(h.clearedTimers.length, 1);
    assert.deepEqual(h.delays, [2000]);
  });

  for (const race of ['before subscribing', 'during immediate notification', 'after registering without notification']) {
    test(`${target} observes an identity made ready ${race} and cleans its listener`, async (t) => {
      let id;
      let reads = 0;
      let subscriptions = 0;
      let unsubscribed = 0;
      const h = harness(t, makeSignal(() => {
        if (race === 'before subscribing' && ++reads > 1) id = gid;
        return id;
      }, (notify) => {
        subscriptions++;
        id = gid;
        if (race === 'during immediate notification') notify();
        return () => { unsubscribed++; };
      }));
      const timers = holdTimers(t, h);
      await completeRun(run);
      assertQr(h);
      assert.equal(h.requests.length, 1);
      assert.equal(timers.size, 0);
      assert.equal(subscriptions, race === 'before subscribing' ? 0 : 1);
      assert.equal(unsubscribed, subscriptions);
      assert.equal(h.clearedTimers.length, subscriptions);
    });
  }

  test(`${target} cleans every expired subscription while preserving the 31-attempt deadline`, async (t) => {
    let subscriptions = 0;
    let unsubscribed = 0;
    const h = harness(t, makeSignal(() => undefined, (notify) => {
      subscriptions++;
      notify(); // Some signals notify immediately with a still-missing value.
      return () => { unsubscribed++; };
    }));
    await completeRun(run);
    assertError(h);
    assert.equal(h.requests.length, 0);
    assert.deepEqual(h.delays, Array(30).fill(2000));
    assert.equal(subscriptions, 30);
    assert.equal(unsubscribed, 30);
    assert.equal(h.clearedTimers.length, 30);
    assert.equal(diagnostic(h).attempt, 31);
    assert.deepEqual(h.timings.map((entry) => JSON.parse(entry[1]).stage), ['started']);
  });

  test(`${target} falls back to polling when subscription registration throws`, async (t) => {
    let id;
    const h = harness(t, makeSignal(() => id, () => { throw new Error('PRIVATE-SUBSCRIBE-DETAILS'); }));
    t.mock.method(globalThis, 'setTimeout', (callback, milliseconds) => {
      h.delays.push(milliseconds);
      return setImmediate(() => { id = gid; callback(); });
    });
    await completeRun(run);
    assertQr(h);
    assert.deepEqual(h.delays, [2000]);
    assert.equal(h.errors.length, 0);
    assert.ok(!JSON.stringify(h.timings).includes('PRIVATE-SUBSCRIBE-DETAILS'));
  });

  test(`${target} falls back safely when the API signal getter throws during registration`, async (t) => {
    let id;
    let signalReads = 0;
    const key = target === 'thank-you' ? 'orderConfirmation' : 'order';
    const data = makeSignal(() => id, () => assert.fail('Unavailable signal must use polling'));
    const identitySignal = data[key];
    Object.defineProperty(data, key, { get() {
      if (++signalReads === 2) throw new Error('PRIVATE-SIGNAL-DETAILS');
      return identitySignal;
    } });
    const h = harness(t, data);
    t.mock.method(globalThis, 'setTimeout', (callback, milliseconds) => {
      h.delays.push(milliseconds);
      return setImmediate(() => { id = gid; callback(); });
    });
    await completeRun(run);
    assertQr(h);
    assert.deepEqual(h.delays, [2000]);
    assert.equal(h.errors.length, 0);
    assert.ok(!JSON.stringify(h.timings).includes('PRIVATE-SIGNAL-DETAILS'));
  });

  test(`${target} settles when immediate readiness unsubscribe throws`, async (t) => {
    let id;
    let unsubscribed = 0;
    const h = harness(t, makeSignal(() => id, (notify) => {
      id = gid;
      notify();
      return () => { unsubscribed++; throw new Error('PRIVATE-UNSUBSCRIBE-DETAILS'); };
    }));
    const timers = holdTimers(t, h);
    await completeRun(run);
    assertQr(h);
    assert.equal(unsubscribed, 1);
    assert.equal(timers.size, 0);
    assert.equal(h.errors.length, 0);
    assert.ok(!JSON.stringify(h.timings).includes('PRIVATE-UNSUBSCRIBE-DETAILS'));
  });

  test(`${target} re-subscribes when a native retry needs a newly delayed identity`, async (t) => {
    let id = gid;
    let notify;
    let unsubscribed = 0;
    const h = harness(t, makeSignal(() => id, (callback) => {
      notify = callback;
      return () => { unsubscribed++; };
    }), (attempt) => attempt === 1 ? response(null, 403) : response());
    await completeRun(run);
    assertError(h);
    id = undefined;
    const timers = holdTimers(t, h);
    const pending = nodes(h.body, 's-button')[0].dispatch('click');
    assert.equal(typeof notify, 'function');
    assert.equal(h.requests.length, 1);
    id = gid;
    notify();
    await pending;
    assertQr(h);
    assert.equal(h.requests.length, 2);
    assert.equal(timers.size, 0);
    assert.equal(unsubscribed, 1);
  });

  test(`${target} serializes native layout and accessibility attributes in lowercase`, async (t) => {
    const h = harness(t, signal);
    await completeRun(run);
    assertQr(h);
    const visit = (node) => {
      for (const name of node.attributes.keys()) assert.equal(name, name.toLowerCase());
      node.children.forEach(visit);
    };
    visit(h.body);
    const qr = nodes(h.body, 's-qr-code')[0];
    assert.match(qr.attributes.get('accessibilitylabel'), /Bestellung 74780/);
    assert.equal(nodes(h.body, 's-grid')[0].attributes.get('gridtemplatecolumns'), '@container (inline-size > 400px) 1fr 1fr, 1fr');
    assert.equal(nodes(h.body, 's-grid')[0].attributes.get('alignitems'), 'start');
    assert.ok(nodes(h.body, 's-box').some((node) => node.attributes.get('mininlinesize') === '0'));
    const loadingSpinner = nodes({ children: h.frames[0], tagName: 'frame' }, 's-spinner')[0];
    assert.equal(loadingSpinner.attributes.get('accessibilitylabel'), 'Details werden geladen. Bitte warten!...');
  });

  test(`${target} remains loading without a provisional QR until metadata resolves`, async (t) => {
    let complete;
    const h = harness(t, signal, () => new Promise((resolve) => { complete = resolve; }));
    const pending = completeRun(run);
    assert.equal(h.requests.length, 1);
    assert.equal(nodes(h.body, 's-spinner').length, 1);
    assert.equal(nodes(h.body, 's-qr-code').length, 0);
    complete(response());
    await pending;
    assertQr(h);
  });

  test(`${target} lets a host awaiting the entrypoint reveal loading before metadata resolves`, async (t) => {
    let complete;
    const h = harness(t, signal, () => new Promise((resolve) => { complete = resolve; }));
    let hostMounted = false;
    const returned = run();
    // The deployed host awaits the callback before changing its mounted flag.
    // A pending callback Promise would leave its child DOM hidden here.
    void Promise.resolve(returned).then(() => { hostMounted = true; });
    await new Promise(setImmediate);
    assert.equal(returned, undefined);
    assert.equal(hostMounted, true);
    assert.equal(nodes(h.body, 's-spinner').length, 1);
    assert.equal(nodes(h.body, 's-qr-code').length, 0);
    assert.equal(h.requests.length, 1);
    complete(response());
    await waitForFinishedView();
    assertQr(h);
  });

  for (const encoding of ['raw', 'base64']) {
    test(`${target} accepts the ${encoding} OrderIdentity GID from first checkout completion`, async (t) => {
      const identity = `gid://shopify/OrderIdentity/${numericId}`;
      const id = encoding === 'base64' ? Buffer.from(identity).toString('base64') : identity;
      const h = harness(t, makeSignal(() => id));
      await completeRun(run);
      assertQr(h);
      assert.equal(h.requests[0].url, `${apiBaseUrl}/api/getordernumber/${numericId}`);
      assert.equal(h.requests.length, 1);
      assert.equal(h.delays.length, 0);
      assert.ok(!nodes(h.body, 's-qr-code')[0].attributes.get('content').includes(confirmation));
    });
  }

  test(`${target} delivery metadata renders its actual note and link instead of a QR`, async (t) => {
    const h = harness(t, signal, () => response({ order_number: 74780, arrLocation: {
      name: 'Delivery', no_station: 'N', checkout_note: 'Test delivery note',
      checkout_hyperlink: 'https://sushi.catering/pages/faq', checkout_hyperlink_text: 'Test delivery link'
    } }));
    await completeRun(run);
    assert.equal(nodes(h.body, 's-qr-code').length, 0);
    assert.match(h.body.textContent, /Lieferinformationen.*Test delivery note.*Test delivery link/);
    assert.equal(nodes(h.body, 's-link')[0].attributes.get('target'), '_blank');
  });

  test(`${target} pickup without a scanner renders the existing pickup instructions`, async (t) => {
    const h = harness(t, signal, () => response({ order_number: 74780, arrLocation: { name: 'Reception', no_station: 'Y' } }));
    await completeRun(run);
    assert.equal(nodes(h.body, 's-qr-code').length, 0);
    assert.match(h.body.textContent, /Abholinformationen.*Sammellieferung/);
  });

  test(`${target} accepts an identity that becomes available after six seconds`, async (t) => {
    let reads = 0;
    const h = harness(t, makeSignal(() => ++reads >= 4 ? gid : undefined));
    await completeRun(run);
    assertQr(h);
    assert.equal(h.delays.reduce((sum, value) => sum + value, 0), 6000);
    assert.equal(h.requests.length, 1);
  });

  test(`${target} recovers backend metadata with a fast first retry and conservative later retries`, async (t) => {
    // An unnamed Order Status keeps the existing metadata retry flow. Named
    // HTTP-500 recovery is covered separately by the regression test above.
    const retrySignal = target === 'order-status' ? { order: { value: { id: gid } } } : signal;
    const h = harness(t, retrySignal, (attempt) => attempt < 4 ? response(null, 500) : response());
    await completeRun(run);
    assertQr(h);
    assert.deepEqual(h.delays, [500, 2000, 2000]);
    assert.equal(h.requests.length, 4);
    assert.equal(h.errors.length, 0);
  });

  test(`${target} records each metadata outcome without exposing request or response data`, async (t) => {
    const h = harness(t, signal, (attempt) => {
      if (attempt === 1) return response(null, 503);
      if (attempt === 2) throw new TypeError('Failed to fetch PRIVATE-REQUEST-DETAILS');
      return response();
    });
    await completeRun(run);
    assertQr(h);
    assert.deepEqual(h.delays, [500, 2000]);
    const requests = h.timings.filter((entry) => entry[0] === '[checkout-ui] Order metadata request:')
      .map((entry) => JSON.parse(entry[1]));
    assert.equal(requests.length, 3);
    assert.deepEqual(requests.map(({ attempt, status, reasonCode }) => ({ attempt, status, reasonCode })), [
      { attempt: 1, status: 503, reasonCode: 'HTTP_ERROR' },
      { attempt: 2, status: null, reasonCode: 'REQUEST_FAILED' },
      { attempt: 3, status: 200, reasonCode: null }
    ]);
    for (const entry of requests) {
      assert.deepEqual(Object.keys(entry), ['attempt', 'elapsedMs', 'status', 'reasonCode']);
      assert.ok(Number.isFinite(entry.elapsedMs) && entry.elapsedMs >= 0);
    }
    const logged = JSON.stringify(h.timings);
    for (const value of [gid, numericId, confirmation, apiBaseUrl, '74780', 'PRIVATE-REQUEST-DETAILS']) {
      assert.ok(!logged.includes(value));
    }
  });

  test(`${target} retains the full backoff for rate limiting`, async (t) => {
    const h = harness(t, signal, () => response(null, 429));
    await completeRun(run);
    assertError(h);
    assert.equal(h.requests.length, 31);
    assert.deepEqual(h.delays, Array(30).fill(2000));
  });

  test(`${target} measures request and body duration separately from retry pauses`, async (t) => {
    let clock = 0;
    const h = harness(t, signal, (attempt) => {
      clock += 80;
      if (attempt === 1) return response(null, 503);
      return { ok: true, status: 200, json: async () => {
        clock += 40;
        return { order_number: 74780, arrLocation: { name: 'Station', no_station: 'N' } };
      } };
    });
    t.mock.method(Date, 'now', () => clock);
    t.mock.method(globalThis, 'setTimeout', (callback, milliseconds) => {
      h.delays.push(milliseconds);
      clock += milliseconds;
      return setImmediate(callback);
    });
    await completeRun(run);
    assertQr(h);
    const requests = h.timings.filter((entry) => entry[0] === '[checkout-ui] Order metadata request:')
      .map((entry) => JSON.parse(entry[1]));
    assert.deepEqual(requests.map((entry) => entry.elapsedMs), [80, 120]);
    assert.deepEqual(h.delays, [500]);
    const ready = h.timings.filter((entry) => entry[0] === '[checkout-ui] Order loading timing:')
      .map((entry) => JSON.parse(entry[1])).find((entry) => entry.stage === 'metadata-ready');
    assert.equal(ready.elapsedMs, 700);
  });

  test(`${target} still renders the real QR if metadata telemetry throws`, async (t) => {
    const h = harness(t, signal);
    t.mock.method(console, 'info', (...args) => {
      h.timings.push(args);
      if (args[0] === '[checkout-ui] Order metadata request:') throw new Error('PRIVATE-LOGGER-FAILURE');
    });
    await completeRun(run);
    assertQr(h);
    assert.equal(h.requests.length, 1);
    assert.equal(h.delays.length, 0);
    assert.equal(h.errors.length, 0);
    assert.ok(!JSON.stringify(h.timings).includes('PRIVATE-LOGGER-FAILURE'));
  });

  test(`${target} diagnoses an invalid JSON body using the response status`, async (t) => {
    const h = harness(t, signal, () => ({ ok: true, status: 200, json: async () => {
      throw new SyntaxError('PRIVATE-BACKEND-BODY');
    } }));
    await completeRun(run);
    assertError(h);
    const requests = h.timings.filter((entry) => entry[0] === '[checkout-ui] Order metadata request:')
      .map((entry) => JSON.parse(entry[1]));
    assert.equal(requests.length, 1);
    assert.equal(requests[0].status, 200);
    assert.equal(requests[0].reasonCode, 'RESPONSE_INVALID');
    assert.ok(!JSON.stringify(h.timings).includes('PRIVATE-BACKEND-BODY'));
  });

  test(`${target} bounds identity waits and retries both phases in place after recovery`, async (t) => {
    let ready = false;
    let reads = 0;
    const h = harness(t, makeSignal(() => { reads++; return ready ? gid : undefined; }));
    await completeRun(run);
    assertError(h);
    assert.equal(reads, 31);
    assert.equal(h.requests.length, 0);
    assert.equal(h.delays.reduce((sum, value) => sum + value, 0), 60000);
    assert.deepEqual(diagnostic(h), {
      stage: 'order-identity', attempt: 31, status: null, reasonCode: 'ORDER_ID_UNAVAILABLE'
    });
    const retry = nodes(h.body, 's-button')[0];
    assert.equal(retry.textContent, 'Erneut versuchen');
    assert.equal(retry.attributes.get('variant'), 'secondary');
    ready = true;
    await retry.dispatch('click');
    assertQr(h);
    assert.equal(reads, 32);
    assert.equal(h.requests.length, 1);
    assert.equal(nodes(h.body, 's-button').length, 0);
  });

  test(`${target} bounds backend retries and ignores a queued duplicate recovery click`, async (t) => {
    let ready = false;
    let complete;
    const h = harness(t, signal, () => ready
      ? new Promise((resolve) => { complete = resolve; })
      : response(null, 503));
    await completeRun(run);
    assertError(h);
    assert.equal(h.requests.length, 31);
    assert.deepEqual(h.delays, [500, ...Array(29).fill(2000)]);
    assert.deepEqual(diagnostic(h), {
      stage: 'order-metadata', attempt: 31, status: 503, reasonCode: 'HTTP_ERROR'
    });
    const retry = nodes(h.body, 's-button')[0];
    ready = true;
    const pending = retry.dispatch('click');
    await retry.dispatch('click');
    assert.equal(h.requests.length, 32);
    assert.equal(nodes(h.body, 's-spinner').length, 1);
    assert.equal(nodes(h.body, 's-qr-code').length, 0);
    complete(response());
    await pending;
    assertQr(h);
  });

  test(`${target} permits another recovery attempt after a nontransient failure`, async (t) => {
    let ready = false;
    const h = harness(t, signal, () => ready ? response() : response(null, 403));
    await completeRun(run);
    assertError(h);
    const firstRetry = nodes(h.body, 's-button')[0];
    await firstRetry.dispatch('click');
    assert.equal(h.requests.length, 2);
    assert.equal(h.delays.length, 0);
    assert.equal(h.errors.length, 2);
    assert.deepEqual(diagnostic(h, 1), {
      stage: 'order-metadata', attempt: 1, status: 403, reasonCode: 'HTTP_ERROR'
    });
    const nextRetry = nodes(h.body, 's-button')[0];
    assert.notEqual(nextRetry, firstRetry);
    ready = true;
    await nextRetry.dispatch('click');
    assertQr(h);
    assert.equal(h.requests.length, 3);
  });

  test(`${target} diagnostics describe malformed responses without exposing their contents`, async (t) => {
    const h = harness(t, signal, () => response({
      order_number: confirmation,
      arrLocation: { name: 'PRIVATE-LOCATION-DETAILS', no_station: 'N' }
    }));
    await completeRun(run);
    assertError(h);
    assert.equal(h.requests.length, 1);
    assert.equal(h.delays.length, 0);
    assert.deepEqual(diagnostic(h), {
      stage: 'order-metadata', attempt: 1, status: 200, reasonCode: 'ORDER_NUMBER_INVALID'
    });
    const logged = JSON.stringify(h.errors);
    assert.ok(!logged.includes(gid));
    assert.ok(!logged.includes(confirmation));
    assert.ok(!logged.includes('PRIVATE-LOCATION-DETAILS'));
  });

  test(`${target} diagnostics exclude network error messages and backend details`, async (t) => {
    const h = harness(t, signal, () => {
      throw Object.assign(new TypeError('Failed to fetch PRIVATE-RESPONSE-CONTENT'), {
        reasonCode: 'PRIVATE-RESPONSE-CONTENT'
      });
    });
    await completeRun(run);
    assertError(h);
    assert.equal(h.requests.length, 31);
    assert.deepEqual(diagnostic(h), {
      stage: 'order-metadata', attempt: 31, status: null, reasonCode: 'REQUEST_FAILED'
    });
    assert.ok(!JSON.stringify(h.errors).includes('PRIVATE-RESPONSE-CONTENT'));
  });
}

test('thank-you waits for delayed Order GID even when a confirmation identifier is present', async (t) => {
  let reads = 0;
  const h = harness(t, { orderConfirmation: { get value() {
    reads++;
    return { number: confirmation, order: { id: reads >= 3 ? gid : undefined } };
  } } });
  await completeRun(thankYouExtension);
  assertQr(h);
  assert.equal(reads, 3);
  assert.deepEqual(h.delays, [2000, 2000]);
  assert.equal(h.requests.length, 1);
  assert.equal(nodes(h.frames[0][0], 's-spinner').length, 1);
});

test('order-status retries a temporarily invalid GID instead of treating the order name as its ID', async (t) => {
  let reads = 0;
  const h = harness(t, { order: { get value() { return { id: ++reads === 1 ? 'gid://shopify/Order/0' : gid, name: '#74780' }; } } });
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.deepEqual(h.delays, [2000]);
  assert.equal(h.requests[0].url, `${apiBaseUrl}/api/getordernumber/${numericId}`);
});

test('a signal that is not readable yet is retried safely', async (t) => {
  let reads = 0;
  const h = harness(t, { order: { get value() {
    if (++reads === 1) throw new Error('Signal not ready');
    return { id: gid };
  } } });
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.equal(h.delays.length, 1);
});

test('base64 encoded Order GIDs retain their existing supported fallback', async (t) => {
  const h = harness(t, { order: { value: { id: Buffer.from(gid).toString('base64') } } });
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.equal(h.requests[0].url, `${apiBaseUrl}/api/getordernumber/${numericId}`);
});

for (const invalidId of [
  undefined, 'gid://shopify/Order/0', 'ZX74780AB', '#74780', 'gid://shopify/Customer/74780',
  'gid://shopify/OrderIdentity/0', 'gid://shopify/OrderIdentity/-1',
  'gid://shopify/OrderIdentity/01', 'gid://shopify/OrderIdentity/74780abc',
  'gid://shopify/OrderIdentity/74780?key=value', 'gid://shopify/OrderIdentity/74780/',
  'gid://shopify/OrderIdentityExtra/74780',
  Buffer.from('gid://shopify/Customer/74780').toString('base64'),
  Buffer.from('gid://shopify/OrderIdentity/74780abc').toString('base64')
]) {
  test(`missing or invalid Order GID ${String(invalidId)} never becomes a guessed backend ID`, async (t) => {
    const h = harness(t, { orderConfirmation: { value: { number: '74780', order: { id: invalidId } } } });
    await completeRun(thankYouExtension);
    assertError(h);
    assert.equal(h.requests.length, 0);
    assert.deepEqual(h.delays, Array(30).fill(2000));
  });
}

for (const status of [404, 409, 423, 425, 429, 500, 502, 503, 504]) {
  test(`transient HTTP ${status} is retried before showing the correct QR`, async (t) => {
    const h = harness(t, { order: { value: { id: gid } } }, (attempt) => attempt === 1 ? response(null, status) : response());
    await completeRun(orderStatusExtension);
    assertQr(h);
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.delays, [status === 429 ? 2000 : 500]);
  });
}

for (const status of [404, 409, 423, 425, 429, 502, 503, 504]) {
  test(`a valid Shopify name does not bypass the existing HTTP ${status} retry behavior`, async (t) => {
    const h = harness(t, { order: { value: { id: gid, name: '#56084' } } },
      (attempt) => attempt === 1 ? response(null, status) : response());
    await completeRun(orderStatusExtension);
    assertQr(h);
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.delays, [status === 429 ? 2000 : 500]);
  });
}

for (const error of [new TypeError('Failed to fetch'), Object.assign(new Error('Request aborted'), { name: 'AbortError' })]) {
  test(`transient ${error.name} is retried`, async (t) => {
    const h = harness(t, { order: { value: { id: gid, name: '#56084' } } }, (attempt) => {
      if (attempt === 1) throw error;
      return response();
    });
    await completeRun(orderStatusExtension);
    assertQr(h);
    assert.equal(h.requests.length, 2);
  });
}

test('backend failures stop after the configured attempt limit and never display a guessed QR', async (t) => {
  const h = harness(t, { orderConfirmation: { value: { number: confirmation, order: { id: gid } } } }, () => response(null, 503));
  await completeRun(thankYouExtension);
  assertError(h);
  assert.equal(h.requests.length, 31);
  assert.deepEqual(h.delays, [500, ...Array(29).fill(2000)]);
});

for (const status of [401, 403, 422]) {
  test(`nontransient HTTP ${status} shows the existing error without retrying`, async (t) => {
    const h = harness(t, { order: { value: { id: gid, name: '#56084' } } }, () => response(null, status));
    await completeRun(orderStatusExtension);
    assertError(h);
    assert.equal(h.requests.length, 1);
    assert.equal(h.delays.length, 0);
  });
}

for (const payload of [
  null,
  { order_number: 0, arrLocation: null },
  { order_number: confirmation, arrLocation: null },
  { order_number: 74780 },
  { order_number: 74780, arrLocation: [] },
  { order_number: 74780, arrLocation: 'Delivery' }
]) {
  test(`incomplete or malformed backend metadata ${JSON.stringify(payload)} never fabricates a default location`, async (t) => {
    const h = harness(t, { order: { value: { id: gid, name: '#56084' } } }, () => response(payload));
    await completeRun(orderStatusExtension);
    assertError(h);
    assert.equal(h.requests.length, 1);
    assert.equal(diagnostic(h).status, 200);
    assert.equal(diagnostic(h).reasonCode,
      /^[1-9]\d*$/.test(String(payload?.order_number || '')) ? 'LOCATION_METADATA_INVALID' : 'ORDER_NUMBER_INVALID');
  });
}

test('an explicitly null backend location preserves the normal numeric QR flow', async (t) => {
  const h = harness(t, { order: { value: { id: gid } } }, () => response({ order_number: '74780', arrLocation: null }));
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.equal(h.requests.length, 1);
  assert.equal(h.delays.length, 0);
});

test('delivery without optional note/link retains the existing German fallback', async (t) => {
  const h = harness(t, { order: { value: { id: gid } } }, () => response({ order_number: 74780, arrLocation: { name: 'Delivery' } }));
  await completeRun(orderStatusExtension);
  assert.equal(nodes(h.body, 's-qr-code').length, 0);
  assert.match(h.body.textContent, /Lieferinformationen.*Ihre Bestellung wird geliefert/);
});

test('QR instructions keep the FAQ and supported native responsive grid contract', async (t) => {
  const h = harness(t, { order: { value: { id: gid } } });
  await completeRun(orderStatusExtension);
  assertQr(h);
  assert.equal(nodes(h.body, 's-query-container').length, 1);
  const grid = nodes(h.body, 's-grid')[0];
  assert.equal(grid.attributes.get('gridtemplatecolumns'), '@container (inline-size > 400px) 1fr 1fr, 1fr');
  assert.equal(nodes(h.body, 's-paragraph').length, 4);
  const faq = nodes(h.body, 's-link')[0];
  assert.equal(faq.attributes.get('href'), 'https://sushi.catering/pages/faq');
  assert.equal(faq.textContent, 'hier');
});
