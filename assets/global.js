//mib348
// if (performance.navigation.type == 2 && (window.location.pathname === "/pages/bestellen" || window.location.pathname === "/pages/datum" || window.location.pathname === "/pages/order-menue")) {
//     sessionStorage.clear();
//     window.location.href = "/pages/bestellen";
// }

function generateShortUUID() {
  return 'xxxxxx'.replace(/[x]/g, function () {
    return (Math.random() * 16 | 0).toString(16);
  });
}

if (localStorage.getItem("uuid") == null) {
  const uuid = generateShortUUID();
  localStorage.setItem("uuid", uuid);
}

function getQueryParams() {
  return new URLSearchParams(window.location.search);
}

// This marker is set only when the customer leaves the storefront for Shopify checkout.
// We use it later on the storefront to decide whether a stale order session should be cleaned up.
const CHECKOUT_RETURN_MARKER = 'sushi_checkout_in_progress';

// These keys describe the active ordering session that powers the red top bar and menu flow.
// We remove only these keys after a completed checkout so other browser storage remains untouched.
const ORDER_SESSION_STORAGE_KEYS = [
  'location',
  'date',
  'no_station',
  'immediate_inventory',
  'b_additional_inventory',
  'additional_inventory',
  'additional_inventory_time',
  'strYStockOnlyCheck',
  'snacks_and_drinks',
  'time_slot',
  CHECKOUT_RETURN_MARKER
];

function clearOrderSessionState() {
  ORDER_SESSION_STORAGE_KEYS.forEach(function (key) {
    sessionStorage.removeItem(key);
  });
}

function reconcileCheckoutReturnState(onComplete) {
  const complete = typeof onComplete === 'function' ? onComplete : function () {};
  const activeCheckoutMarker = sessionStorage.getItem(CHECKOUT_RETURN_MARKER);
  const excludedPaths = ['/pages/bestellen', '/pages/datum', '/pages/order-menue', '/cart'];

  if (!activeCheckoutMarker || excludedPaths.includes(window.location.pathname)) {
    complete();
    return;
  }

  const rootUrl =
    window.Shopify && window.Shopify.routes && window.Shopify.routes.root
      ? window.Shopify.routes.root
      : '/';

  fetch(rootUrl + 'cart.js', { credentials: 'same-origin' })
    .then(function (response) {
      return response.json();
    })
    .then(function (cart) {
      if (cart && cart.item_count === 0) {
        clearOrderSessionState();
        document.querySelectorAll('.location_bar').forEach(function (element) {
          element.remove();
        });
      }
    })
    .catch(function (error) {
      console.error('[Checkout Return] Failed to reconcile checkout return state:', error);
    })
    .finally(function () {
      complete();
    });
}

// VPN/Proxy guard: show warning strip and block add-to-cart actions.
(function initializeVpnCartGuard() {
  if (window.__vpnCartGuardInitialized) return;
  window.__vpnCartGuardInitialized = true;
  window.__vpnGuardBuild = '2026-02-10-v3';

  const CACHE_KEY = 'vpn_detection_cache_v1';
  const BLOCKED_MESSAGE = 'VPN/Proxy erkannt. Produkte koennen nicht in den Warenkorb gelegt werden.';
  const BLOCKED_TITLE = 'VPN/Proxy erkannt. Bitte deaktivieren Sie den VPN/Proxy.';
  const ADD_TO_CART_SELECTOR = [
    'form[action*="/cart/add"] [type="submit"]',
    'button[name="add"]',
    '.product-form__submit',
    '[data-add-to-cart]',
    'a[href*="/cart/add"]',
    '.shopify-payment-button__button',
    'button[name="plus"]'
  ].join(', ');

  window.vpnDetectionState = window.vpnDetectionState || {
    checked: false,
    blocked: false,
    isVpn: false,
    isProxy: false,
    isTor: false,
    isRelay: false,
    isDatacenter: false,
    heuristicMatch: false,
    providerSignals: [],
    source: null,
    checkedAt: null
  };

  function parseBooleanFlag(value) {
    if (value === true || value === 1) return true;
    if (typeof value === 'string') {
      const normalized = value.trim().toLowerCase();
      return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'y';
    }
    return false;
  }

  function getPathFromUrl(url) {
    if (!url) return '';
    try {
      return new URL(url, window.location.origin).pathname.toLowerCase();
    } catch (error) {
      return String(url).toLowerCase();
    }
  }

  function isCartAddPath(url) {
    const path = getPathFromUrl(url);
    return /\/cart\/add(\.js)?$/.test(path);
  }

  function isCartAddRequest(url) {
    if (!url) return false;
    return isCartAddPath(url);
  }

  function disableAddToCartElements(rootNode) {
    if (!window.vpnDetectionState || !window.vpnDetectionState.blocked) return;
    const root = rootNode && rootNode.querySelectorAll ? rootNode : document;
    const elements = [];

    if (root !== document && root.matches && root.matches(ADD_TO_CART_SELECTOR)) {
      elements.push(root);
    }

    root.querySelectorAll(ADD_TO_CART_SELECTOR).forEach((element) => {
      elements.push(element);
    });

    elements.forEach((element) => {
      if (element.getAttribute('data-vpn-cart-disabled') === 'true') return;
      element.setAttribute('data-vpn-cart-disabled', 'true');
      element.setAttribute('aria-disabled', 'true');
      element.setAttribute('title', BLOCKED_TITLE);
      element.classList.add('vpn-cart-disabled');

      if ('disabled' in element) {
        element.disabled = true;
      } else {
        element.style.pointerEvents = 'none';
      }

      if (element.tagName === 'A') {
        element.setAttribute('tabindex', '-1');
      }
    });
  }

  function ensureWarningStyles() {
    if (document.getElementById('vpn-warning-strip-style')) return;

    const style = document.createElement('style');
    style.id = 'vpn-warning-strip-style';
    style.textContent = `
      #vpn-warning-strip {
        background: #9a1111;
        color: #ffffff;
        text-align: center;
        padding: 10px 14px;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.35;
        position: relative;
        z-index: 9999;
      }
      .vpn-cart-disabled {
        opacity: 0.55 !important;
        cursor: not-allowed !important;
        pointer-events: none !important;
      }
    `;
    document.head.appendChild(style);
  }

  function ensureWarningStrip() {
    if (document.getElementById('vpn-warning-strip')) return;
    ensureWarningStyles();

    const strip = document.createElement('div');
    strip.id = 'vpn-warning-strip';
    strip.setAttribute('role', 'alert');
    strip.textContent = BLOCKED_MESSAGE;

    const header = document.querySelector('#shopify-section-header, .header-wrapper, header');
    if (header && header.parentNode) {
      header.parentNode.insertBefore(strip, header);
    } else if (document.body) {
      document.body.insertAdjacentElement('afterbegin', strip);
    }
  }

  let observerAttached = false;
  function attachMutationObserver() {
    if (observerAttached || !document.documentElement) return;
    observerAttached = true;

    const observer = new MutationObserver((mutations) => {
      if (!window.vpnDetectionState || !window.vpnDetectionState.blocked) return;
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType !== 1) return;
          disableAddToCartElements(node);
        });
      });
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  function dispatchDetectionEvent() {
    try {
      window.dispatchEvent(
        new CustomEvent('vpn-detection-complete', {
          detail: Object.assign({}, window.vpnDetectionState)
        })
      );
    } catch (error) {
      console.warn('[VPN Guard] Failed to dispatch detection event', error);
    }
  }

  function activateBlockedMode() {
    ensureWarningStrip();
    disableAddToCartElements(document);
    attachMutationObserver();
  }

  function readCachedState() {
    return null;
  }

  function writeCachedState(result) {
    try {
      // Avoid stale false negatives when users toggle VPN between page loads.
      localStorage.removeItem(CACHE_KEY);
    } catch (error) {
      // Ignore storage failures
    }
  }

  function applyDetectionResult(result, sourceLabel) {
    const nextState = {
      checked: true,
      blocked: !!result.blocked,
      isVpn: !!result.isVpn,
      isProxy: !!result.isProxy,
      isTor: !!result.isTor,
      isRelay: !!result.isRelay,
      isDatacenter: !!result.isDatacenter,
      heuristicMatch: !!result.heuristicMatch,
      providerSignals: Array.isArray(result.providerSignals) ? result.providerSignals : [],
      source: sourceLabel || result.source || null,
      checkedAt: Date.now()
    };

    window.vpnDetectionState = Object.assign(window.vpnDetectionState || {}, nextState);
    writeCachedState(window.vpnDetectionState);

    if (window.vpnDetectionState.blocked) {
      activateBlockedMode();
    }

    dispatchDetectionEvent();
    return window.vpnDetectionState;
  }

  function parseDetectionPayload(payload) {
    if (!payload || typeof payload !== 'object') return null;
    const security = payload.security || (payload.data && payload.data.security) || {};

    const isVpn = parseBooleanFlag(payload.is_vpn) || parseBooleanFlag(security.is_vpn) || parseBooleanFlag(security.vpn);
    const isProxy = parseBooleanFlag(payload.is_proxy) || parseBooleanFlag(security.is_proxy) || parseBooleanFlag(security.proxy);
    const isTor = parseBooleanFlag(payload.is_tor) || parseBooleanFlag(security.is_tor) || parseBooleanFlag(security.tor);
    const isRelay = parseBooleanFlag(payload.is_relay) || parseBooleanFlag(security.relay);
    const isDatacenter =
      parseBooleanFlag(payload.is_datacenter) ||
      parseBooleanFlag(payload.is_hosting) ||
      parseBooleanFlag(payload.hosting) ||
      parseBooleanFlag(payload.datacenter) ||
      (typeof payload.company?.type === 'string' && payload.company.type.toLowerCase() === 'hosting') ||
      (typeof payload.asn?.type === 'string' && ['hosting', 'business'].includes(payload.asn.type.toLowerCase()));

    const providerFingerprint = [
      payload.org,
      payload.isp,
      payload.as,
      payload.company?.name,
      payload.company?.domain,
      payload.asn?.org,
      payload.asn?.descr,
      payload.asn?.domain
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();

    const vpnProviderPattern = /\b(proton|protonvpn|nordvpn|surfshark|mullvad|expressvpn|cyberghost|private internet access|pia|ipvanish|windscribe|tunnelbear|purevpn|hide\.?me|hidemyass|hma|ivpn|torguard)\b/i;
    const vpnDatacenterPattern = /\b(m247|datacamp|choopa|vultr|leaseweb|worldstream|ovh|digitalocean|linode|hetzner|contabo|webzilla|serverius|gcore)\b/i;

    const hasVpnProviderKeyword = providerFingerprint !== '' && vpnProviderPattern.test(providerFingerprint);
    const hasVpnDatacenterKeyword = providerFingerprint !== '' && vpnDatacenterPattern.test(providerFingerprint);
    const heuristicMatch = hasVpnProviderKeyword || hasVpnDatacenterKeyword || isDatacenter;

    const hasAnySignal =
      payload.is_vpn != null ||
      payload.is_proxy != null ||
      payload.is_tor != null ||
      payload.is_relay != null ||
      payload.is_datacenter != null ||
      payload.hosting != null ||
      payload.datacenter != null ||
      security.is_vpn != null ||
      security.is_proxy != null ||
      security.is_tor != null ||
      security.vpn != null ||
      security.proxy != null ||
      security.tor != null ||
      security.relay != null ||
      providerFingerprint !== '';

    if (!hasAnySignal) return null;

    return {
      blocked: isVpn || isProxy || isTor || isRelay || heuristicMatch,
      isVpn,
      isProxy,
      isTor,
      isRelay,
      isDatacenter,
      heuristicMatch
    };
  }

  function fetchJsonWithTimeout(url, timeoutMs) {
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timeout = setTimeout(() => {
      if (controller) controller.abort();
    }, timeoutMs);

    return fetch(url, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'omit',
      headers: {
        Accept: 'application/json'
      },
      signal: controller ? controller.signal : undefined
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
      })
      .finally(() => {
        clearTimeout(timeout);
      });
  }

  let detectionPromise = null;
  function runVpnDetection() {
    if (detectionPromise) return detectionPromise;

    detectionPromise = (async () => {
      const cached = readCachedState();
      if (cached) {
        window.vpnDetectionState = Object.assign(window.vpnDetectionState || {}, cached, {
          source: `${cached.source || 'cache'} (cache)`
        });
        if (window.vpnDetectionState.blocked) {
          activateBlockedMode();
        }
        dispatchDetectionEvent();
        return window.vpnDetectionState;
      }

      const providers = [
        { name: 'ipapi.is', url: 'https://api.ipapi.is/?output=json', timeoutMs: 4000 },
        { name: 'ipwho.is', url: 'https://ipwho.is/?output=json&security=1', timeoutMs: 2500 },
        { name: 'ipwhois.app', url: 'https://ipwhois.app/json/', timeoutMs: 2500 }
      ];

      const checks = await Promise.all(
        providers.map(async (provider) => {
          try {
            const payload = await fetchJsonWithTimeout(provider.url, provider.timeoutMs);
            const parsed = parseDetectionPayload(payload);
            if (!parsed) return null;
            return {
              provider: provider.name,
              parsed
            };
          } catch (error) {
            console.warn(`[VPN Guard] ${provider.name} detection failed`, error);
            return null;
          }
        })
      );

      const parsedResults = checks.filter(Boolean);

      if (parsedResults.length > 0) {
        const merged = parsedResults.reduce(
          (acc, item) => {
            acc.blocked = acc.blocked || !!item.parsed.blocked;
            acc.isVpn = acc.isVpn || !!item.parsed.isVpn;
            acc.isProxy = acc.isProxy || !!item.parsed.isProxy;
            acc.isTor = acc.isTor || !!item.parsed.isTor;
            acc.isRelay = acc.isRelay || !!item.parsed.isRelay;
            acc.isDatacenter = acc.isDatacenter || !!item.parsed.isDatacenter;
            acc.heuristicMatch = acc.heuristicMatch || !!item.parsed.heuristicMatch;
            return acc;
          },
          {
            blocked: false,
            isVpn: false,
            isProxy: false,
            isTor: false,
            isRelay: false,
            isDatacenter: false,
            heuristicMatch: false
          }
        );

        merged.providerSignals = parsedResults.map((item) => ({
          provider: item.provider,
          blocked: item.parsed.blocked,
          isVpn: item.parsed.isVpn,
          isProxy: item.parsed.isProxy,
          isTor: item.parsed.isTor,
          isRelay: item.parsed.isRelay,
          isDatacenter: item.parsed.isDatacenter,
          heuristicMatch: item.parsed.heuristicMatch
        }));

        applyDetectionResult(merged, parsedResults.map((item) => item.provider).join(', '));
        console.log('[VPN Guard] Detection result:', window.vpnDetectionState);
        return window.vpnDetectionState;
      }

      // Fail open when providers are unavailable.
      applyDetectionResult(
        {
          blocked: false,
          isVpn: false,
          isProxy: false,
          isTor: false,
          isRelay: false,
          isDatacenter: false,
          heuristicMatch: false,
          providerSignals: []
        },
        'unavailable'
      );
      return window.vpnDetectionState;
    })();

    return detectionPromise;
  }

  function installFetchGuard() {
    if (!window.fetch || window.__vpnFetchGuardInstalled) return;
    window.__vpnFetchGuardInstalled = true;

    const originalFetch = window.fetch.bind(window);
    window.fetch = function (resource, init) {
      try {
        const requestUrl =
          typeof resource === 'string'
            ? resource
            : resource && resource.url
              ? resource.url
              : '';

        if (window.vpnDetectionState && window.vpnDetectionState.blocked && isCartAddRequest(requestUrl)) {
          console.warn('[VPN Guard] Blocked fetch add-to-cart request:', requestUrl);
          return Promise.resolve(
            new Response(
              JSON.stringify({
                status: 'blocked',
                message: BLOCKED_MESSAGE
              }),
              {
                status: 403,
                headers: { 'Content-Type': 'application/json' }
              }
            )
          );
        }
      } catch (error) {
        console.warn('[VPN Guard] Fetch guard error', error);
      }

      return originalFetch(resource, init);
    };
  }

  function installXhrGuard() {
    if (!window.XMLHttpRequest || window.__vpnXhrGuardInstalled) return;
    window.__vpnXhrGuardInstalled = true;

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
      this.__vpnGuardUrl = url;
      return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
      if (window.vpnDetectionState && window.vpnDetectionState.blocked && isCartAddRequest(this.__vpnGuardUrl)) {
        console.warn('[VPN Guard] Blocked XHR add-to-cart request:', this.__vpnGuardUrl);
        this.abort();
        return;
      }
      return originalSend.apply(this, arguments);
    };
  }

  function installDomSubmitGuard() {
    if (window.__vpnSubmitGuardInstalled) return;
    window.__vpnSubmitGuardInstalled = true;

    document.addEventListener(
      'submit',
      function (event) {
        if (!window.vpnDetectionState || !window.vpnDetectionState.blocked) return;
        const form = event.target;
        if (!form || form.nodeName !== 'FORM') return;
        const action = form.getAttribute('action') || '';
        if (isCartAddRequest(action)) {
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
        }
      },
      true
    );
  }

  function installDomClickGuard() {
    if (window.__vpnClickGuardInstalled) return;
    window.__vpnClickGuardInstalled = true;

    document.addEventListener(
      'click',
      function (event) {
        if (!window.vpnDetectionState || !window.vpnDetectionState.blocked) return;
        const trigger = event.target && event.target.closest ? event.target.closest(ADD_TO_CART_SELECTOR) : null;
        if (!trigger) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        event.stopPropagation();
      },
      true
    );
  }

  window.runVpnDetection = runVpnDetection;
  window.isVpnCartBlocked = function () {
    return !!(window.vpnDetectionState && window.vpnDetectionState.blocked);
  };
  window.debugVpnDetection = async function () {
    detectionPromise = null;
    try {
      localStorage.removeItem(CACHE_KEY);
    } catch (error) {
      // Ignore storage failures
    }
    return runVpnDetection();
  };

  installFetchGuard();
  installXhrGuard();
  installDomSubmitGuard();
  installDomClickGuard();

  runVpnDetection().catch((error) => {
    console.warn('[VPN Guard] Unexpected detection error', error);
  });
})();

// Ensure URL-provided location/date always override stored values (highest priority).
(() => {
  const initialParams = getQueryParams();
  const urlLocation = initialParams.get('location');
  const urlDate = initialParams.get('date');

  if (urlLocation && urlLocation.trim() !== '') {
    sessionStorage.setItem('location', urlLocation.trim());
  }

  if (urlDate && urlDate.trim() !== '') {
    sessionStorage.setItem('date', urlDate.trim());
  }
})();

// Disable clicks until the page is fully loaded (bestellen page only)
if (window.location && window.location.pathname === "/pages/bestellen") {
  (function () {
    // Add CSS for spinner and disabled state
    const style = document.createElement('style');
    style.textContent = `
      .next-button-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #ffffff;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 1s ease-in-out infinite;
        margin-right: 8px;
      }
      @keyframes spin {
        to { transform: rotate(360deg); }
      }
      .bestellen-disabled {
        opacity: 0.6;
        pointer-events: none;
        cursor: not-allowed;
      }
    `;
    document.head.appendChild(style);

    // Function to add spinner to next button and disable dropdowns
    const addLoadingState = function () {
      const nextButton = document.querySelector('#next_button');
      if (nextButton && !nextButton.querySelector('.next-button-spinner')) {
        const spinner = document.createElement('span');
        spinner.className = 'next-button-spinner';
        nextButton.insertBefore(spinner, nextButton.firstChild);
        nextButton.disabled = true;
        nextButton.style.opacity = '0.6';
        nextButton.style.cursor = 'not-allowed';
      }

      // Disable all dropdowns/selects
      const dropdowns = document.querySelectorAll('#stationDropdown, .dropdown-menu, .dropdown-toggle');
      dropdowns.forEach(function (dropdown) {
        dropdown.classList.add('bestellen-disabled');
        if (dropdown.tagName === 'SELECT') {
          dropdown.disabled = true;
        }
      });
    };

    // Function to remove spinner from next button and enable dropdowns
    const removeLoadingState = function () {
      const nextButton = document.querySelector('#next_button');
      if (nextButton) {
        const spinner = nextButton.querySelector('.next-button-spinner');
        if (spinner) {
          spinner.remove();
        }
        nextButton.disabled = false;
        nextButton.style.opacity = '';
        nextButton.style.cursor = '';
      }

      // Enable all dropdowns/selects
      const dropdowns = document.querySelectorAll('#stationDropdown, .dropdown-menu, .dropdown-toggle');
      dropdowns.forEach(function (dropdown) {
        dropdown.classList.remove('bestellen-disabled');
        if (dropdown.tagName === 'SELECT') {
          dropdown.disabled = false;
        }
      });
    };

    // Use a capturing listener so we intercept as early as possible
    const __bestellenPreventClick__ = function (e) {
      if (!window.__bestellenPageFullyLoaded) {
        e.stopPropagation();
        e.preventDefault();
      }
    };

    // Add loading state immediately
    addLoadingState();

    document.addEventListener("click", __bestellenPreventClick__, true);
    window.addEventListener("load", function () {
      window.__bestellenPageFullyLoaded = true;
      document.removeEventListener("click", __bestellenPreventClick__, true);
      removeLoadingState();
    });
  })();
}

// if (localStorage.getItem("location") != null && sessionStorage.getItem("location") == null) {
//   sessionStorage.setItem("location", localStorage.getItem("location"));
// }

document.addEventListener('DOMContentLoaded', function () {
  console.log('[Cart Manager] Page loaded:', window.location.pathname);

  // Define critical paths that need cart preservation
  const CRITICAL_PATHS = ['/pages/order-menue', '/cart', '/checkout'];
  const currentPath = window.location.pathname;

  // Simple helper to check if a path is critical
  const isPathCritical = (path) => {
    const isCritical = CRITICAL_PATHS.includes(path);
    console.log('[Cart Manager] Checking if path is critical:', path, isCritical);
    return isCritical;
  };

  // Simple helper to clear cart
  const clearCart = () => {
    console.log('[Cart Manager] Clearing cart...');
    return $.ajax({
      type: "POST",
      url: window.Shopify.routes.root + "cart/clear.js",
      async: false,
      dataType: "json"
    }).then(() => {
      console.log('[Cart Manager] Cart cleared successfully');
    }).catch(error => {
      console.error('[Cart Manager] Failed to clear cart:', error);
    });
  };

  // Handle navigation between pages
  if (isPathCritical(currentPath)) {
    console.log('[Cart Manager] On critical path, setting up navigation handlers');

    // Single handler for both unload events
    const handleNavigation = (event) => {
      // Get target URL if available
      const targetUrl = document.activeElement?.href;
      if (targetUrl) {
        const targetPath = new URL(targetUrl).pathname;
        console.log('[Cart Manager] Navigation detected. Target:', targetPath);

        // Only clear if navigating to non-critical path
        if (!isPathCritical(targetPath)) {
          console.log('[Cart Manager] Navigating to non-critical path, clearing cart');
          // Use sendBeacon for more reliable delivery during page unload
          if (navigator.sendBeacon) {
            navigator.sendBeacon(window.Shopify.routes.root + "cart/clear.js");
            console.log('[Cart Manager] Cart clear request sent via beacon');
          } else {
            // Fallback to sync XHR
            const xhr = new XMLHttpRequest();
            xhr.open("POST", window.Shopify.routes.root + "cart/clear.js", false);
            xhr.send();
            console.log('[Cart Manager] Cart clear request sent via sync XHR');
          }
        } else {
          console.log('[Cart Manager] Navigating to critical path, preserving cart');
        }
      }
    };

    window.addEventListener('pagehide', handleNavigation);
    window.addEventListener('beforeunload', handleNavigation);
    console.log('[Cart Manager] Navigation handlers attached');
  }

  // Handle checkout button click
  $(document).on("click", "#checkout", function (e) {
    console.log('[Cart Manager] Checkout button clicked');
    e.preventDefault();

    //get url params
    const urlParams = getQueryParams();
    const location = urlParams.get('location');
    const date = urlParams.get('date');
    const uuid = urlParams.get('uuid');
    const no_station = urlParams.get('no_station');
    const immediate_inventory = urlParams.get('immediate_inventory');
    const additional_inventory = urlParams.get('additional_inventory');
    const additional_inventory_time = urlParams.get('additional_inventory_time');

    // Fallbacks when URL params are missing (e.g., on /cart)
    const effectiveLocation = location || sessionStorage.getItem("location");
    const effectiveDate = date || sessionStorage.getItem("date");
    const effectiveImmediate = (immediate_inventory != null) ? immediate_inventory : sessionStorage.getItem("immediate_inventory");
    const isSameDayPreorder = effectiveImmediate === "N" && !!effectiveDate && effectiveDate === getFormattedDate();
    let shouldPreventCheckout = false;

    // First, get the cart to check if it contains only snacks_and_drinks items
    let cartHasOnlySnacks = false;
    if (isSameDayPreorder) {
      $.ajax({
        type: "GET",
        url: window.Shopify.routes.root + "cart.js",
        async: false,
        dataType: "json",
        success: function (cartData) {
          // Check if all items are snacks_and_drinks
          if (cartData.items.length > 0) {
            cartHasOnlySnacks = cartData.items.every(function (item) {
              return item.properties.snacks_and_drinks === "Y";
            });
            console.log('[Cart Manager] Cart contains only snacks:', cartHasOnlySnacks);
          }
        },
        error: function (xhr, status, error) {
          console.error('[Cart Manager] Failed to fetch cart for snacks check:', error);
        }
      });
    }

    // Only check time expiration if cart has non-snacks items
    if (isSameDayPreorder && !cartHasOnlySnacks) {
      $.ajax({
        url: `https://dev.sushi.catering/getLocations/${encodeURIComponent(effectiveLocation)}`,
        type: "GET",
        async: false,
        cache: true,
        dataType: "json",
        success: function (data) {
          // Save the same-day preorder end time
          window.samedayPreorderEndTime = data.sameday_preorder_end_time;
          // const additionalInventoryEnabled = data.additional_inventory === 'Y';
          // const additionalInventoryEndTime = data.second_additional_inventory_end_time;

          // Get the current date and time in Germany
          const now = new Date();
          const options = {
            timeZone: 'Europe/Berlin',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
          };
          const formatter = new Intl.DateTimeFormat('en-GB', options); // 'en-GB' for 24-hour format
          const parts = formatter.formatToParts(now);

          let dateObj = {};
          parts.forEach(({ type, value }) => {
            dateObj[type] = value;
          });

          const currentHours = parseInt(dateObj.hour, 10);
          const currentMinutes = parseInt(dateObj.minute, 10);
          const currentTimeInMinutes = currentHours * 60 + currentMinutes;

          // Determine applicable cutoff (additional inventory may extend same-day window)
          let cutoffTimeInMinutes = null;
          // if (additionalInventoryEnabled && additionalInventoryEndTime) {
          //   const [addHours, addMinutes] = additionalInventoryEndTime.split(':').map(Number);
          //   cutoffTimeInMinutes = addHours * 60 + addMinutes;
          // } else
          if (window.samedayPreorderEndTime) {
            const [coHours, coMinutes] = window.samedayPreorderEndTime.split(':').map(Number);
            cutoffTimeInMinutes = coHours * 60 + coMinutes;
          }

          if (cutoffTimeInMinutes !== null && currentTimeInMinutes >= cutoffTimeInMinutes) {
            // Same-day preorder time window has expired
            alert("Die Vorbestellung für heute ist abgeschlossen. Bitte wählen Sie ein anderes Datum.");
            $.ajax({
              type: "POST",
              url: window.Shopify.routes.root + "cart/clear.js",
              async: false,
              dataType: "json",
              success: function () {
                window.location.href = "/pages/bestellen";
              },
              error: function (xhr, status, error) {
                console.error('[Cart Manager] Failed to clear cart:', error);
              }
            });
            shouldPreventCheckout = true;
          }

        },
        error: function (xhr, status, error) {
          console.error('[Cart Manager] Failed to get locations:', error);
        }
      });
    } else if (isSameDayPreorder && cartHasOnlySnacks) {
      console.log('[Cart Manager] Cart contains only snacks, skipping client-side time check');
    }
    if (shouldPreventCheckout) {
      return;
    }
    $.ajax({
      type: "GET",
      url: window.Shopify.routes.root + "cart.js",
      dataType: "json",
      success: function (cart) {

        // Server-side check for same-day preorder cutoff, leveraging existing API
        let timeExpired = false;
        const isPreorder = (sessionStorage.getItem("immediate_inventory") || "N") === "N";
        const isToday = cart.items.length > 0 && cart.items[0].properties.date === getFormattedDate();

        // Check if cart contains any non-snacks items
        const hasNonSnacksItems = cart.items.some(function (item) {
          return item.properties.snacks_and_drinks !== "Y";
        });

        // Only check time expiration if cart has non-snacks items
        if (isPreorder && isToday && hasNonSnacksItems) {
          $.ajax({
            type: "POST",
            url: "https://dev.sushi.catering/api/checkOrderInventory",
            async: false,
            cache: false,
            data: {
              items: JSON.stringify(cart.items)
            },
            dataType: "json",
            success: function (response) {
              if (response.sameday_preorder_time_expired == 1) {
                timeExpired = true;
                alert("Die Vorbestellung für heute ist abgeschlossen. Bitte wählen Sie ein anderes Datum.");

                $.ajax({
                  type: "POST",
                  url: window.Shopify.routes.root + "cart/clear.js",
                  dataType: "json",
                  async: false,
                  success: function () {
                    window.location.href = "/pages/bestellen";
                  },
                  error: function () {
                    window.location.href = "/pages/bestellen"; // Still redirect on error
                  }
                });
              }
            },
            error: function () {
              console.error('Cart Check order Inventory API error on checkout');
              alert('Es gab einen Fehler bei der Überprüfung Ihrer Bestellung. Bitte versuchen Sie es erneut.');
              timeExpired = true;
            }
          });
        } else if (isPreorder && isToday && !hasNonSnacksItems) {
          console.log('[Cart Manager] Cart contains only snacks and drinks, skipping time expiration check');
        }

        if (timeExpired) {
          return; // Stop checkout process
        }

        console.log('[Cart Manager] Cart validation started');

        // 1. Delivery Minimum Check
        if (sessionStorage.getItem("location") === "Delivery") {
          const currentTotal = $(".totals__total-value").html();
          // min_order_limit is a global variable, assumed to be defined and updated elsewhere (e.g., on /cart page load)
          if (comparePrices(min_order_limit, currentTotal)) {
            console.log('[Cart Manager] Delivery minimum not met');
            alert('Die Mindestlieferbestellmenge sollte betragen: €' + min_order_limit + ' EUR');
            return;
          }
        }

        // 2. Date Array and Max Quantity Checks
        const dates = [];
        let quantityCheckFailed = false;
        let firstQuantityErrorElement = null;

        $.each(cart.items, function (index, item) {
          // Skip Pfand (bottle deposit) items from all validation - auto-managed by Pfand Manager
          if (item.variant_id === window.pfandConfig?.variantId) {
            console.log('[Cart Manager] Pfand item, skipping validation:', item.product_title);
            return;
          }

          dates.push(item.properties.date);

          // Skip all quantity validation for snacks_and_drinks items - they have unlimited inventory
          const isSnacksAndDrinks = item.properties.snacks_and_drinks === "Y";
          if (isSnacksAndDrinks) {
            console.log('[Cart Manager] Snacks and drinks item, skipping quantity check:', item.product_title);
            return; // Skip to next item
          }

          let stored_qty = parseInt(item.properties.max_quantity, 10);
          if (sessionStorage.getItem("location") === "Delivery") {
            stored_qty = 99; // For Delivery, max quantity is 99
          }

          //check if item properties are empty then gracefully clear the cart and ask the customer to add the item again
          if (item.properties.length === 0) {
            alert("Es gab einen Fehler bei der Überprüfung Ihres Warenkorbs. Bitte versuchen Sie es erneut, indem Sie das Produkt erneut hinzufügen.");
            $.ajax({
              type: "POST",
              url: window.Shopify.routes.root + "cart/clear.js",
              async: false,
              dataType: "json",
              success: function (response) {
                window.location.href = "/pages/bestellen";
              },
              error: function (xhr, status, error) {
                alert("Cart clear error:");
                console.log("Cart clear error:", error);
              },
            });
            return;
          }

          const quantityInput = $(`input.quantity__input[data-quantity-variant-id="${item.id}"]`);
          const quantityContainer = quantityInput.closest(".cart-item__quantity");

          if (item.quantity > stored_qty) {
            quantityCheckFailed = true;
            quantityInput.closest('.quantity').find('button[name="plus"]').attr('disabled', true).prop('disabled', true);
            if (!quantityContainer.find("small.lowstock").length) {
              quantityContainer.append(`<small class="lowstock" style="color:red;">Es sind nur ${stored_qty} Artikel verfügbar</small>`);
            }
            if (!firstQuantityErrorElement && quantityContainer.length) {
              firstQuantityErrorElement = quantityContainer;
            }
          } else if (item.quantity === stored_qty && stored_qty !== 99) { // Disable plus if quantity equals max (and not delivery special case)
            quantityInput.closest('.quantity').find('button[name="plus"]').attr('disabled', true).prop('disabled', true);
          }
        });

        // Scroll to the first quantity error if any
        if (quantityCheckFailed && firstQuantityErrorElement) {
          const elementTop = firstQuantityErrorElement.offset().top;
          window.scrollTo({ top: elementTop - 150, behavior: "smooth" });
        }

        // 3. Date Validation
        const allSameDate = dates.length > 0 && dates.every(date => date === dates[0]);
        if (!allSameDate) {
          console.log('[Cart Manager] Date validation failed:', dates);
          alert('Sie können nur Artikel hinzufügen, die das gleiche Vorbestellungsdatum haben.');
          return;
        }

        // 3.5. Location Validation - Ensure all cart items are from current location
        const currentLocation = sessionStorage.getItem("location");
        const locations = [];
        let locationMismatchFound = false;

        $.each(cart.items, function (index, item) {
          // Skip Pfand items from location mismatch validation
          if (item.variant_id === window.pfandConfig?.variantId) return;

          const itemLocation = item.properties.location;
          const isSnacksAndDrinks = item.properties.snacks_and_drinks === "Y";
          locations.push(itemLocation);

          // Skip location validation for snacks and drinks items
          if (isSnacksAndDrinks) {
            console.log('[Cart Manager] Snacks and drinks item, skipping location check:', item.product_title);
            return; // Continue to next item
          }

          // Check if item location matches current session location
          if (itemLocation && currentLocation && itemLocation !== currentLocation) {
            locationMismatchFound = true;
            console.warn('[Cart Manager] Location mismatch found:', {
              itemLocation: itemLocation,
              currentLocation: currentLocation,
              productTitle: item.product_title
            });
          }
        });

        // If location mismatch found, alert user and prevent checkout
        if (locationMismatchFound) {
          console.log('[Cart Manager] Location validation failed:', {
            currentLocation: currentLocation,
            cartLocations: locations
          });
          alert('Ihr Warenkorb enthält Artikel von verschiedenen Standorten. Bitte entfernen Sie Artikel von anderen Standorten oder wählen Sie einen einheitlichen Standort.');
          return;
        }

        // Additional check: Ensure all non-snacks items have the same location among themselves
        const nonSnacksLocations = [];
        $.each(cart.items, function (index, item) {
          const isSnacksAndDrinks = item.properties.snacks_and_drinks === "Y";
          // Skip both snacks_and_drinks and Pfand items from location uniqueness check
          const isPfandItem = item.variant_id === window.pfandConfig?.variantId;
          if (!isSnacksAndDrinks && !isPfandItem && item.properties.location) {
            nonSnacksLocations.push(item.properties.location);
          }
        });

        const allSameLocation = nonSnacksLocations.length === 0 || nonSnacksLocations.every(location => location === nonSnacksLocations[0]);
        if (!allSameLocation) {
          console.log('[Cart Manager] Mixed location items in cart (excluding snacks):', nonSnacksLocations);
          alert('Ihr Warenkorb enthält Artikel von verschiedenen Standorten. Bitte entfernen Sie Artikel von anderen Standorten oder wählen Sie einen einheitlichen Standort.');
          return;
        }

        console.log('[Cart Manager] Location validation passed. All items from:', currentLocation);

        // 4. Agreement Checks
        if (sessionStorage.getItem("location") !== "Delivery") {
          if (!$('#incorrent_item_agree').is(':checked')) {
            console.log('[Cart Manager] Item agreement not checked');
            alert("Um zur Kasse zu gehen und fortzufahren, müssen Sie zustimmen, dass Sie keine Artikel aus Bestellungen Dritter annehmen, und dass bei Entnahme eines falschen Artikels eine 20€-Gebühr pro Artikel fällig wird.");
            return;
          }
        }

        if (!$('#agree').is(':checked')) {
          console.log('[Cart Manager] Terms agreement not checked');
          alert("Um zur Kasse gehen zu können, müssen Sie den Allgemeinen Geschäftsbedingungen zustimmen.");
          return;
        }

        // 5. Post-Agreement Quantity Check (if any item exceeded its max quantity)
        if (quantityCheckFailed) {
          console.log('[Cart Manager] Quantity validation failed for one or more items (item.quantity > stored_qty). User must correct.');
          // Messages are already displayed, and buttons disabled.
          return;
        }

        // 6. Special Delivery Redirect OR Final Stock Check
        if (sessionStorage.getItem("location") === "Delivery") {
          console.log('[Cart Manager] Location is Delivery, proceeding directly to checkout after initial checks.');
          sessionStorage.setItem(CHECKOUT_RETURN_MARKER, 'Y');
          window.location.href = "/checkout";
          return;
        }

        // If NOT Delivery, then proceed to the final stock check
        console.log('[Cart Manager] All initial validations passed, proceeding to final stock check for non-delivery order.');

        // Filter out snacks_and_drinks AND Pfand items - they don't need inventory validation
        const nonSnacksItems = cart.items.filter(function (item) {
          return item.properties.snacks_and_drinks !== "Y" && item.variant_id !== window.pfandConfig?.variantId;
        });

        console.log('[Cart Manager] Stock check - total items:', cart.items.length, 'non-snacks items:', nonSnacksItems.length);

        // If cart contains only snacks items, skip stock check and proceed to checkout
        if (nonSnacksItems.length === 0) {
          console.log('[Cart Manager] Cart contains only snacks and drinks, skipping stock check, proceeding to checkout');
          sessionStorage.setItem(CHECKOUT_RETURN_MARKER, 'Y');
          window.location.href = "/checkout";
          return;
        }

        // Check inventory only for non-snacks items
        $.ajax({
          type: "POST",
          url: "https://dev.sushi.catering/api/checkCartProductsQty", // URL from new code
          async: false,
          cache: false,
          data: {
            items: JSON.stringify(nonSnacksItems) // Send only non-snacks items for validation
          },
          dataType: "json",
          success: function (productsResponse) {
            let allProductsAvailable = true;
            let firstStockErrorElement = null;

            for (let i = 0; i < productsResponse.length; i++) {
              const productStatus = productsResponse[i];
              const currentCartItem = nonSnacksItems[i]; // Use filtered array for comparison

              // Ensure currentCartItem exists to prevent errors if arrays misalign
              if (!currentCartItem || currentCartItem.variant_id != productStatus.variant_id) {
                console.warn('[Cart Manager] Mismatch between cart items and stock check response at index', i);
                // Potentially handle this more gracefully, e.g., by trying to find by variant_id
                // For now, following the assumption of aligned arrays from the new code.
                // If variant_id is reliably present in cart.items[i], we could find:
                // const currentCartItem = nonSnacksItems.find(ci => ci.variant_id == productStatus.variant_id);
              }


              const quantityInput = $(`input.quantity__input[data-quantity-variant-id="${productStatus.variant_id}"]`);
              const quantityContainer = quantityInput.closest(".cart-item__quantity");

              if (productStatus.qty === 0) {
                alert(productStatus.name + ' ist Ausverkauft');
                if (quantityContainer.length && !quantityContainer.find("small.soldout").length) {
                  quantityContainer.append('<small class="soldout" style="color:red;">Ausverkauft</small>');
                }
                if (allProductsAvailable && quantityContainer.length) { // Only scroll to the first error
                  firstStockErrorElement = quantityContainer;
                }
                allProductsAvailable = false;
              } else if (currentCartItem && productStatus.qty < currentCartItem.quantity) {
                alert('Für ' + productStatus.name + ' sind nur noch ' + productStatus.qty + ' Artikel übrig');
                if (quantityContainer.length && !quantityContainer.find("small.soldout").length) { // Using "soldout" class as per new code for this message too
                  quantityContainer.append(`<small class="soldout" style="color:red;">${productStatus.qty} Produkte verfügbar</small>`);
                }
                if (allProductsAvailable && quantityContainer.length) { // Only scroll to the first error
                  firstStockErrorElement = quantityContainer;
                }
                allProductsAvailable = false;
              }
            }

            if (firstStockErrorElement) {
              const elementTop = firstStockErrorElement.offset().top;
              window.scrollTo({ top: elementTop - 150, behavior: "smooth" });
            }

            if (!allProductsAvailable) {
              console.log("[Cart Manager] Final stock check failed. Some products unavailable or insufficient stock.");
              return; // Prevent checkout
            }

            console.log('[Cart Manager] All validations (including final stock) passed, proceeding to checkout.');
            sessionStorage.setItem(CHECKOUT_RETURN_MARKER, 'Y');
            window.location.href = "/checkout";
          },
          error: function (xhr, status, error) { // Added xhr, status, error params
            console.error('[Cart Manager] Failed to check product quantities (final check):', error);
            alert('Es gab einen Fehler bei der Überprüfung der Produktverfügbarkeit. Bitte versuchen Sie es erneut.');
          }
        });
      },
      error: function (xhr, status, error) { // Added xhr, status, error params
        console.error('[Cart Manager] Failed to validate cart (initial fetch):', error);
        alert('Es gab einen Fehler bei der Überprüfung Ihres Warenkorbs. Bitte versuchen Sie es erneut.');
      }
    });
  });

  if (window.location.pathname === "/pages/order-menue") {
    // $(".order_qty").find("input").attr("max", 99);
    // $(".qty_portion").hide();
    history.pushState(null, null, window.location.href); // Push current state to history

    window.onpopstate = function (event) {
      sessionStorage.clear();

      $.ajax({
        type: "POST",
        url: window.Shopify.routes.root + "cart/clear.js",
        async: false,
        dataType: "json",
        success: function (response) {
          window.location.href = "/pages/bestellen";
        },
        error: function (xhr, status, error) {
          alert("Cart clear error:");
          console.log("Cart clear error:", error);
        },
      });

    };
  }
  if (window.location.pathname === "/pages/menue") {
    // Select all elements with the class .product_media
    document.querySelectorAll('.product_media').forEach(function (element) {
      // Find the first .pf-main-media element within the current .product_media element
      var pfMainMedia = element.querySelector('.pf-main-media');

      // Get the 'data-href' attribute and append '?page=menue'
      var href = pfMainMedia.getAttribute('data-href') + '?page=menue';

      // Append additional query parameters if they exist in sessionStorage
      if (sessionStorage.getItem("location") != null)
        href += "&location=" + sessionStorage.getItem("location");
      if (sessionStorage.getItem("date") != null)
        href += "&date=" + sessionStorage.getItem("date");
      if (localStorage.getItem("uuid") != null)
        href += "&uuid=" + localStorage.getItem("uuid");
      if (sessionStorage.getItem("no_station") != null)
        href += "&no_station=" + sessionStorage.getItem("no_station");
      if (sessionStorage.getItem("immediate_inventory") != null)
        href += "&immediate_inventory=" + sessionStorage.getItem("immediate_inventory");
      if (sessionStorage.getItem("b_additional_inventory") != null)
        href += "&additional_inventory=" + sessionStorage.getItem("b_additional_inventory");
      if (sessionStorage.getItem("additional_inventory_time") != null)
        href += "&additional_inventory_time=" + sessionStorage.getItem("additional_inventory_time");
      if (sessionStorage.getItem("strYStockOnlyCheck") != null)
        href += "&strYStockOnlyCheck=" + sessionStorage.getItem("strYStockOnlyCheck");

      // Set the updated href back as the 'data-href' attribute
      pfMainMedia.setAttribute('data-href', href);
    });
  }

  if (window.location.pathname.includes('/products/')) {
    const queryParams = getQueryParams();

    // Check if 'location', 'date', and 'uuid' parameters are missing in the URL
    if (!queryParams.has('location') || !queryParams.has('date') || !queryParams.has('uuid')) {
      // Get all elements with the specified class names and hide them
      var quantityElements = document.querySelectorAll('.product-form__quantity');
      var submitElements = document.querySelectorAll('.product-form__submit');
      var buttonElements = document.querySelectorAll('.product-form__buttons');

      // Hide quantity and submit elements
      quantityElements.forEach(function (element) {
        element.style.display = 'none';
      });
      submitElements.forEach(function (element) {
        element.style.display = 'none';
      });

      // Clear session storage to avoid adding different dates against the products to the cart.
      sessionStorage.clear();
      $.ajax({
        type: "POST",
        url: window.Shopify.routes.root + "cart/clear.js",
        async: false,
        dataType: "json",
        success: function (response) {
        },
        error: function (xhr, status, error) {
          console.log("Cart clear error:", error);
        },
      });

      // Update the innerHTML of the button elements
      buttonElements.forEach(function (element) {
        element.innerHTML = '<a class="product-form__submit button button--full-width button--primary" href="/pages/bestellen">Bitte bestellen Sie hier</a>';
      });
    } else {
      // Console log the parameters if they exist
      console.log('Location:', queryParams.get('location'));
      console.log('Date:', queryParams.get('date'));
      console.log('UUID:', queryParams.get('uuid'));
    }
  }

  const qp = getQueryParams();
  if (qp.has('uuid')) {
    localStorage.setItem("uuid", qp.get('uuid'));
  }
  // Respect location/date passed via shared links before any redirect logic runs.
  // Without this, /pages/order-menue links that only include ?location=... lose the
  // location when we bounce the user back to /pages/bestellen to collect missing data.
  if (qp.has('location')) {
    sessionStorage.setItem("location", qp.get('location'));
  }
  if (qp.has('date')) {
    sessionStorage.setItem("date", qp.get('date'));
  }

  // Check if required parameters are missing and redirect accordingly
  if (
    (sessionStorage.getItem("location") == null ||
      sessionStorage.getItem("date") == null ||
      localStorage.getItem("uuid") == null) &&
    (window.location.pathname === "/pages/order-menue" || window.location.pathname === "/cart")
  ) {
    // Preserve any known location when redirecting so links with ?location keep working.
    const redirectParams = new URLSearchParams();
    const redirectLocation =
      qp.get('location') ||
      sessionStorage.getItem("location") ||
      localStorage.getItem("location");

    if (redirectLocation) {
      redirectParams.set("location", redirectLocation);
    }

    const redirectUrl = redirectParams.toString()
      ? `/pages/bestellen?${redirectParams.toString()}`
      : "/pages/bestellen";

    window.location.href = redirectUrl;
  } else if (window.location.pathname === "/pages/datum") {
    const queryParams = getQueryParams();
    if (queryParams.has('location')) {
      sessionStorage.setItem("location", queryParams.get('location'));
    } else if (sessionStorage.getItem("location") == null && localStorage.getItem("location") == null) {
      window.location.href = "/pages/bestellen";
    }

    if (sessionStorage.getItem("date") != null && !queryParams.has('location')) {
      sessionStorage.clear();
      window.location.replace("/pages/bestellen");
    }
  }

});


// Function to format today's date as dd-mm-yyyy for Germany
function getFormattedDate() {
  // Use Intl.DateTimeFormat to get the correct current date in Germany
  const options = {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour12: false
  };

  const formatter = new Intl.DateTimeFormat([], options);
  const parts = formatter.formatToParts(new Date());

  let dateObj = {};
  parts.forEach(({ type, value }) => {
    dateObj[type] = value;
  });

  return `${dateObj.day}-${dateObj.month}-${dateObj.year}`;
}

function updateLocationBar() {
  if (!window.jQuery) return;
  const $ = window.jQuery;
  const strLocation = sessionStorage.getItem("location") || "";
  const strDate = sessionStorage.getItem("date") || "";

  if (strLocation || strDate) {
    // Make sure the location bar exists before trying to update it.
    if ($(".location_bar").length > 0) {
      $(".location_bar").css("display", "block");
      $(".location_bar_text").html(`&nbsp;${strLocation}&nbsp;${strDate}`);
    }
  } else {
    $(".location_bar").remove();
  }
}

if (window.location.pathname === "/pages/order-menue" || window.location.pathname === "/cart" || (window.location.pathname === "/pages/datum" && sessionStorage.getItem("location") == null && localStorage.getItem("location") == null)) {
  // Check if the session storage 'date' exists and is not null
  if (sessionStorage.getItem("date") !== null) {
    const storedDate = sessionStorage.getItem("date");
    const todayDate = getFormattedDate();

    // Compare the stored date with today's date
    const storedDateParts = storedDate.split('-');
    const todayDateParts = todayDate.split('-');

    const storedDateObj = new Date(storedDateParts[2], storedDateParts[1] - 1, storedDateParts[0]);
    const todayDateObj = new Date(todayDateParts[2], todayDateParts[1] - 1, todayDateParts[0]);
    todayDateObj.setHours(0, 0, 0, 0); // Normalize today to midnight

    if (storedDateObj < todayDateObj) {
      // =========================================================================
      // YESTERDAY ITEMS EXCEPTION
      // Allow yesterday's date for immediate inventory orders. This is needed
      // because the "Sofortbestellung Gestern" (Immediate Order Yesterday) button
      // sets the date to yesterday to show products from yesterday's inventory.
      // Without this exception, the date validation would clear the session and
      // redirect away, breaking the yesterday items feature.
      // =========================================================================
      const isImmediateInventory = sessionStorage.getItem("immediate_inventory") === "Y";

      // Calculate yesterday's date for comparison
      const yesterdayDateObj = new Date(todayDateObj);
      yesterdayDateObj.setDate(yesterdayDateObj.getDate() - 1);
      yesterdayDateObj.setHours(0, 0, 0, 0); // Normalize to midnight for accurate comparison

      // Check if the stored date is exactly yesterday (not older than yesterday)
      const isExactlyYesterday = storedDateObj.getTime() === yesterdayDateObj.getTime();

      // Allow yesterday's date ONLY for immediate inventory orders (yesterday items feature)
      // For any date older than yesterday, always clear and redirect
      if (isImmediateInventory && isExactlyYesterday) {
        console.log("[Date Validation] Yesterday date allowed for immediate inventory order (Yesterdays Items feature)");
        // Continue without clearing - the yesterday order is valid
      } else {
        console.warn("Stored date in sessionStorage (" + storedDate + ") is in the past. Clearing session, cart, and redirecting to /pages/bestellen.");
        sessionStorage.clear();
        $.ajax({
          type: "POST",
          url: window.Shopify.routes.root + "cart/clear.js",
          async: false,
          dataType: "json",
          async: false, // Crucial for completing before redirect
          success: function () {
            window.location.href = "/pages/bestellen";
          },
          error: function (xhr, status, error) {
            console.error("Cart clear error during past date handling in global.js:", error);
            window.location.href = "/pages/bestellen"; // Still redirect
          }
        });
      }
      // Use a return or throw to stop further script execution in this context if necessary.
      // For now, the redirect will stop it.
    }
  } else {
    // If 'date' does not exist in sessionStorage, set it to today's date
    sessionStorage.setItem("date", getFormattedDate());
  }
}


if (window.jQuery) {
  let $ = window.jQuery;

  $(document).ready(function () {
    // ============================================================================
    // SESSION TIMEOUT FUNCTIONALITY (5-MINUTE INACTIVITY TIMEOUT)
    // ============================================================================
    // Automatically clears all session data (sessionStorage, cookies, cart) and
    // redirects user to /pages/bestellen after 5 minutes of inactivity.
    // This ensures users don't have stale cart/session data if they leave the
    // browser tab open and come back later. Timer resets on any user interaction
    // (click, scroll, keypress, mouse movement, touch).
    // ============================================================================
    (function initSessionTimeout() {
      // Skip timeout initialization if already on /pages/bestellen
      // No point redirecting to the same page we're already on
      if (window.location.pathname === '/pages/bestellen') {
        console.log('[Session Timeout] On bestellen page, skipping timeout initialization');
        return;
      }

      // -------------------------------------------------------------------------
      // CONFIGURATION: 2 minutes = 120,000 milliseconds
      // Change this value to adjust the timeout duration
      // -------------------------------------------------------------------------
      var SESSION_TIMEOUT_MS = 2 * 60 * 1000; // 120000ms = 2 minutes

      // Stores the timeout ID so we can clear/reset it on user activity
      var sessionTimeoutId = null;

      // -------------------------------------------------------------------------
      // HELPER: Clear all browser cookies
      // Loops through all cookies and sets them to expire in the past,
      // which effectively deletes them from the browser
      // -------------------------------------------------------------------------
      function clearAllCookies() {
        try {
          var cookies = document.cookie.split(";");
          for (var i = 0; i < cookies.length; i++) {
            var cookie = cookies[i];
            var eqPos = cookie.indexOf("=");
            // Extract cookie name (before the = sign)
            var name = eqPos > -1 ? cookie.substr(0, eqPos).trim() : cookie.trim();
            // Set cookie to expire in the past (1970) to delete it
            // path=/ ensures we delete cookies from all paths
            document.cookie = name + "=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/";
          }
          console.log('[Session Timeout] All cookies cleared');
        } catch (e) {
          console.error('[Session Timeout] Error clearing cookies:', e);
        }
      }

      // -------------------------------------------------------------------------
      // MAIN HANDLER: Executes when the 5-minute timeout is reached
      // 1. Clears sessionStorage (location, date, inventory flags, etc.)
      // 2. Clears all cookies
      // 3. Clears the Shopify cart via AJAX
      // 4. Redirects to /pages/bestellen
      // -------------------------------------------------------------------------
      function handleSessionTimeout() {
        console.log('[Session Timeout] 5-minute inactivity timeout reached');
        console.log('[Session Timeout] Clearing all session data and redirecting...');

        // Step 1: Clear sessionStorage
        // This removes location, date, immediate_inventory, no_station, etc.
        try {
          sessionStorage.clear();
          console.log('[Session Timeout] SessionStorage cleared');
        } catch (e) {
          console.error('[Session Timeout] Error clearing sessionStorage:', e);
        }

        // Step 2: Clear all cookies
        clearAllCookies();

        // Step 3: Clear the Shopify cart and then redirect
        // Using async:false to ensure cart is cleared before redirect
        if (window.Shopify && window.Shopify.routes && window.Shopify.routes.root) {
          $.ajax({
            type: "POST",
            url: window.Shopify.routes.root + "cart/clear.js",
            async: false, // MUST be sync to complete before redirect
            dataType: "json",
            success: function() {
              console.log('[Session Timeout] Cart cleared successfully');
            },
            error: function(xhr, status, error) {
              console.error('[Session Timeout] Error clearing cart:', error);
            },
            complete: function() {
              // Step 4: Redirect to bestellen page (always happens, even on error)
              console.log('[Session Timeout] Redirecting to /pages/bestellen');
              window.location.href = "/pages/bestellen";
            }
          });
        } else {
          // Fallback: redirect without clearing cart if Shopify routes unavailable
          console.warn('[Session Timeout] Shopify routes not available, redirecting anyway');
          window.location.href = "/pages/bestellen";
        }
      }

      // -------------------------------------------------------------------------
      // TIMER RESET: Called on initialization and on every user interaction
      // Clears any existing timeout and starts a fresh 5-minute countdown
      // -------------------------------------------------------------------------
      function resetSessionTimeout() {
        // Clear any existing timeout to prevent multiple timers
        if (sessionTimeoutId) {
          clearTimeout(sessionTimeoutId);
        }
        // Start a fresh 5-minute countdown
        sessionTimeoutId = setTimeout(handleSessionTimeout, SESSION_TIMEOUT_MS);
      }

      // -------------------------------------------------------------------------
      // INITIALIZATION: Start the timeout and attach activity listeners
      // -------------------------------------------------------------------------

      // Start the initial 5-minute countdown
      resetSessionTimeout();

      // List of DOM events that indicate user activity
      // Any of these will reset the 5-minute timer
      var activityEvents = [
        'click',      // Mouse clicks
        'scroll',     // Page scrolling
        'keypress',   // Keyboard input
        'keydown',    // Keyboard key down (catches more keys than keypress)
        'mousemove',  // Mouse movement
        'touchstart', // Touch screen tap start
        'touchmove'   // Touch screen swipe/scroll
      ];

      // Attach event listeners for all activity events
      // Using { passive: true } for better scroll/touch performance
      activityEvents.forEach(function(eventName) {
        document.addEventListener(eventName, resetSessionTimeout, { passive: true });
      });

      console.log('[Session Timeout] Initialized - will timeout after ' + (SESSION_TIMEOUT_MS / 1000) + ' seconds of inactivity');
    })();
    // ============================================================================
    // END OF SESSION TIMEOUT FUNCTIONALITY
    // ============================================================================

    //when the "bestellen" site loads, it should check whether their is already a location and date in the session -&gt; if yes it should redirect to the meunue page directly otherwise just display the normal page
    const currentPathForParams = window.location.pathname;
    const pagesForParams = ["/pages/bestellen", "/pages/datum", "/pages/order-menue"];

    if (pagesForParams.includes(currentPathForParams)) {
      const queryParams = getQueryParams();

      // Always overwrite session storage from URL params if they exist.
      if (queryParams.has('location')) sessionStorage.setItem("location", queryParams.get('location'));
      if (queryParams.has('date')) sessionStorage.setItem("date", queryParams.get('date'));
      if (queryParams.has('immediate_inventory')) sessionStorage.setItem("immediate_inventory", queryParams.get('immediate_inventory'));
      if (queryParams.has('no_station')) sessionStorage.setItem("no_station", queryParams.get('no_station'));
      if (queryParams.has('additional_inventory')) sessionStorage.setItem("b_additional_inventory", queryParams.get('additional_inventory'));
      if (queryParams.has('additional_inventory_time')) sessionStorage.setItem("additional_inventory_time", queryParams.get('additional_inventory_time'));
      if (queryParams.has('strYStockOnlyCheck')) sessionStorage.setItem("strYStockOnlyCheck", queryParams.get('strYStockOnlyCheck'));

      // Page-specific logic after parameters have been processed
      if (currentPathForParams === "/pages/bestellen") {
        if (queryParams.has('location')) {
          window.location.href = `/pages/datum?location=${encodeURIComponent(queryParams.get('location'))}`;
        } else {
          if (sessionStorage.getItem("location") === "Delivery") sessionStorage.clear();

          if (sessionStorage.getItem("location") == null && sessionStorage.getItem("date") == null && localStorage.getItem("location") == null) {
            // Stay on page
          } else if (sessionStorage.getItem("location") == null && localStorage.getItem("location") != null) {
            $("#stationDropdown").html(localStorage.getItem("location"));
            $("#stationDropdown").attr('style', "background-color:black;");
            $('#next_button').css('display', 'inline-block');
          } else if (sessionStorage.getItem("date") == null) {
            window.location.replace("/pages/datum");
          } else {
            window.location.href = "/pages/order-menue?location=" + sessionStorage.getItem("location") + "&date=" + sessionStorage.getItem("date") + "&immediate_inventory=" + sessionStorage.getItem("immediate_inventory") + "&no_station=" + sessionStorage.getItem("no_station") + "&additional_inventory=" + sessionStorage.getItem("b_additional_inventory") + "&additional_inventory_time=" + sessionStorage.getItem("additional_inventory_time") + "&strYStockOnlyCheck=" + (sessionStorage.getItem("strYStockOnlyCheck") || "N") + "&uuid=" + localStorage.getItem("uuid");
          }
        }
      } else if (currentPathForParams === "/pages/datum") {
        const queryParams = getQueryParams();

        // Only redirect if a date is present AND a new location wasn't just passed in the URL.
        if (!queryParams.has('location') && sessionStorage.getItem("date") != null) {
          sessionStorage.clear();
          window.location.replace("/pages/bestellen");
          return; // Stop further execution
        }

        if (sessionStorage.getItem("location") == null && localStorage.getItem("location") == null) {
          window.location.href = "/pages/bestellen";
        }
      } else if (currentPathForParams === "/pages/order-menue") {
        // Special handling for Delivery location
        if (sessionStorage.getItem("location") == "Delivery") {
          $(".order_qty").find("input").attr("max", 99);
          $(".qty_portion").hide();
        }

        if (sessionStorage.getItem("location") == null || sessionStorage.getItem("date") == null || localStorage.getItem("uuid") == null) {
          window.location.href = "/pages/bestellen";
        }
      }
    } else if (window.location.pathname === "/cart") {
      window.min_order_limit = 0;
      // if(window.location.pathname === "/cart"){
      if (sessionStorage.getItem("location") == "Delivery") {
        $(".incorrent_item_agree_cb_portion").hide();

        $.ajax({
          type: "GET",
          url: "https://dev.sushi.catering/getLocations/Delivery",
          async: false,
          cache: false,
          // data: {
          //     items: JSON.stringify(response.items)
          // },
          dataType: "json",
          success: function (data) {
            min_order_limit = data.min_order_limit;
            //window.location.href = "/checkout";
          },
          error: function () {
            console.log('Cart Check Delivery Inventory api error');
          }
        });
      }
      // }

      $.ajax({
        type: "GET",
        url: window.Shopify.routes.root + "cart.js",
        dataType: "json",
        success: function (response) {
          removePastDateProducts(response);

          let items = response.items;

          // Check if cart contains any non-snacks items
          const hasNonSnacksItems = items.some(function (item) {
            return item.properties.snacks_and_drinks !== "Y";
          });

          // Only check time expiration if cart has non-snacks items
          if (hasNonSnacksItems) {
            $.ajax({
              type: "POST",
              url: "https://dev.sushi.catering/api/checkOrderInventory",
              async: false,
              cache: false,
              data: {
                items: JSON.stringify(response.items)
              },
              dataType: "json",
              success: function (response) {
                //window.location.href = "/checkout";
                if (response.sameday_preorder_time_expired == 1) {
                  alert('Du kannst nur noch eine Sofortbestellung tätigen.');
                  sessionStorage.clear();
                  $(".location_bar").remove();

                  $.ajax({
                    type: "POST",
                    url: window.Shopify.routes.root + "cart/clear.js",
                    async: false,
                    dataType: "json",
                    success: function (response) {
                      window.location.href = "/pages/bestellen";
                    },
                    error: function (xhr, status, error) {
                      alert("Cart clear error:");
                      console.log("Cart clear error:", error);
                    },
                  });
                }
              },
              error: function () {
                console.log('Cart Check order Inventory api error');
              }
            });
          } else {
            console.log('[Cart Manager] Cart contains only snacks and drinks on /cart page, skipping time expiration check');
          }
        },
        error: function () {
        }
      });
      function removeProductFromCart(productId) {
        return new Promise((resolve, reject) => {
          var changeUrl = '/cart/change.js';
          var payload = {
            id: productId,
            quantity: 0 // Setting quantity to 0 will remove the item
          };

          $.ajax({
            url: changeUrl,
            type: 'POST',
            dataType: 'json',
            data: payload,
            success: function (response) {
              console.log('Product removed from cart:', productId);
              resolve(productId); // Resolve the promise when the product is successfully removed
            },
            error: function (xhr, status, error) {
              console.error('Failed to remove product from cart:', productId, status, error);
              reject(error); // Reject the promise if an error occurs
            }
          });
        });
      }

      function removePastDateProducts(response) {
        var removalPromises = [];
        var bReload = false;

        // =========================================================================
        // YESTERDAY ITEMS EXCEPTION
        // Check if this is an immediate inventory order (yesterday items feature).
        // If so, we need to allow products with yesterday's date to remain in cart.
        // =========================================================================
        var isImmediateInventory = sessionStorage.getItem("immediate_inventory") === "Y";

        $.each(response.items, function (index, product) {
          var dateParts = product.properties.date.split('-');
          var productDate = new Date(dateParts[2], dateParts[1] - 1, dateParts[0]);
          productDate.setHours(0, 0, 0, 0); // Normalize to midnight for accurate comparison

          var currentDate = new Date(new Date().toLocaleString("en-US", { timeZone: "Europe/Berlin" }));
          currentDate.setHours(0, 0, 0, 0); // Remove time portion for comparison

          // Calculate yesterday's date
          var yesterdayDate = new Date(currentDate);
          yesterdayDate.setDate(yesterdayDate.getDate() - 1);
          yesterdayDate.setHours(0, 0, 0, 0);

          // Check if product date is exactly yesterday
          var isExactlyYesterday = productDate.getTime() === yesterdayDate.getTime();

          if (productDate < currentDate) {
            // Allow yesterday's products for immediate inventory orders (yesterday items feature)
            // Only remove products older than yesterday, or yesterday's products if NOT immediate inventory
            if (isImmediateInventory && isExactlyYesterday) {
              console.log('[Cart Manager] Keeping yesterday product for immediate inventory order:', product.title);
              // Don't remove - this is a valid yesterday items order
            } else {
              bReload = true;
              removalPromises.push(removeProductFromCart(product.id));
            }
          }
        });

        Promise.all(removalPromises).then(function (results) {
          console.log('All removable items have been removed:', results);
          if (bReload === true) { // If any past date products were found and removed
            console.warn("Past date products found in cart and removed. Clearing session, cart, and redirecting to /pages/bestellen.");
            sessionStorage.clear();
            // Cart items were already cleared by individual removeProductFromCart calls.
            // To be absolutely sure the cart is empty if bReload is true:
            $.ajax({
              type: "POST",
              url: window.Shopify.routes.root + "cart/clear.js",
              async: false,
              dataType: "json",
              async: false, // Crucial for completing before redirect
              success: function () {
                window.location.href = "/pages/bestellen";
              },
              error: function (xhr, status, error) {
                console.error("Cart clear error after removing past date products:", error);
                window.location.href = "/pages/bestellen"; // Still redirect
              }
            });
          }
        }).catch(function (error) {
          console.error('An error occurred while removing items:', error);
        });
      }
    }

    // Reconcile a completed checkout first, then render the location bar from the remaining session state.
    reconcileCheckoutReturnState(updateLocationBar);
  });

  // Delegated event handlers can remain outside the ready block
  $(document).on("click", ".location_bar_closer", function () {
    sessionStorage.clear();
    $(".location_bar").remove();

    $.ajax({
      type: "POST",
      url: window.Shopify.routes.root + "cart/clear.js",
      async: false,
      dataType: "json",
      success: function (response) {
        window.location.href = "/";
      },
      error: function (xhr, status, error) {
        alert("Cart clear error:");
        console.log("Cart clear error:", error);
      },
    });
  });

  $(document).on("click", ".station", function (e) {
    e.preventDefault();

    var href = $(this).attr("href");
    var strLocation = $(this).html();
    var strDate = sessionStorage.getItem("date") != null ? sessionStorage.getItem("date") : "";

    $("div.shopify-section.shopify-section-group-header-group")
      .not(".section-header")
      .find("p")
      .html(" " + strLocation + " " + strDate);

    if (strLocation != localStorage.getItem("location") && confirm("Möchten Sie diesen Standort für die zukünftige Verwendung speichern?") == true) {
      localStorage.setItem("location", strLocation);
      sessionStorage.setItem("location", strLocation);
    } else {
      sessionStorage.setItem("location", strLocation);
    }

    location.replace(href + "?location=" + strLocation);
  });

  $(document).on("click", "#next_button", function (e) {
    e.preventDefault();
    var href = $(this).attr("href");
    // Ensure precedence: URL > sessionStorage > localStorage (bestellen page only)
    var qpForNext = getQueryParams();
    var selectedLocation = (window.location.pathname === "/pages/bestellen" && qpForNext.has("location"))
      ? qpForNext.get("location")
      : (sessionStorage.getItem("location") || localStorage.getItem("location") || "");
    if (selectedLocation) {
      location.replace(href + "?location=" + selectedLocation);
    } else {
      location.replace(href);
    }
  });

  $(document).on("click", "#home_delivery_btn", function (e) {
    e.preventDefault();
    var href = $(this).attr("href");
    location.replace(href);
  });

  function comparePrices(minOrderLimit, currentTotal) {
    // Remove currency symbols and whitespace, replace comma with dot
    const minOrder = parseFloat(minOrderLimit.replace(',', '.'));
    const total = parseFloat(currentTotal.match(/\d+,\d+/)[0].replace(',', '.'));

    console.log('Minimum order:', minOrder);
    console.log('Current total:', total);

    if (minOrder > 0 && total < minOrder)
      return true;
    else
      return false;
  }
}

function getFocusableElements(container) {
  return Array.from(
    container.querySelectorAll(
      "summary, a[href], button:enabled, [tabindex]:not([tabindex^='-']), [draggable], area, input:not([type=hidden]):enabled, select:enabled, textarea:enabled, object, iframe"
    )
  );
}

document.querySelectorAll('[id^="Details-"] summary').forEach((summary) => {
  summary.setAttribute("role", "button");
  summary.setAttribute(
    "aria-expanded",
    summary.parentNode.hasAttribute("open")
  );

  if (summary.nextElementSibling.getAttribute("id")) {
    summary.setAttribute("aria-controls", summary.nextElementSibling.id);
  }

  summary.addEventListener("click", (event) => {
    event.currentTarget.setAttribute(
      "aria-expanded",
      !event.currentTarget.closest("details").hasAttribute("open")
    );
  });

  if (summary.closest("header-drawer, menu-drawer")) return;
  summary.parentElement.addEventListener("keyup", onKeyUpEscape);
});

const trapFocusHandlers = {};

function trapFocus(container, elementToFocus = container) {
  var elements = getFocusableElements(container);
  var first = elements[0];
  var last = elements[elements.length - 1];

  removeTrapFocus();

  trapFocusHandlers.focusin = (event) => {
    if (
      event.target !== container &&
      event.target !== last &&
      event.target !== first
    )
      return;

    document.addEventListener("keydown", trapFocusHandlers.keydown);
  };

  trapFocusHandlers.focusout = function () {
    document.removeEventListener("keydown", trapFocusHandlers.keydown);
  };

  trapFocusHandlers.keydown = function (event) {
    if (event.code.toUpperCase() !== "TAB") return; // If not TAB key
    // On the last focusable element and tab forward, focus the first element.
    if (event.target === last && !event.shiftKey) {
      event.preventDefault();
      first.focus();
    }

    //  On the first focusable element and tab backward, focus the last element.
    if (
      (event.target === container || event.target === first) &&
      event.shiftKey
    ) {
      event.preventDefault();
      last.focus();
    }
  };

  document.addEventListener("focusout", trapFocusHandlers.focusout);
  document.addEventListener("focusin", trapFocusHandlers.focusin);

  elementToFocus.focus();

  if (
    elementToFocus.tagName === "INPUT" &&
    ["search", "text", "email", "url"].includes(elementToFocus.type) &&
    elementToFocus.value
  ) {
    elementToFocus.setSelectionRange(0, elementToFocus.value.length);
  }
}

// Here run the querySelector to figure out if the browser supports :focus-visible or not and run code based on it.
try {
  document.querySelector(":focus-visible");
} catch (e) {
  focusVisiblePolyfill();
}

function focusVisiblePolyfill() {
  const navKeys = [
    "ARROWUP",
    "ARROWDOWN",
    "ARROWLEFT",
    "ARROWRIGHT",
    "TAB",
    "ENTER",
    "SPACE",
    "ESCAPE",
    "HOME",
    "END",
    "PAGEUP",
    "PAGEDOWN",
  ];
  let currentFocusedElement = null;
  let mouseClick = null;

  window.addEventListener("keydown", (event) => {
    if (navKeys.includes(event.code.toUpperCase())) {
      mouseClick = false;
    }
  });

  window.addEventListener("mousedown", (event) => {
    mouseClick = true;
  });

  window.addEventListener(
    "focus",
    () => {
      if (currentFocusedElement)
        currentFocusedElement.classList.remove("focused");

      if (mouseClick) return;

      currentFocusedElement = document.activeElement;
      currentFocusedElement.classList.add("focused");
    },
    true
  );
}

function pauseAllMedia() {
  document.querySelectorAll(".js-youtube").forEach((video) => {
    video.contentWindow.postMessage(
      '{"event":"command","func":"' + "pauseVideo" + '","args":""}',
      "*"
    );
  });
  document.querySelectorAll(".js-vimeo").forEach((video) => {
    video.contentWindow.postMessage('{"method":"pause"}', "*");
  });
  document.querySelectorAll("video").forEach((video) => video.pause());
  document.querySelectorAll("product-model").forEach((model) => {
    if (model.modelViewerUI) model.modelViewerUI.pause();
  });
}

function removeTrapFocus(elementToFocus = null) {
  document.removeEventListener("focusin", trapFocusHandlers.focusin);
  document.removeEventListener("focusout", trapFocusHandlers.focusout);
  document.removeEventListener("keydown", trapFocusHandlers.keydown);

  if (elementToFocus) elementToFocus.focus();
}

function onKeyUpEscape(event) {
  if (event.code.toUpperCase() !== "ESCAPE") return;

  const openDetailsElement = event.target.closest("details[open]");
  if (!openDetailsElement) return;

  const summaryElement = openDetailsElement.querySelector("summary");
  openDetailsElement.removeAttribute("open");
  summaryElement.setAttribute("aria-expanded", false);
  summaryElement.focus();
}

class QuantityInput extends HTMLElement {
  constructor() {
    super();
    this.input = this.querySelector("input");
    this.changeEvent = new Event("change", { bubbles: true });
    this.input.addEventListener("change", this.onInputChange.bind(this));
    this.querySelectorAll("button").forEach((button) =>
      button.addEventListener("click", this.onButtonClick.bind(this))
    );
  }

  quantityUpdateUnsubscriber = undefined;

  connectedCallback() {
    this.validateQtyRules();
    this.quantityUpdateUnsubscriber = subscribe(
      PUB_SUB_EVENTS.quantityUpdate,
      this.validateQtyRules.bind(this)
    );
  }

  disconnectedCallback() {
    if (this.quantityUpdateUnsubscriber) {
      this.quantityUpdateUnsubscriber();
    }
  }

  onInputChange(event) {
    this.validateQtyRules();
  }

  onButtonClick(event) {
    event.preventDefault();
    const previousValue = this.input.value;

    event.target.name === "plus" ? this.input.stepUp() : this.input.stepDown();
    if (previousValue !== this.input.value)
      this.input.dispatchEvent(this.changeEvent);
  }

  validateQtyRules() {
    const value = parseInt(this.input.value);
    if (this.input.min) {
      const min = parseInt(this.input.min);
      const buttonMinus = this.querySelector(".quantity__button[name='minus']");
      buttonMinus.classList.toggle("disabled", value <= min);
    }
    if (this.input.max) {
      const max = parseInt(this.input.max);
      const buttonPlus = this.querySelector(".quantity__button[name='plus']");
      buttonPlus.classList.toggle("disabled", value >= max);
    }
  }
}

customElements.define("quantity-input", QuantityInput);

function debounce(fn, wait) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), wait);
  };
}

function throttle(fn, delay) {
  let lastCall = 0;
  return function (...args) {
    const now = new Date().getTime();
    if (now - lastCall < delay) {
      return;
    }
    lastCall = now;
    return fn(...args);
  };
}

function fetchConfig(type = "json") {
  return {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: `application/${type}`,
    },
  };
}

/*
 * Shopify Common JS
 *
 */
if (typeof window.Shopify == "undefined") {
  window.Shopify = {};
}

Shopify.bind = function (fn, scope) {
  return function () {
    return fn.apply(scope, arguments);
  };
};

Shopify.setSelectorByValue = function (selector, value) {
  for (var i = 0, count = selector.options.length; i < count; i++) {
    var option = selector.options[i];
    if (value == option.value || value == option.innerHTML) {
      selector.selectedIndex = i;
      return i;
    }
  }
};

Shopify.addListener = function (target, eventName, callback) {
  target.addEventListener
    ? target.addEventListener(eventName, callback, false)
    : target.attachEvent("on" + eventName, callback);
};

Shopify.postLink = function (path, options) {
  options = options || {};
  var method = options["method"] || "post";
  var params = options["parameters"] || {};

  var form = document.createElement("form");
  form.setAttribute("method", method);
  form.setAttribute("action", path);

  for (var key in params) {
    var hiddenField = document.createElement("input");
    hiddenField.setAttribute("type", "hidden");
    hiddenField.setAttribute("name", key);
    hiddenField.setAttribute("value", params[key]);
    form.appendChild(hiddenField);
  }
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
};

Shopify.CountryProvinceSelector = function (
  country_domid,
  province_domid,
  options
) {
  this.countryEl = document.getElementById(country_domid);
  this.provinceEl = document.getElementById(province_domid);
  this.provinceContainer = document.getElementById(
    options["hideElement"] || province_domid
  );

  Shopify.addListener(
    this.countryEl,
    "change",
    Shopify.bind(this.countryHandler, this)
  );

  this.initCountry();
  this.initProvince();
};

Shopify.CountryProvinceSelector.prototype = {
  initCountry: function () {
    var value = this.countryEl.getAttribute("data-default");
    Shopify.setSelectorByValue(this.countryEl, value);
    this.countryHandler();
  },

  initProvince: function () {
    var value = this.provinceEl.getAttribute("data-default");
    if (value && this.provinceEl.options.length > 0) {
      Shopify.setSelectorByValue(this.provinceEl, value);
    }
  },

  countryHandler: function (e) {
    var opt = this.countryEl.options[this.countryEl.selectedIndex];
    var raw = opt.getAttribute("data-provinces");
    var provinces = JSON.parse(raw);

    this.clearOptions(this.provinceEl);
    if (provinces && provinces.length == 0) {
      this.provinceContainer.style.display = "none";
    } else {
      for (var i = 0; i < provinces.length; i++) {
        var opt = document.createElement("option");
        opt.value = provinces[i][0];
        opt.innerHTML = provinces[i][1];
        this.provinceEl.appendChild(opt);
      }

      this.provinceContainer.style.display = "";
    }
  },

  clearOptions: function (selector) {
    while (selector.firstChild) {
      selector.removeChild(selector.firstChild);
    }
  },

  setOptions: function (selector, values) {
    for (var i = 0, count = values.length; i < values.length; i++) {
      var opt = document.createElement("option");
      opt.value = values[i];
      opt.innerHTML = values[i];
      selector.appendChild(opt);
    }
  },
};

class MenuDrawer extends HTMLElement {
  constructor() {
    super();

    this.mainDetailsToggle = this.querySelector("details");

    this.addEventListener("keyup", this.onKeyUp.bind(this));
    this.addEventListener("focusout", this.onFocusOut.bind(this));
    this.bindEvents();
  }

  bindEvents() {
    this.querySelectorAll("summary").forEach((summary) =>
      summary.addEventListener("click", this.onSummaryClick.bind(this))
    );
    this.querySelectorAll("button:not(.localization-selector)").forEach(
      (button) =>
        button.addEventListener("click", this.onCloseButtonClick.bind(this))
    );
  }

  onKeyUp(event) {
    if (event.code.toUpperCase() !== "ESCAPE") return;

    const openDetailsElement = event.target.closest("details[open]");
    if (!openDetailsElement) return;

    openDetailsElement === this.mainDetailsToggle
      ? this.closeMenuDrawer(
        event,
        this.mainDetailsToggle.querySelector("summary")
      )
      : this.closeSubmenu(openDetailsElement);
  }

  onSummaryClick(event) {
    const summaryElement = event.currentTarget;
    const detailsElement = summaryElement.parentNode;
    const parentMenuElement = detailsElement.closest(".has-submenu");
    const isOpen = detailsElement.hasAttribute("open");
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    function addTrapFocus() {
      trapFocus(
        summaryElement.nextElementSibling,
        detailsElement.querySelector("button")
      );
      summaryElement.nextElementSibling.removeEventListener(
        "transitionend",
        addTrapFocus
      );
    }

    if (detailsElement === this.mainDetailsToggle) {
      if (isOpen) event.preventDefault();
      isOpen
        ? this.closeMenuDrawer(event, summaryElement)
        : this.openMenuDrawer(summaryElement);

      if (window.matchMedia("(max-width: 990px)")) {
        document.documentElement.style.setProperty(
          "--viewport-height",
          `${window.innerHeight}px`
        );
      }
    } else {
      setTimeout(() => {
        detailsElement.classList.add("menu-opening");
        summaryElement.setAttribute("aria-expanded", true);
        parentMenuElement && parentMenuElement.classList.add("submenu-open");
        !reducedMotion || reducedMotion.matches
          ? addTrapFocus()
          : summaryElement.nextElementSibling.addEventListener(
            "transitionend",
            addTrapFocus
          );
      }, 100);
    }
  }

  openMenuDrawer(summaryElement) {
    setTimeout(() => {
      this.mainDetailsToggle.classList.add("menu-opening");
    });
    summaryElement.setAttribute("aria-expanded", true);
    trapFocus(this.mainDetailsToggle, summaryElement);
    document.body.classList.add(`overflow-hidden-${this.dataset.breakpoint}`);
  }

  closeMenuDrawer(event, elementToFocus = false) {
    if (event === undefined) return;

    this.mainDetailsToggle.classList.remove("menu-opening");
    this.mainDetailsToggle.querySelectorAll("details").forEach((details) => {
      details.removeAttribute("open");
      details.classList.remove("menu-opening");
    });
    this.mainDetailsToggle
      .querySelectorAll(".submenu-open")
      .forEach((submenu) => {
        submenu.classList.remove("submenu-open");
      });
    document.body.classList.remove(
      `overflow-hidden-${this.dataset.breakpoint}`
    );
    removeTrapFocus(elementToFocus);
    this.closeAnimation(this.mainDetailsToggle);

    if (event instanceof KeyboardEvent)
      elementToFocus?.setAttribute("aria-expanded", false);
  }

  onFocusOut() {
    setTimeout(() => {
      if (
        this.mainDetailsToggle.hasAttribute("open") &&
        !this.mainDetailsToggle.contains(document.activeElement)
      )
        this.closeMenuDrawer();
    });
  }

  onCloseButtonClick(event) {
    const detailsElement = event.currentTarget.closest("details");
    this.closeSubmenu(detailsElement);
  }

  closeSubmenu(detailsElement) {
    const parentMenuElement = detailsElement.closest(".submenu-open");
    parentMenuElement && parentMenuElement.classList.remove("submenu-open");
    detailsElement.classList.remove("menu-opening");
    detailsElement
      .querySelector("summary")
      .setAttribute("aria-expanded", false);
    removeTrapFocus(detailsElement.querySelector("summary"));
    this.closeAnimation(detailsElement);
  }

  closeAnimation(detailsElement) {
    let animationStart;

    const handleAnimation = (time) => {
      if (animationStart === undefined) {
        animationStart = time;
      }

      const elapsedTime = time - animationStart;

      if (elapsedTime < 400) {
        window.requestAnimationFrame(handleAnimation);
      } else {
        detailsElement.removeAttribute("open");
        if (detailsElement.closest("details[open]")) {
          trapFocus(
            detailsElement.closest("details[open]"),
            detailsElement.querySelector("summary")
          );
        }
      }
    };

    window.requestAnimationFrame(handleAnimation);
  }
}

customElements.define("menu-drawer", MenuDrawer);

class HeaderDrawer extends MenuDrawer {
  constructor() {
    super();
  }

  openMenuDrawer(summaryElement) {
    this.header = this.header || document.querySelector(".section-header");
    this.borderOffset =
      this.borderOffset ||
        this.closest(".header-wrapper").classList.contains(
          "header-wrapper--border-bottom"
        )
        ? 1
        : 0;
    document.documentElement.style.setProperty(
      "--header-bottom-position",
      `${parseInt(
        this.header.getBoundingClientRect().bottom - this.borderOffset
      )}px`
    );
    this.header.classList.add("menu-open");

    setTimeout(() => {
      this.mainDetailsToggle.classList.add("menu-opening");
    });

    summaryElement.setAttribute("aria-expanded", true);
    window.addEventListener("resize", this.onResize);
    trapFocus(this.mainDetailsToggle, summaryElement);
    document.body.classList.add(`overflow-hidden-${this.dataset.breakpoint}`);
  }

  closeMenuDrawer(event, elementToFocus) {
    if (!elementToFocus) return;
    super.closeMenuDrawer(event, elementToFocus);
    this.header.classList.remove("menu-open");
    window.removeEventListener("resize", this.onResize);
  }

  onResize = () => {
    this.header &&
      document.documentElement.style.setProperty(
        "--header-bottom-position",
        `${parseInt(
          this.header.getBoundingClientRect().bottom - this.borderOffset
        )}px`
      );
    document.documentElement.style.setProperty(
      "--viewport-height",
      `${window.innerHeight}px`
    );
  };
}

customElements.define("header-drawer", HeaderDrawer);

class ModalDialog extends HTMLElement {
  constructor() {
    super();
    this.querySelector('[id^="ModalClose-"]').addEventListener(
      "click",
      this.hide.bind(this, false)
    );
    this.addEventListener("keyup", (event) => {
      if (event.code.toUpperCase() === "ESCAPE") this.hide();
    });
    if (this.classList.contains("media-modal")) {
      this.addEventListener("pointerup", (event) => {
        if (
          event.pointerType === "mouse" &&
          !event.target.closest("deferred-media, product-model")
        )
          this.hide();
      });
    } else {
      this.addEventListener("click", (event) => {
        if (event.target === this) this.hide();
      });
    }
  }

  connectedCallback() {
    if (this.moved) return;
    this.moved = true;
    document.body.appendChild(this);
  }

  show(opener) {
    this.openedBy = opener;
    const popup = this.querySelector(".template-popup");
    document.body.classList.add("overflow-hidden");
    this.setAttribute("open", "");
    if (popup) popup.loadContent();
    trapFocus(this, this.querySelector('[role="dialog"]'));
    window.pauseAllMedia();
  }

  hide() {
    document.body.classList.remove("overflow-hidden");
    document.body.dispatchEvent(new CustomEvent("modalClosed"));
    this.removeAttribute("open");
    removeTrapFocus(this.openedBy);
    window.pauseAllMedia();
  }
}
customElements.define("modal-dialog", ModalDialog);

class ModalOpener extends HTMLElement {
  constructor() {
    super();

    const button = this.querySelector("button");

    if (!button) return;
    button.addEventListener("click", () => {
      const modal = document.querySelector(this.getAttribute("data-modal"));
      if (modal) modal.show(button);
    });
  }
}
customElements.define("modal-opener", ModalOpener);

class DeferredMedia extends HTMLElement {
  constructor() {
    super();
    const poster = this.querySelector('[id^="Deferred-Poster-"]');
    if (!poster) return;
    poster.addEventListener("click", this.loadContent.bind(this));
  }

  loadContent(focus = true) {
    window.pauseAllMedia();
    if (!this.getAttribute("loaded")) {
      const content = document.createElement("div");
      content.appendChild(
        this.querySelector("template").content.firstElementChild.cloneNode(true)
      );

      this.setAttribute("loaded", true);
      const deferredElement = this.appendChild(
        content.querySelector("video, model-viewer, iframe")
      );
      if (focus) deferredElement.focus();
      if (
        deferredElement.nodeName == "VIDEO" &&
        deferredElement.getAttribute("autoplay")
      ) {
        // force autoplay for safari
        deferredElement.play();
      }
    }
  }
}

customElements.define("deferred-media", DeferredMedia);

class SliderComponent extends HTMLElement {
  constructor() {
    super();
    this.slider = this.querySelector('[id^="Slider-"]');
    this.sliderItems = this.querySelectorAll('[id^="Slide-"]');
    this.enableSliderLooping = false;
    this.currentPageElement = this.querySelector(".slider-counter--current");
    this.pageTotalElement = this.querySelector(".slider-counter--total");
    this.prevButton = this.querySelector('button[name="previous"]');
    this.nextButton = this.querySelector('button[name="next"]');

    if (!this.slider || !this.nextButton) return;

    this.initPages();
    const resizeObserver = new ResizeObserver((entries) => this.initPages());
    resizeObserver.observe(this.slider);

    this.slider.addEventListener("scroll", this.update.bind(this));
    this.prevButton.addEventListener("click", this.onButtonClick.bind(this));
    this.nextButton.addEventListener("click", this.onButtonClick.bind(this));
  }

  initPages() {
    this.sliderItemsToShow = Array.from(this.sliderItems).filter(
      (element) => element.clientWidth > 0
    );
    if (this.sliderItemsToShow.length < 2) return;
    this.sliderItemOffset =
      this.sliderItemsToShow[1].offsetLeft -
      this.sliderItemsToShow[0].offsetLeft;
    this.slidesPerPage = Math.floor(
      (this.slider.clientWidth - this.sliderItemsToShow[0].offsetLeft) /
      this.sliderItemOffset
    );
    this.totalPages = this.sliderItemsToShow.length - this.slidesPerPage + 1;
    this.update();
  }

  resetPages() {
    this.sliderItems = this.querySelectorAll('[id^="Slide-"]');
    this.initPages();
  }

  update() {
    // Temporarily prevents unneeded updates resulting from variant changes
    // This should be refactored as part of https://github.com/Shopify/dawn/issues/2057
    if (!this.slider || !this.nextButton) return;

    const previousPage = this.currentPage;
    this.currentPage =
      Math.round(this.slider.scrollLeft / this.sliderItemOffset) + 1;

    if (this.currentPageElement && this.pageTotalElement) {
      this.currentPageElement.textContent = this.currentPage;
      this.pageTotalElement.textContent = this.totalPages;
    }

    if (this.currentPage != previousPage) {
      this.dispatchEvent(
        new CustomEvent("slideChanged", {
          detail: {
            currentPage: this.currentPage,
            currentElement: this.sliderItemsToShow[this.currentPage - 1],
          },
        })
      );
    }

    if (this.enableSliderLooping) return;

    if (
      this.isSlideVisible(this.sliderItemsToShow[0]) &&
      this.slider.scrollLeft === 0
    ) {
      this.prevButton.setAttribute("disabled", "disabled");
    } else {
      this.prevButton.removeAttribute("disabled");
    }

    if (
      this.isSlideVisible(
        this.sliderItemsToShow[this.sliderItemsToShow.length - 1]
      )
    ) {
      this.nextButton.setAttribute("disabled", "disabled");
    } else {
      this.nextButton.removeAttribute("disabled");
    }
  }

  isSlideVisible(element, offset = 0) {
    const lastVisibleSlide =
      this.slider.clientWidth + this.slider.scrollLeft - offset;
    return (
      element.offsetLeft + element.clientWidth <= lastVisibleSlide &&
      element.offsetLeft >= this.slider.scrollLeft
    );
  }

  onButtonClick(event) {
    event.preventDefault();
    const step = event.currentTarget.dataset.step || 1;
    this.slideScrollPosition =
      event.currentTarget.name === "next"
        ? this.slider.scrollLeft + step * this.sliderItemOffset
        : this.slider.scrollLeft - step * this.sliderItemOffset;
    this.setSlidePosition(this.slideScrollPosition);
  }

  setSlidePosition(position) {
    this.slider.scrollTo({
      left: position,
    });
  }
}

customElements.define("slider-component", SliderComponent);

class SlideshowComponent extends SliderComponent {
  constructor() {
    super();
    this.sliderControlWrapper = this.querySelector(".slider-buttons");
    this.enableSliderLooping = true;

    if (!this.sliderControlWrapper) return;

    this.sliderFirstItemNode = this.slider.querySelector(".slideshow__slide");
    if (this.sliderItemsToShow.length > 0) this.currentPage = 1;

    this.announcementBarSlider = this.querySelector(".announcement-bar-slider");
    // Value below should match --duration-announcement-bar CSS value
    this.announcerBarAnimationDelay = this.announcementBarSlider ? 250 : 0;

    this.sliderControlLinksArray = Array.from(
      this.sliderControlWrapper.querySelectorAll(".slider-counter__link")
    );
    this.sliderControlLinksArray.forEach((link) =>
      link.addEventListener("click", this.linkToSlide.bind(this))
    );
    this.slider.addEventListener("scroll", this.setSlideVisibility.bind(this));
    this.setSlideVisibility();

    if (this.announcementBarSlider) {
      this.announcementBarArrowButtonWasClicked = false;

      this.reducedMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
      );
      this.reducedMotion.addEventListener("change", () => {
        if (this.slider.getAttribute("data-autoplay") === "true")
          this.setAutoPlay();
      });

      [this.prevButton, this.nextButton].forEach((button) => {
        button.addEventListener(
          "click",
          () => {
            this.announcementBarArrowButtonWasClicked = true;
          },
          { once: true }
        );
      });
    }

    if (this.slider.getAttribute("data-autoplay") === "true")
      this.setAutoPlay();
  }

  setAutoPlay() {
    this.autoplaySpeed = this.slider.dataset.speed * 1000;
    this.addEventListener("mouseover", this.focusInHandling.bind(this));
    this.addEventListener("mouseleave", this.focusOutHandling.bind(this));
    this.addEventListener("focusin", this.focusInHandling.bind(this));
    this.addEventListener("focusout", this.focusOutHandling.bind(this));

    if (this.querySelector(".slideshow__autoplay")) {
      this.sliderAutoplayButton = this.querySelector(".slideshow__autoplay");
      this.sliderAutoplayButton.addEventListener(
        "click",
        this.autoPlayToggle.bind(this)
      );
      this.autoplayButtonIsSetToPlay = true;
      this.play();
    } else {
      this.reducedMotion.matches || this.announcementBarArrowButtonWasClicked
        ? this.pause()
        : this.play();
    }
  }

  onButtonClick(event) {
    super.onButtonClick(event);
    this.wasClicked = true;

    const isFirstSlide = this.currentPage === 1;
    const isLastSlide = this.currentPage === this.sliderItemsToShow.length;

    if (!isFirstSlide && !isLastSlide) {
      this.applyAnimationToAnnouncementBar(event.currentTarget.name);
      return;
    }

    if (isFirstSlide && event.currentTarget.name === "previous") {
      this.slideScrollPosition =
        this.slider.scrollLeft +
        this.sliderFirstItemNode.clientWidth * this.sliderItemsToShow.length;
    } else if (isLastSlide && event.currentTarget.name === "next") {
      this.slideScrollPosition = 0;
    }

    this.setSlidePosition(this.slideScrollPosition);

    this.applyAnimationToAnnouncementBar(event.currentTarget.name);
  }

  setSlidePosition(position) {
    if (this.setPositionTimeout) clearTimeout(this.setPositionTimeout);
    this.setPositionTimeout = setTimeout(() => {
      this.slider.scrollTo({
        left: position,
      });
    }, this.announcerBarAnimationDelay);
  }

  update() {
    super.update();
    this.sliderControlButtons = this.querySelectorAll(".slider-counter__link");
    this.prevButton.removeAttribute("disabled");

    if (!this.sliderControlButtons.length) return;

    this.sliderControlButtons.forEach((link) => {
      link.classList.remove("slider-counter__link--active");
      link.removeAttribute("aria-current");
    });
    this.sliderControlButtons[this.currentPage - 1].classList.add(
      "slider-counter__link--active"
    );
    this.sliderControlButtons[this.currentPage - 1].setAttribute(
      "aria-current",
      true
    );
  }

  autoPlayToggle() {
    this.togglePlayButtonState(this.autoplayButtonIsSetToPlay);
    this.autoplayButtonIsSetToPlay ? this.pause() : this.play();
    this.autoplayButtonIsSetToPlay = !this.autoplayButtonIsSetToPlay;
  }

  focusOutHandling(event) {
    if (this.sliderAutoplayButton) {
      const focusedOnAutoplayButton =
        event.target === this.sliderAutoplayButton ||
        this.sliderAutoplayButton.contains(event.target);
      if (!this.autoplayButtonIsSetToPlay || focusedOnAutoplayButton) return;
      this.play();
    } else if (
      !this.reducedMotion.matches &&
      !this.announcementBarArrowButtonWasClicked
    ) {
      this.play();
    }
  }

  focusInHandling(event) {
    if (this.sliderAutoplayButton) {
      const focusedOnAutoplayButton =
        event.target === this.sliderAutoplayButton ||
        this.sliderAutoplayButton.contains(event.target);
      if (focusedOnAutoplayButton && this.autoplayButtonIsSetToPlay) {
        this.play();
      } else if (this.autoplayButtonIsSetToPlay) {
        this.pause();
      }
    } else if (this.announcementBarSlider.contains(event.target)) {
      this.pause();
    }
  }

  play() {
    this.slider.setAttribute("aria-live", "off");
    clearInterval(this.autoplay);
    this.autoplay = setInterval(
      this.autoRotateSlides.bind(this),
      this.autoplaySpeed
    );
  }

  pause() {
    this.slider.setAttribute("aria-live", "polite");
    clearInterval(this.autoplay);
  }

  togglePlayButtonState(pauseAutoplay) {
    if (pauseAutoplay) {
      this.sliderAutoplayButton.classList.add("slideshow__autoplay--paused");
      this.sliderAutoplayButton.setAttribute(
        "aria-label",
        window.accessibilityStrings.playSlideshow
      );
    } else {
      this.sliderAutoplayButton.classList.remove("slideshow__autoplay--paused");
      this.sliderAutoplayButton.setAttribute(
        "aria-label",
        window.accessibilityStrings.pauseSlideshow
      );
    }
  }

  autoRotateSlides() {
    const slideScrollPosition =
      this.currentPage === this.sliderItems.length
        ? 0
        : this.slider.scrollLeft + this.sliderItemOffset;

    this.setSlidePosition(slideScrollPosition);
    this.applyAnimationToAnnouncementBar();
  }

  setSlideVisibility(event) {
    this.sliderItemsToShow.forEach((item, index) => {
      const linkElements = item.querySelectorAll("a");
      if (index === this.currentPage - 1) {
        if (linkElements.length)
          linkElements.forEach((button) => {
            button.removeAttribute("tabindex");
          });
        item.setAttribute("aria-hidden", "false");
        item.removeAttribute("tabindex");
      } else {
        if (linkElements.length)
          linkElements.forEach((button) => {
            button.setAttribute("tabindex", "-1");
          });
        item.setAttribute("aria-hidden", "true");
        item.setAttribute("tabindex", "-1");
      }
    });
    this.wasClicked = false;
  }

  applyAnimationToAnnouncementBar(button = "next") {
    if (!this.announcementBarSlider) return;

    const itemsCount = this.sliderItems.length;
    const increment = button === "next" ? 1 : -1;

    const currentIndex = this.currentPage - 1;
    let nextIndex = (currentIndex + increment) % itemsCount;
    nextIndex = nextIndex === -1 ? itemsCount - 1 : nextIndex;

    const nextSlide = this.sliderItems[nextIndex];
    const currentSlide = this.sliderItems[currentIndex];

    const animationClassIn = "announcement-bar-slider--fade-in";
    const animationClassOut = "announcement-bar-slider--fade-out";

    const isFirstSlide = currentIndex === 0;
    const isLastSlide = currentIndex === itemsCount - 1;

    const shouldMoveNext =
      (button === "next" && !isLastSlide) ||
      (button === "previous" && isFirstSlide);
    const direction = shouldMoveNext ? "next" : "previous";

    currentSlide.classList.add(`${animationClassOut}-${direction}`);
    nextSlide.classList.add(`${animationClassIn}-${direction}`);

    setTimeout(() => {
      currentSlide.classList.remove(`${animationClassOut}-${direction}`);
      nextSlide.classList.remove(`${animationClassIn}-${direction}`);
    }, this.announcerBarAnimationDelay * 2);
  }

  linkToSlide(event) {
    event.preventDefault();
    const slideScrollPosition =
      this.slider.scrollLeft +
      this.sliderFirstItemNode.clientWidth *
      (this.sliderControlLinksArray.indexOf(event.currentTarget) +
        1 -
        this.currentPage);
    this.slider.scrollTo({
      left: slideScrollPosition,
    });
  }
}

customElements.define("slideshow-component", SlideshowComponent);

class VariantSelects extends HTMLElement {
  constructor() {
    super();
    this.addEventListener("change", this.onVariantChange);
  }

  onVariantChange() {
    this.updateOptions();
    this.updateMasterId();
    this.toggleAddButton(true, "", false);
    this.updatePickupAvailability();
    this.removeErrorMessage();
    this.updateVariantStatuses();

    if (!this.currentVariant) {
      this.toggleAddButton(true, "", true);
      this.setUnavailable();
    } else {
      this.updateMedia();
      this.updateURL();
      this.updateVariantInput();
      this.renderProductInfo();
      this.updateShareUrl();
    }
  }

  updateOptions() {
    this.options = Array.from(
      this.querySelectorAll("select"),
      (select) => select.value
    );
  }

  updateMasterId() {
    this.currentVariant = this.getVariantData().find((variant) => {
      return !variant.options
        .map((option, index) => {
          return this.options[index] === option;
        })
        .includes(false);
    });
  }

  updateMedia() {
    if (!this.currentVariant) return;
    if (!this.currentVariant.featured_media) return;

    const mediaGalleries = document.querySelectorAll(
      `[id^="MediaGallery-${this.dataset.section}"]`
    );
    mediaGalleries.forEach((mediaGallery) =>
      mediaGallery.setActiveMedia(
        `${this.dataset.section}-${this.currentVariant.featured_media.id}`,
        true
      )
    );

    const modalContent = document.querySelector(
      `#ProductModal-${this.dataset.section} .product-media-modal__content`
    );
    if (!modalContent) return;
    const newMediaModal = modalContent.querySelector(
      `[data-media-id="${this.currentVariant.featured_media.id}"]`
    );
    modalContent.prepend(newMediaModal);
  }

  updateURL() {
    if (!this.currentVariant || this.dataset.updateUrl === "false") return;
    window.history.replaceState(
      {},
      "",
      `${this.dataset.url}?variant=${this.currentVariant.id}`
    );
  }

  updateShareUrl() {
    const shareButton = document.getElementById(
      `Share-${this.dataset.section}`
    );
    if (!shareButton || !shareButton.updateUrl) return;
    shareButton.updateUrl(
      `${window.shopUrl}${this.dataset.url}?variant=${this.currentVariant.id}`
    );
  }

  updateVariantInput() {
    const productForms = document.querySelectorAll(
      `#product-form-${this.dataset.section}, #product-form-installment-${this.dataset.section}`
    );
    productForms.forEach((productForm) => {
      const input = productForm.querySelector('input[name="id"]');
      input.value = this.currentVariant.id;
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  updateVariantStatuses() {
    const selectedOptionOneVariants = this.variantData.filter(
      (variant) => this.querySelector(":checked").value === variant.option1
    );
    const inputWrappers = [...this.querySelectorAll(".product-form__input")];
    inputWrappers.forEach((option, index) => {
      if (index === 0) return;
      const optionInputs = [
        ...option.querySelectorAll('input[type="radio"], option'),
      ];
      const previousOptionSelected =
        inputWrappers[index - 1].querySelector(":checked").value;
      const availableOptionInputsValue = selectedOptionOneVariants
        .filter(
          (variant) =>
            variant.available &&
            variant[`option${index}`] === previousOptionSelected
        )
        .map((variantOption) => variantOption[`option${index + 1}`]);
      this.setInputAvailability(optionInputs, availableOptionInputsValue);
    });
  }

  setInputAvailability(listOfOptions, listOfAvailableOptions) {
    listOfOptions.forEach((input) => {
      if (listOfAvailableOptions.includes(input.getAttribute("value"))) {
        input.innerText = input.getAttribute("value");
      } else {
        input.innerText = window.variantStrings.unavailable_with_option.replace(
          "[value]",
          input.getAttribute("value")
        );
      }
    });
  }

  updatePickupAvailability() {
    const pickUpAvailability = document.querySelector("pickup-availability");
    if (!pickUpAvailability) return;

    if (this.currentVariant && this.currentVariant.available) {
      pickUpAvailability.fetchAvailability(this.currentVariant.id);
    } else {
      pickUpAvailability.removeAttribute("available");
      pickUpAvailability.innerHTML = "";
    }
  }

  removeErrorMessage() {
    const section = this.closest("section");
    if (!section) return;

    const productForm = section.querySelector("product-form");
    if (productForm) productForm.handleErrorMessage();
  }

  renderProductInfo() {
    const requestedVariantId = this.currentVariant.id;
    const sectionId = this.dataset.originalSection
      ? this.dataset.originalSection
      : this.dataset.section;

    fetch(
      `${this.dataset.url}?variant=${requestedVariantId}&section_id=${this.dataset.originalSection
        ? this.dataset.originalSection
        : this.dataset.section
      }`
    )
      .then((response) => response.text())
      .then((responseText) => {
        // prevent unnecessary ui changes from abandoned selections
        if (this.currentVariant.id !== requestedVariantId) return;

        const html = new DOMParser().parseFromString(responseText, "text/html");
        const destination = document.getElementById(
          `price-${this.dataset.section}`
        );
        const source = html.getElementById(
          `price-${this.dataset.originalSection
            ? this.dataset.originalSection
            : this.dataset.section
          }`
        );
        const skuSource = html.getElementById(
          `Sku-${this.dataset.originalSection
            ? this.dataset.originalSection
            : this.dataset.section
          }`
        );
        const skuDestination = document.getElementById(
          `Sku-${this.dataset.section}`
        );
        const inventorySource = html.getElementById(
          `Inventory-${this.dataset.originalSection
            ? this.dataset.originalSection
            : this.dataset.section
          }`
        );
        const inventoryDestination = document.getElementById(
          `Inventory-${this.dataset.section}`
        );

        const volumePricingSource = html.getElementById(
          `Volume-${this.dataset.originalSection
            ? this.dataset.originalSection
            : this.dataset.section
          }`
        );

        const pricePerItemDestination = document.getElementById(
          `Price-Per-Item-${this.dataset.section}`
        );
        const pricePerItemSource = html.getElementById(
          `Price-Per-Item-${this.dataset.originalSection
            ? this.dataset.originalSection
            : this.dataset.section
          }`
        );

        const volumePricingDestination = document.getElementById(
          `Volume-${this.dataset.section}`
        );

        if (source && destination) destination.innerHTML = source.innerHTML;
        if (inventorySource && inventoryDestination)
          inventoryDestination.innerHTML = inventorySource.innerHTML;
        if (skuSource && skuDestination) {
          skuDestination.innerHTML = skuSource.innerHTML;
          skuDestination.classList.toggle(
            "visibility-hidden",
            skuSource.classList.contains("visibility-hidden")
          );
        }

        if (volumePricingSource && volumePricingDestination) {
          volumePricingDestination.innerHTML = volumePricingSource.innerHTML;
        }

        if (pricePerItemSource && pricePerItemDestination) {
          pricePerItemDestination.innerHTML = pricePerItemSource.innerHTML;
          pricePerItemDestination.classList.toggle(
            "visibility-hidden",
            pricePerItemSource.classList.contains("visibility-hidden")
          );
        }

        const price = document.getElementById(`price-${this.dataset.section}`);

        if (price) price.classList.remove("visibility-hidden");

        if (inventoryDestination)
          inventoryDestination.classList.toggle(
            "visibility-hidden",
            inventorySource.innerText === ""
          );

        const addButtonUpdated = html.getElementById(
          `ProductSubmitButton-${sectionId}`
        );
        this.toggleAddButton(
          addButtonUpdated ? addButtonUpdated.hasAttribute("disabled") : true,
          window.variantStrings.soldOut
        );

        publish(PUB_SUB_EVENTS.variantChange, {
          data: {
            sectionId,
            html,
            variant: this.currentVariant,
          },
        });
      });
  }

  toggleAddButton(disable = true, text, modifyClass = true) {
    const productForm = document.getElementById(
      `product-form-${this.dataset.section}`
    );
    if (!productForm) return;
    const addButton = productForm.querySelector('[name="add"]');
    const addButtonText = productForm.querySelector('[name="add"] > span');
    if (!addButton) return;

    if (disable) {
      addButton.setAttribute("disabled", "disabled");
      if (text) addButtonText.textContent = text;
    } else {
      addButton.removeAttribute("disabled");
      addButtonText.textContent = window.variantStrings.addToCart;
    }

    if (!modifyClass) return;
  }

  setUnavailable() {
    const button = document.getElementById(
      `product-form-${this.dataset.section}`
    );
    const addButton = button.querySelector('[name="add"]');
    const addButtonText = button.querySelector('[name="add"] > span');
    const price = document.getElementById(`price-${this.dataset.section}`);
    const inventory = document.getElementById(
      `Inventory-${this.dataset.section}`
    );
    const sku = document.getElementById(`Sku-${this.dataset.section}`);
    const pricePerItem = document.getElementById(
      `Price-Per-Item-${this.dataset.section}`
    );

    if (!addButton) return;
    addButtonText.textContent = window.variantStrings.unavailable;
    if (price) price.classList.add("visibility-hidden");
    if (inventory) inventory.classList.add("visibility-hidden");
    if (sku) sku.classList.add("visibility-hidden");
    if (pricePerItem) pricePerItem.classList.add("visibility-hidden");
  }

  getVariantData() {
    this.variantData =
      this.variantData ||
      JSON.parse(this.querySelector('[type="application/json"]').textContent);
    return this.variantData;
  }
}

customElements.define("variant-selects", VariantSelects);

class VariantRadios extends VariantSelects {
  constructor() {
    super();
  }

  setInputAvailability(listOfOptions, listOfAvailableOptions) {
    listOfOptions.forEach((input) => {
      if (listOfAvailableOptions.includes(input.getAttribute("value"))) {
        input.classList.remove("disabled");
      } else {
        input.classList.add("disabled");
      }
    });
  }

  updateOptions() {
    const fieldsets = Array.from(this.querySelectorAll("fieldset"));
    this.options = fieldsets.map((fieldset) => {
      return Array.from(fieldset.querySelectorAll("input")).find(
        (radio) => radio.checked
      ).value;
    });
  }
}

customElements.define("variant-radios", VariantRadios);

class ProductRecommendations extends HTMLElement {
  constructor() {
    super();
  }

  connectedCallback() {
    const handleIntersection = (entries, observer) => {
      if (!entries[0].isIntersecting) return;
      observer.unobserve(this);

      fetch(this.dataset.url)
        .then((response) => response.text())
        .then((text) => {
          const html = document.createElement("div");
          html.innerHTML = text;
          const recommendations = html.querySelector("product-recommendations");

          if (recommendations && recommendations.innerHTML.trim().length) {
            this.innerHTML = recommendations.innerHTML;
          }

          if (
            !this.querySelector("slideshow-component") &&
            this.classList.contains("complementary-products")
          ) {
            this.remove();
          }

          if (html.querySelector(".grid__item")) {
            this.classList.add("product-recommendations--loaded");
          }
        })
        .catch((e) => {
          console.error(e);
        });
    };

    new IntersectionObserver(handleIntersection.bind(this), {
      rootMargin: "0px 0px 400px 0px",
    }).observe(this);
  }
}

customElements.define("product-recommendations", ProductRecommendations);

/* ═══════════════════════════════════════════════════════════════════════════════
   PFAND MANAGER (Bottle Deposit Auto-Add)
   ═══════════════════════════════════════════════════════════════════════════════
   German law requires a 0.25 EUR deposit ("Pfand") per drink bottle.
   This IIFE automatically keeps a "Pfand" line item in the cart whose quantity
   always matches the total number of drink bottles (items with _pfand_eligible=Y).

   How it works:
   1. On every cart change (add/remove/update), syncPfand() fetches the cart
   2. It counts all drink bottle quantities and compares to the existing Pfand item
   3. It adds, updates, or removes the Pfand line item to stay in sync
   4. After modifying Pfand, it publishes a cartUpdate event so the cart UI refreshes

   Re-entrancy protection:
   - pfandUpdateInProgress flag prevents syncPfand from running while already updating
   - The PUB_SUB_EVENTS.cartUpdate subscriber ignores events with source='pfand-manager'
   - The fetch interceptor ignores calls made while pfandUpdateInProgress is true
   ═══════════════════════════════════════════════════════════════════════════════ */
(function PfandManager() {
  // Only run if Pfand product is configured and available in the store
  if (!window.pfandConfig || !window.pfandConfig.enabled || !window.pfandConfig.variantId) {
    console.log('[Pfand Manager] Pfand not configured or unavailable, skipping initialization');
    return;
  }

  var PFAND_VARIANT_ID = window.pfandConfig.variantId;

  // Re-entrancy guard: prevents infinite loops when our own cart changes
  // trigger the interceptors/subscribers again
  var pfandUpdateInProgress = false;

  // Debounce timer reference - ensures we only run syncPfand once even if
  // multiple cart events fire in quick succession (e.g., rapid qty changes)
  var syncTimeout = null;

  /**
   * Schedules a syncPfand call after a short delay (300ms).
   * If called multiple times within 300ms, only the last call executes.
   * This prevents hammering the cart API when multiple events fire together.
   */
  function debouncedSync() {
    if (syncTimeout) clearTimeout(syncTimeout);
    syncTimeout = setTimeout(syncPfand, 300);
  }

  /**
   * Core sync function - fetches the current cart, counts drink bottles,
   * and ensures the Pfand line item quantity matches.
   *
   * Flow:
   *   1. GET /cart.js to read current cart contents
   *   2. Loop through items: count drink bottles (_pfand_eligible=Y) and find Pfand item
   *   3. Compare totalDrinkQty vs Pfand item quantity
   *   4. Add / update / remove Pfand as needed
   *   5. Publish cartUpdate so the cart drawer and cart page re-render
   */
  function syncPfand() {
    // If already in the middle of a Pfand update, skip to avoid double-fire
    if (pfandUpdateInProgress) {
      console.log('[Pfand Manager] Update already in progress, skipping');
      return;
    }
    pfandUpdateInProgress = true;

    fetch('/cart.js', { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (cart) {
        // Count total quantity of drink bottles in the cart
        var totalDrinkQty = 0;
        // Reference to the existing Pfand line item (if any)
        var pfandItem = null;

        cart.items.forEach(function (item) {
          if (item.variant_id === PFAND_VARIANT_ID) {
            // This is the Pfand line item itself
            pfandItem = item;
          } else if (item.properties && item.properties._pfand_eligible === 'Y') {
            // This is a drink item - add its quantity to the total
            totalDrinkQty += item.quantity;
          }
        });

        console.log('[Pfand Manager] Drink qty:', totalDrinkQty, '| Pfand exists:', !!pfandItem,
          pfandItem ? '(qty: ' + pfandItem.quantity + ')' : '');

        // CASE 1: No drinks in cart but Pfand exists → remove it
        if (totalDrinkQty === 0 && pfandItem) {
          console.log('[Pfand Manager] Removing Pfand (no drinks in cart)');
          return fetch('/cart/change.js', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id: pfandItem.key, quantity: 0 })
          })
          .then(function (r) { return r.json(); })
          .then(refreshCart);
        }

        // CASE 2: Drinks in cart but no Pfand yet → add it
        if (totalDrinkQty > 0 && !pfandItem) {
          console.log('[Pfand Manager] Adding Pfand with qty:', totalDrinkQty);
          return fetch('/cart/add.js', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
              items: [{
                id: PFAND_VARIANT_ID,
                quantity: totalDrinkQty,
                properties: {
                  _pfand: 'Y',      // Marks this as a Pfand item (hidden from cart display)
                  _pfand_auto: 'Y'   // Marks this as auto-managed (visible on order for merchant)
                }
              }]
            })
          })
          .then(function (r) { return r.json(); })
          .then(refreshCart);
        }

        // CASE 3: Both drinks and Pfand exist but quantities don't match → update
        if (totalDrinkQty > 0 && pfandItem && pfandItem.quantity !== totalDrinkQty) {
          console.log('[Pfand Manager] Updating Pfand qty from', pfandItem.quantity, 'to', totalDrinkQty);
          return fetch('/cart/change.js', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id: pfandItem.key, quantity: totalDrinkQty })
          })
          .then(function (r) { return r.json(); })
          .then(refreshCart);
        }

        // CASE 4: Already in sync (no drinks + no Pfand, or quantities match)
        console.log('[Pfand Manager] Already in sync, no action needed');
        pfandUpdateInProgress = false;
      })
      .catch(function (err) {
        console.error('[Pfand Manager] Error syncing Pfand:', err);
        pfandUpdateInProgress = false;
      });
  }

  /**
   * Called after any Pfand cart modification succeeds.
   * Resets the re-entrancy guard and publishes a cartUpdate event
   * with source='pfand-manager' so the cart UI (drawer + page) re-renders
   * but our own subscriber knows to ignore this event.
   */
  function refreshCart(cartData) {
    pfandUpdateInProgress = false;
    console.log('[Pfand Manager] Cart refreshed after Pfand update');
    // Publish cartUpdate so the cart drawer and cart page re-render
    publish(PUB_SUB_EVENTS.cartUpdate, { source: 'pfand-manager', cartData: cartData });
  }

  /* ── TRIGGER 1: Theme PubSub ──
     Listen for cart updates published by product-form.js, cart.js, quick-order-list.js.
     Skip events that we ourselves fired (source === 'pfand-manager'). */
  subscribe(PUB_SUB_EVENTS.cartUpdate, function (event) {
    if (event && event.source === 'pfand-manager') return;
    debouncedSync();
  });

  /* ── TRIGGER 2: Fetch Interceptor ──
     Catches any fetch-based cart operations (including PageFly's add-to-cart)
     that don't go through the theme's PubSub system.
     Skips interception when pfandUpdateInProgress is true (our own requests). */
  var originalFetch = window.fetch;
  window.fetch = function () {
    var url = arguments[0];
    // Handle both string URLs and Request objects
    var urlStr = typeof url === 'string' ? url : (url && url.url ? url.url : '');
    var isCartChange = /\/cart\/(add|change|update)\.js/.test(urlStr);

    var result = originalFetch.apply(this, arguments);

    // Only trigger sync for cart-modifying calls that aren't from Pfand Manager itself
    if (isCartChange && !pfandUpdateInProgress) {
      result.then(function () {
        debouncedSync();
      }).catch(function () { /* ignore errors - the original caller handles them */ });
    }

    return result;
  };

  /* ── TRIGGER 3: jQuery AJAX Interceptor ──
     Catches jQuery $.ajax cart operations (some PageFly forms use $.ajax).
     Uses jQuery's global ajaxComplete event to detect completed cart requests. */
  if (window.jQuery) {
    jQuery(document).ajaxComplete(function (event, xhr, settings) {
      if (settings && /\/cart\/(add|change|update)\.js/.test(settings.url || '')) {
        // Don't trigger if Pfand Manager is already handling an update
        if (!pfandUpdateInProgress) {
          debouncedSync();
        }
      }
    });
  }

  /* ── TRIGGER 4: Initial Page Load ──
     Run syncPfand on page load to handle returning visitors who already
     have drinks in their cart (e.g., coming back to /cart from another page). */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncPfand);
  } else {
    // DOM already loaded (script loaded with defer or at bottom of page)
    syncPfand();
  }

  console.log('[Pfand Manager] Initialized with variant ID:', PFAND_VARIANT_ID);
})();
