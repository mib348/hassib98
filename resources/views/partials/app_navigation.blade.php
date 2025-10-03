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
</script>
