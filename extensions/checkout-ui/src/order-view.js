/**
 * Shared rendering logic for the two checkout-ui targets, written for the
 * 2026-07 "Polaris web components" runtime.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * On the 2025-07 runtime a single index.js exported TWO named handlers
 * (thankYouExtension / orderStatusExtension) and Shopify picked one per target.
 * On 2026-07 the bundler imports the *default* export of each target's module
 * and the entrypoint receives NO arguments, so it cannot tell which target it
 * is. Therefore each target now needs its own module with its own default
 * export. To avoid duplicating the QR / delivery / pickup rendering, both entry
 * modules (thank-you.js, order-status.js) call the ONE shared orchestrator here
 * and only differ in HOW they read the order identity from the global `shopify`
 * object (orderConfirmation vs order).
 *
 * The QR still encodes the numeric order number that the pickup station scans.
 * The backend supplies that number AND the real fulfillment metadata for both
 * targets; Shopify's confirmation identifier is never used as QR content.
 */

// ---------------------------------------------------------------------------
// Static text and configuration (unchanged from the previous implementation).
// ---------------------------------------------------------------------------
const TEXTS = {
  LOADING: 'Details werden geladen. Bitte warten!...',
  LOADING_RETRY: (count, max) => `Details werden geladen... Versuch ${count}/${max}`,
  ERROR: 'Fehler beim Laden der Bestelldetails. Bitte versuchen Sie es später erneut oder prüfen Sie Ihre E-Mail.',
  RETRY: 'Erneut versuchen',
  NO_ORDER_NUMBER: 'Bestellnummer nicht gefunden, QR-Code kann nicht angezeigt werden.',
  SUCCESS_FALLBACK: 'Ihre Bestellung wurde erfolgreich übermittelt. Weitere Details finden Sie in Ihrer Bestätigungs-E-Mail.',
  QR_INSTRUCTIONS: [
    'Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Stelle hierfür die maximale Helligkeit deines Mobilgeräts ein und halte dein Mobilgerät senkrecht, mittig und im Abstand von ca. 10cm vor den Scanner.',
    'Bitte achte darauf, dass der QR-Code die volle Breite deines Bildschirms ausfüllen muss. Falls der QR-Code im Browser zu klein angezeigt wird, kannst du hineinzoomen oder den QR-Code aus deiner E-Mail verwenden.',
    'Nach dem Scannen, folge den Anweisungen auf dem Monitor. Wenn deine Bestellung nicht gefunden wurde, versuche es nach 30 Sekunden erneut. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail.'
  ],
  FAQ_LINK: 'https://sushi.catering/pages/faq',
  FAQ_TEXT: 'hier',
  FAQ_PREFIX: 'Fragen und Antworten rund um deine Bestellung findest du ',
  DELIVERY_HEADING: 'Lieferinformationen',
  PICKUP_HEADING: 'Abholinformationen',
  PICKUP_INSTRUCTION: 'Wir liefern alle bestellten Gerichte in einer Sammellieferung täglich und frisch an die Rezeption. Achten Sie bitte darauf, dass die Gerichte bis zum Verzehr kühl gehalten werden müssen, z.B. im Kühlschrank. Um wie viel Uhr wir genau anliefern, entnehmen Sie bitte der Seite auf die Sie weitergeleitet werden, nachdem Sie den Standort festgelegt haben.',
  DELIVERY_FALLBACK: 'Ihre Bestellung wird geliefert. Weitere Informationen finden Sie in Ihrer Bestätigungs-E-Mail.',
  DELIVERY_LINK_DEFAULT: 'Weitere Details zur Lieferung'
};

const CONFIG = {
  // New orders can take longer to become readable. Keep the 31-attempt bound
  // and two-second identity/later recovery gaps. The first metadata retry uses
  // the original 500ms pause, so one temporary failure need not add two seconds.
  MAX_RETRIES: 31,
  RETRY_DELAY: 2000,
  INITIAL_METADATA_RETRY_DELAY: 500,
  // Backend base URL. DEV value; the live extension uses https://app.sushi.catering.
  API_BASE_URL: 'https://dev.sushi.catering'
};

// ---------------------------------------------------------------------------
// Tiny DOM builder for Shopify's `s-*` web components.
// Mirrors the helper already proven in voucher-codes-page: `on*` keys become
// addEventListener() calls, everything else becomes an attribute, and null /
// undefined values are skipped so we never emit `attr="null"`.
// ---------------------------------------------------------------------------
function createEl(tag, attrs = {}, children = []) {
  const el = document.createElement(tag);

  Object.entries(attrs || {}).forEach(([name, value]) => {
    if (value === undefined || value === null) return;
    // onClick -> addEventListener('click', fn); these are native DOM events on
    // the web components (click / input / change), per the 2026-07 docs.
    if (name.startsWith('on') && typeof value === 'function') {
      el.addEventListener(name.slice(2).toLowerCase(), value);
      return;
    }
    // The worker preserves attribute case, while the host reads lowercase
    // names such as accessibilitylabel. Normalize here so layout and labels
    // reach the native component; event handlers were attached above.
    el.setAttribute(name.toLowerCase(), String(value));
  });

  const kids = Array.isArray(children) ? children : [children];
  kids.forEach((child) => {
    if (child === undefined || child === null) return;
    el.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
  });

  return el;
}

// ---------------------------------------------------------------------------
// Order-id helpers.
// ---------------------------------------------------------------------------

// A first confirmation can still expose an OrderIdentity GID before Shopify
// exposes the Order GID. Both carry the backend lookup ID. Accept only these
// two resource names with a positive numeric suffix, including older base64
// identifiers; an order name or confirmation string is never a lookup ID.
function extractNumericId(orderId) {
  if (typeof orderId !== 'string') return null;

  const trimmed = orderId.trim();
  if (!trimmed) return null;

  const normalized = normalizeOrderIdentifier(trimmed);
  const match = normalized?.match(/^gid:\/\/shopify\/(?:Order|OrderIdentity)\/([1-9]\d*)$/);
  return match ? match[1] : null;
}

// Return a canonical gid string, decoding base64 when Shopify hands us that.
function normalizeOrderIdentifier(value) {
  if (value.includes('gid://shopify/')) return value;
  const decoded = decodePotentialBase64(value);
  if (decoded && decoded.includes('gid://shopify/')) return decoded;
  return null;
}

// Best-effort base64 decode that never throws on non-base64 input.
function decodePotentialBase64(value) {
  const text = value.replace(/\s+/g, '');
  if (!text || text.length < 8) return null;
  if (!/^[A-Za-z0-9+/=]+$/.test(text)) return null;
  try {
    if (typeof atob === 'function') return atob(text);
    if (typeof globalThis !== 'undefined' && typeof globalThis.atob === 'function') return globalThis.atob(text);
    if (typeof Buffer !== 'undefined') return Buffer.from(text, 'base64').toString('utf8');
  } catch (error) {
    return null;
  }
  return null;
}

// Decide whether a failed fetch is worth retrying (transient conditions only).
function shouldRetryFetch(error) {
  if (!error) return false;
  if (error.name === 'AbortError') return true;
  const status = typeof error.status === 'number' ? error.status : null;
  if (status) return [404, 409, 423, 425, 429, 500, 502, 503, 504].includes(status);
  const message = error.message || '';
  return /network|fetch|timeout|load failed/i.test(message);
}

// Small promise-based sleep for the retry loops.
function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// Shopify signals can announce the order before the next polling gap ends.
// Wake on a valid identity immediately, while keeping the existing two-second
// fallback for missing/broken subscriptions. Only our listener is removed;
// destroying the shared Shopify signal would affect other consumers.
function waitForIdentity(getIdentity, getIdentitySignal) {
  let signal;
  try { signal = getIdentitySignal?.(); } catch (error) { /* Use the timer fallback. */ }
  if (typeof signal?.subscribe !== 'function') return delay(CONFIG.RETRY_DELAY);

  return new Promise((resolve) => {
    let timer;
    let unsubscribe;
    let finished = false;
    const stopSubscription = () => {
      const stop = unsubscribe;
      unsubscribe = undefined;
      try { if (typeof stop === 'function') stop(); } catch (error) { /* Readiness still settles. */ }
    };
    const finish = () => {
      if (finished) return;
      finished = true;
      if (timer !== undefined) clearTimeout(timer);
      stopSubscription();
      resolve();
    };
    const check = () => {
      if (extractNumericId(safeIdentity(getIdentity).gid)) finish();
    };

    // Re-read around registration so an update between the earlier identity
    // check and subscribing cannot be missed. Some signals also notify during
    // subscribe(), before they return their unsubscribe function.
    check();
    if (finished) return;
    timer = setTimeout(finish, CONFIG.RETRY_DELAY);
    try {
      unsubscribe = signal.subscribe(check);
      if (finished) stopSubscription();
      else check();
    } catch (error) {
      check();
    }
  });
}

// ---------------------------------------------------------------------------
// Always use the existing backend contract: { order_number, arrLocation }.
// Shopify's page APIs cannot supply our location flags and delivery notes, so
// skipping this request could show a scanner QR for a delivery-only order.
// ---------------------------------------------------------------------------
async function fetchOrderMeta(numericOrderId, attempt) {
  const startedAt = Date.now();
  let status = null;
  let reasonCode = null;
  try {
    const response = await fetch(`${CONFIG.API_BASE_URL}/api/getordernumber/${numericOrderId}`, {
      method: 'GET',
      cache: 'no-store'
    });
    if (Number.isInteger(response.status) && response.status >= 100 && response.status <= 599) {
      status = response.status;
    }
    if (!response.ok) {
      reasonCode = 'HTTP_ERROR';
      const err = new Error('Order metadata request failed');
      err.status = response.status;
      err.reasonCode = 'HTTP_ERROR';
      throw err;
    }
    // Include response-body reading in the request duration. This event proves
    // transport/JSON completion; the caller still validates the actual data.
    reasonCode = 'RESPONSE_INVALID';
    const data = await response.json();
    reasonCode = null;
    return { data, status: response.status };
  } catch (error) {
    if (status === null) reasonCode = error?.name === 'AbortError' ? 'REQUEST_ABORTED' : 'REQUEST_FAILED';
    throw error;
  } finally {
    // One private-data-free event per request separates slow responses from
    // retry pauses. Never log the URL/ID, raw error, body, or customer details.
    // Diagnostics must not change success/failure if a host logger throws.
    try {
      console.info('[checkout-ui] Order metadata request:', JSON.stringify({
        attempt,
        elapsedMs: Math.max(0, Date.now() - startedAt),
        status,
        reasonCode
      }));
    } catch (error) { /* Loading and recovery do not depend on diagnostics. */ }
  }
}

// ---------------------------------------------------------------------------
// View builders — each returns a detached `s-*` node tree (never mounts).
// ---------------------------------------------------------------------------

// Loading state: spinner + (optional retry progress) message.
function buildLoading(retryCount) {
  const message = retryCount > 0 ? TEXTS.LOADING_RETRY(retryCount, CONFIG.MAX_RETRIES) : TEXTS.LOADING;
  return createEl('s-stack', { direction: 'block', gap: 'base' }, [
    createEl('s-spinner', { accessibilityLabel: TEXTS.LOADING }),
    createEl('s-text', {}, message)
  ]);
}

// Keep recovery inside the current page. Reloading a first confirmation page
// can navigate away, so the button starts both lookups again without navigation.
function buildError(getIdentity, getIdentitySignal) {
  let retrying = false;
  return createEl('s-stack', { direction: 'block', gap: 'base' }, [
    createEl('s-banner', { tone: 'critical' }, [createEl('s-text', {}, TEXTS.ERROR)]),
    createEl('s-button', { variant: 'secondary', onClick: () => {
      // A second queued click must not start a competing request or rendering.
      if (retrying) return;
      retrying = true;
      return runOrderExtension(getIdentity, getIdentitySignal);
    } }, TEXTS.RETRY)
  ]);
}

// The QR must encode the backend's plain numeric order number. Polaris supports
// only `base` and `fill` sizes; filling its container keeps it easy to scan.
function buildQrSection(orderNumber) {
  const qr = createEl('s-qr-code', {
    content: String(orderNumber),
    size: 'fill',
    accessibilityLabel: `QR-Code für Bestellung ${orderNumber}`
  });

  const instructions = TEXTS.QR_INSTRUCTIONS.map((text) => createEl('s-paragraph', {}, text));

  // "Fragen und Antworten ... findest du hier." with `hier` as an external link.
  const faqLine = createEl('s-paragraph', {}, [
    TEXTS.FAQ_PREFIX,
    createEl('s-link', { href: TEXTS.FAQ_LINK, target: '_blank' }, TEXTS.FAQ_TEXT),
    '.'
  ]);

  // Keep the narrow stacked / wide side-by-side layout. Equal desktop columns
  // give the QR half the available width instead of squeezing it into 40%.
  // The instructions wrap in the other half, while mobile keeps a full-width QR.
  // Container queries work inside Shopify's extension sandbox;
  // window.matchMedia and the old viewport API do not. Fraction columns also
  // leave room for the gap, unlike two percentage columns that total 100%.
  return createEl('s-query-container', {}, [
    createEl('s-grid', {
      gridTemplateColumns: '@container (inline-size > 400px) 1fr 1fr, 1fr',
      gap: 'large',
      alignItems: 'start'
    }, [
      createEl('s-box', { inlineSize: '100%', minInlineSize: '0' }, [qr]),
      createEl('s-stack', { direction: 'block', gap: 'small', minInlineSize: '0' }, [...instructions, faqLine])
    ])
  ]);
}

// Delivery / station-pickup info block (shown instead of the QR when the
// backend flags the order as delivery or station pickup).
function buildDeliveryInfo(arrLocation, isDelivery) {
  const children = [
    createEl('s-heading', {}, isDelivery ? TEXTS.DELIVERY_HEADING : TEXTS.PICKUP_HEADING),
    createEl('s-paragraph', {}, isDelivery ? (arrLocation?.checkout_note || TEXTS.DELIVERY_FALLBACK) : TEXTS.PICKUP_INSTRUCTION)
  ];
  if (isDelivery && arrLocation?.checkout_hyperlink) {
    children.push(
      createEl('s-link', { href: arrLocation.checkout_hyperlink, target: '_blank' },
        arrLocation.checkout_hyperlink_text || TEXTS.DELIVERY_LINK_DEFAULT)
    );
  }
  return createEl('s-stack', { direction: 'block', gap: 'base' }, children);
}

// Success state: choose delivery/pickup info OR the QR, exactly like before.
function buildSuccess(state) {
  const isDelivery = state.arrLocation?.name === 'Delivery';
  const isStationPickup = state.stationFlag === 'Y';

  const children = [];
  if (isDelivery || isStationPickup) {
    children.push(buildDeliveryInfo(state.arrLocation, isDelivery));
  } else if (state.orderNumber) {
    children.push(buildQrSection(state.orderNumber));
  } else {
    children.push(createEl('s-text', { tone: 'critical' }, TEXTS.NO_ORDER_NUMBER));
  }

  if (children.length === 0) {
    children.push(createEl('s-text', {}, TEXTS.SUCCESS_FALLBACK));
  }
  return createEl('s-stack', { direction: 'block', gap: 'base' }, children);
}

// Mount a freshly built node tree into document.body (replacing any prior UI).
function mount(node) {
  if (document?.body) {
    document.body.replaceChildren(node);
  }
}

// ---------------------------------------------------------------------------
// The shared orchestrator. Each target passes a `getIdentity()` that reads its
// own source of truth from the global `shopify` object and returns
// { gid }. Show loading, wait for a valid order GID, then fetch our backend's
// order number and location metadata. Do not render a provisional QR while that
// request is pending: its eventual result may require delivery/pickup text.
// Both targets share the same bounded waits, recovery and error messages.
// ---------------------------------------------------------------------------
export async function runOrderExtension(getIdentity, getIdentitySignal) {
  const state = { orderNumber: null, stationFlag: null, arrLocation: null };
  let stage = 'order-identity';
  let attempt = 0;
  let status = null;
  const startedAt = Date.now();
  // These elapsed stages separate late Shopify identity from backend latency.
  // Log only controlled stage names and durations, never order/customer data.
  const recordTiming = (timingStage) => console.info('[checkout-ui] Order loading timing:', JSON.stringify({
    stage: timingStage,
    elapsedMs: Math.max(0, Date.now() - startedAt)
  }));

  // 1. Immediate loading state.
  mount(buildLoading(0));
  recordTiming('started');

  try {
    // 2. Wait for the order identity to become available (data can lag on busy
    //    stores). We re-read the global each attempt because it is a signal.
    let identity = { gid: null };
    for (attempt = 1; attempt <= CONFIG.MAX_RETRIES; attempt++) {
      identity = safeIdentity(getIdentity);
      if (extractNumericId(identity.gid)) break;
      if (attempt > 1) mount(buildLoading(attempt));
      if (attempt < CONFIG.MAX_RETRIES) await waitForIdentity(getIdentity, getIdentitySignal);
    }

    // 3. Resolve the actual order resource ID, never its confirmation number.
    const numericId = extractNumericId(identity.gid);
    if (!numericId || numericId === '0') {
      throw Object.assign(new Error('Order id could not be extracted'), { reasonCode: 'ORDER_ID_UNAVAILABLE' });
    }
    recordTiming('identity-ready');

    let lastError = null;
    stage = 'order-metadata';
    for (attempt = 1; attempt <= CONFIG.MAX_RETRIES; attempt++) {
      try {
        const response = await fetchOrderMeta(numericId, attempt);
        const data = response.data;
        status = response.status;
        state.orderNumber = data?.order_number != null ? String(data.order_number) : null;
        // The endpoint returns a positive numeric order number and arrLocation
        // (which may legitimately be null). Reject incomplete responses instead
        // of inventing default location flags and accidentally showing a QR.
        if (!state.orderNumber || !/^[1-9]\d*$/.test(state.orderNumber)) {
          throw Object.assign(new Error('Order number not found'), { reasonCode: 'ORDER_NUMBER_INVALID' });
        }
        if (!Object.prototype.hasOwnProperty.call(data, 'arrLocation') ||
          (data.arrLocation !== null && (typeof data.arrLocation !== 'object' || Array.isArray(data.arrLocation)))) {
          throw Object.assign(new Error('Order location metadata not found'), { reasonCode: 'LOCATION_METADATA_INVALID' });
        }
        state.stationFlag = data.arrLocation?.no_station;
        state.arrLocation = data.arrLocation;
        recordTiming('metadata-ready');
        mount(buildSuccess(state));
        return;
      } catch (error) {
        lastError = error;
        if (shouldRetryFetch(error) && attempt < CONFIG.MAX_RETRIES) {
          mount(buildLoading(attempt));
          // Restore fast initial recovery without repeatedly hammering a slow
          // backend. Rate limits and all later failures retain the longer gap.
          const retryDelay = attempt === 1 && error?.status !== 429
            ? CONFIG.INITIAL_METADATA_RETRY_DELAY : CONFIG.RETRY_DELAY;
          await delay(retryDelay);
          continue;
        }
        throw error;
      }
    }
    throw lastError || new Error('Order data unavailable');
  } catch (error) {
    // These small diagnostic fields distinguish late identity from a failed
    // lookup without logging identifiers, confirmation text or response data.
    const reasonCodes = ['ORDER_ID_UNAVAILABLE', 'HTTP_ERROR', 'ORDER_NUMBER_INVALID', 'LOCATION_METADATA_INVALID'];
    // The iframe log flattens objects to "[object Object]". A JSON string keeps
    // these safe diagnostic fields readable without adding private values.
    console.error('[checkout-ui] Order details could not be loaded:', JSON.stringify({
      stage,
      attempt: Math.min(attempt, CONFIG.MAX_RETRIES),
      status: typeof error?.status === 'number' ? error.status : status,
      reasonCode: reasonCodes.includes(error?.reasonCode)
        ? error.reasonCode
        : (error?.name === 'AbortError' ? 'REQUEST_ABORTED' : 'REQUEST_FAILED')
    }));
    mount(buildError(getIdentity, getIdentitySignal));
  }
}

// Read the target-specific identity without ever throwing (the global may not
// be ready yet on the first attempts).
function safeIdentity(getIdentity) {
  try {
    const result = getIdentity() || {};
    return { gid: result.gid || null };
  } catch (error) {
    return { gid: null };
  }
}
