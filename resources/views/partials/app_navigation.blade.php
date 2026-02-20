{{--
    AppBridge v4 Navigation Helper Function
    This partial provides a global navigation function that preserves Shopify embed context
    Include this partial before your scripts section: @include('partials.app_navigation')
--}}

<script>
    /**
     * Navigate to a page while preserving Shopify embed context
     * This function maintains the shop, host, token, and other query parameters
     * required for proper embedded app navigation in Shopify admin
     *
     * @param {string} path - The target path (e.g., '/orders' or '/kitchen/ADMIN?menu=1')
     * @example navigateToPage('/orders')
     * @example navigateToPage('/kitchen/ADMIN?menu=1')
     */
    function navigateToPage(path) {
        // Get current URL search parameters to preserve embed context
        const currentParams = new URLSearchParams(window.location.search);

        // Parse the path to check if it already has query parameters
        const [basePath, pathParams] = path.split('?');

        // Merge existing parameters from current URL with new path
        if (pathParams) {
            // If path has its own params (like menu=1), add them
            const pathParamsObj = new URLSearchParams(pathParams);
            pathParamsObj.forEach((value, key) => {
                currentParams.set(key, value);
            });
        }

        // Construct the new URL with preserved parameters
        const newUrl = basePath + '?' + currentParams.toString();

        // Navigate using window.location to maintain embed context
        window.location.href = newUrl;
    }

    /**
     * Return the best available Shopify session token for authenticated requests.
     * Priority:
     * 1) window.sessionToken from AppBridge token handler (auto-refreshed)
     * 2) token query param for initial page entries
     */
    function getShopifySessionToken() {
        if (window.sessionToken) {
            return window.sessionToken;
        }

        const currentParams = new URLSearchParams(window.location.search);
        return currentParams.get('token');
    }

    /**
     * Standardize backend error extraction so alerts do not show "undefined".
     * Shopify middleware returns { error: "..." } while Laravel often returns { message: "..." }.
     */
    function getAjaxErrorMessage(xhr, fallbackMessage) {
        const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

        if (response && response.message) {
            return response.message;
        }

        if (response && response.error) {
            return response.error;
        }

        if (xhr && xhr.statusText) {
            return xhr.statusText;
        }

        return fallbackMessage;
    }

    /**
     * Wait for AppBridge token bootstrap before making first page-load API call.
     * If AppBridge is unavailable, callback runs immediately for non-embedded usage.
     */
    function waitForShopifySessionToken(callback, maxWaitMs = 3000, pollIntervalMs = 100) {
        if (typeof callback !== 'function') {
            return;
        }

        const appBridgeCanProvideToken = window.shopify && typeof window.shopify.idToken === 'function';
        if (!appBridgeCanProvideToken) {
            callback();
            return;
        }

        const startTime = Date.now();

        const tryRun = () => {
            if (window.sessionToken || Date.now() - startTime >= maxWaitMs) {
                callback();
                return;
            }

            setTimeout(tryRun, pollIntervalMs);
        };

        tryRun();
    }

    // Expose helpers globally for all Blade views that include this partial.
    window.getShopifySessionToken = getShopifySessionToken;
    window.getAjaxErrorMessage = getAjaxErrorMessage;
    window.waitForShopifySessionToken = waitForShopifySessionToken;

    // Ensure jQuery requests carry Shopify bearer token for verify.shopify middleware.
    if (window.jQuery) {
        window.jQuery.ajaxSetup({
            beforeSend: function (xhr) {
                const sessionToken = getShopifySessionToken();

                if (sessionToken) {
                    xhr.setRequestHeader('Authorization', `Bearer ${sessionToken}`);
                }
            }
        });
    }

    // Ensure fetch-based requests (used by some views) also carry the bearer token.
    if (window.fetch && !window.__shopifyFetchTokenPatched) {
        const originalFetch = window.fetch.bind(window);

        window.fetch = function (input, init) {
            const requestInit = init || {};
            const requestUrl = typeof input === 'string' ? input : (input && input.url ? input.url : '');
            const resolvedUrl = requestUrl ? new URL(requestUrl, window.location.origin) : null;
            const isSameOriginRequest = resolvedUrl && resolvedUrl.origin === window.location.origin;

            if (isSameOriginRequest) {
                const inputHeaders = input instanceof Request ? input.headers : {};
                const headers = new Headers(requestInit.headers || inputHeaders);
                const sessionToken = getShopifySessionToken();

                if (sessionToken && !headers.has('Authorization')) {
                    headers.set('Authorization', `Bearer ${sessionToken}`);
                }

                requestInit.headers = headers;
            }

            return originalFetch(input, requestInit);
        };

        window.__shopifyFetchTokenPatched = true;
    }
</script>
