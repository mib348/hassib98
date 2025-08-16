import {
  BlockStack as CheckoutBlockStack,
  View as CheckoutView,
  Text as CheckoutText,
  TextBlock as CheckoutTextBlock,
  Image as CheckoutImage,
  Heading as CheckoutHeading,
  Link as CheckoutLink,
  Spinner as CheckoutSpinner,
  QRCode as CheckoutQRCode,
  InlineStack as CheckoutInlineStack,
  InlineLayout as CheckoutInlineLayout,
  extension as checkoutExtension,
} from "@shopify/ui-extensions/checkout";

import {
  BlockStack as CustomerAccountBlockStack,
  View as CustomerAccountView,
  Text as CustomerAccountText,
  TextBlock as CustomerAccountTextBlock,
  Image as CustomerAccountImage,
  Heading as CustomerAccountHeading,
  Link as CustomerAccountLink,
  Spinner as CustomerAccountSpinner,
  QRCode as CustomerAccountQRCode,
  InlineStack as CustomerAccountInlineStack,
  InlineLayout as CustomerAccountInlineLayout,
  extension as customerAccountExtension,
  // Note: Some components might not exist in customer-account or have different names.
  // This assumes common ones like Text, View, BlockStack, Heading, Link, Spinner, QRCode, InlineStack are available and compatible.
  // If specific components like Image or QRCode are not in customer-account, AppLogic would need adjustment.
} from "@shopify/ui-extensions/customer-account";

// Target the Order Status page (Thank You page).
// Make sure this target is enabled in your shopify.extension.toml
// REMOVED INCORRECT extend("purchase.order-status.block.render", ...) call that was here.

// --- Shared Application Logic ---
// Now accepts a 'components' object
function AppLogic(root, api, targetName, components) {
  const { BlockStack, View, Text, TextBlock, Image, Heading, Link, Spinner, QRCode, InlineStack, InlineLayout } = components;

  // --- Log API object for inspection (for development) ---
  console.log(`[Checkout UI Ext - ${targetName}] API object:`, api);
  console.log(`[Checkout UI Ext - ${targetName}] API version:`, api.version);
  console.log(`[Checkout UI Ext - ${targetName}] API settings:`, api.settings);
  console.log(`[Checkout UI Ext - ${targetName}] API app context:`, api.app);
  console.log(`[Checkout UI Ext - ${targetName}] Root object (raw):`, root);

  // Detect mobile devices through user agent when viewport API is unavailable
  function isMobileDevice() {
    try {
      // Ensure navigator is available before trying to access userAgent
      if (typeof navigator !== 'undefined' && navigator.userAgent) {
        const userAgent = navigator.userAgent || navigator.vendor || (typeof window !== 'undefined' ? window.opera : '');
        console.log(`[Checkout UI Ext - ${targetName}] User agent: ${userAgent}`);

        // Check for common mobile device indicators in user agent
        const mobileRegex = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile|tablet/i;
        const isMobile = mobileRegex.test(userAgent);
        console.log(`[Checkout UI Ext - ${targetName}] isMobile from user agent: ${isMobile}`);
        return isMobile;
      } else {
        console.warn(`[Checkout UI Ext - ${targetName}] Navigator or userAgent not available for device detection.`);
        return false; // Default to not mobile if navigator is unavailable
      }
    } catch (error) {
      console.error(`[Checkout UI Ext - ${targetName}] Error in isMobileDevice detection:`, error);
      return false; // Default to not mobile in case of any error
    }
  }

  // Store mobile detection result
  const isMobile = isMobileDevice();
  console.log(`[Checkout UI Ext - ${targetName}] Is mobile device: ${isMobile}`);

  // Check for viewport API specifically
  if (api.viewport) {
    console.log(`[Checkout UI Ext - ${targetName}] Viewport API is available`);
    console.log(`[Checkout UI Ext - ${targetName}] Viewport API type:`, typeof api.viewport);
    console.log(`[Checkout UI Ext - ${targetName}] Viewport API keys:`, Object.keys(api.viewport));

    if (api.viewport.current) {
      console.log(`[Checkout UI Ext - ${targetName}] Viewport CURRENT is available`);
      console.log(`[Checkout UI Ext - ${targetName}] Viewport CURRENT type:`, typeof api.viewport.current);
      console.log(`[Checkout UI Ext - ${targetName}] Viewport CURRENT keys:`, Object.keys(api.viewport.current));
      console.log(`[Checkout UI Ext - ${targetName}] Viewport CURRENT width:`, api.viewport.current.width);
      console.log(`[Checkout UI Ext - ${targetName}] Viewport CURRENT isSmall:`, api.viewport.current.isSmall);
    } else {
      console.warn(`[Checkout UI Ext - ${targetName}] Viewport CURRENT is NOT available`);
    }
  } else {
    console.warn(`[Checkout UI Ext - ${targetName}] Viewport API is NOT available`);
  }

  if (root && typeof root === 'object') {
    const rootKeys = Object.keys(root);
    console.log(`[Checkout UI Ext - ${targetName}] Root object ACTUAL keys:`, rootKeys);
    rootKeys.forEach(key => {
      console.log(`[Checkout UI Ext - ${targetName}] Root key [${key}] type: ${typeof root[key]}`);
      if (key === 'components' && typeof root[key] === 'object' && root[key] !== null) {
        const componentKeys = Object.keys(root[key]);
        console.log(`[Checkout UI Ext - ${targetName}] Root key [components] - ACTUAL its keys:`, componentKeys);
      }
      else if (typeof root[key] !== 'object' && typeof root[key] !== 'function') {
        console.log(`[Checkout UI Ext - ${targetName}] Root key [${key}] value:`, root[key]);
      }
    });
  }

  console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation (raw):`, api.orderConfirmation);
  if (api.orderConfirmation && typeof api.orderConfirmation === 'object') {
    const orderConfirmationKeys = Object.keys(api.orderConfirmation);
    console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation ACTUAL keys:`, orderConfirmationKeys);
    orderConfirmationKeys.forEach(key => {
      console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation key [${key}] type: ${typeof api.orderConfirmation[key]}`);
      if (key === 'current' && typeof api.orderConfirmation[key] === 'object' && api.orderConfirmation[key] !== null) {
        const currentOrderConfirmation = api.orderConfirmation[key];
        console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation key [current] (is object) - its keys:`, Object.keys(currentOrderConfirmation));
        Object.keys(currentOrderConfirmation).forEach(currentKey => {
          console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation.current key [${currentKey}] type: ${typeof currentOrderConfirmation[currentKey]}`);
          if (typeof currentOrderConfirmation[currentKey] !== 'object' && typeof currentOrderConfirmation[currentKey] !== 'function') {
            console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation.current key [${currentKey}] value:`, currentOrderConfirmation[currentKey]);
          }
          if (currentOrderConfirmation[currentKey] && typeof currentOrderConfirmation[currentKey] === 'object' && currentOrderConfirmation[currentKey].id) {
            console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation.current key [${currentKey}] has .id:`, currentOrderConfirmation[currentKey].id);
          }
          if (currentKey === 'order' && currentOrderConfirmation[currentKey] && typeof currentOrderConfirmation[currentKey] === 'object') {
            console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation.current.order found. Its keys:`, Object.keys(currentOrderConfirmation[currentKey]));
            if (currentOrderConfirmation[currentKey].id) {
              console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation.current.order has .id:`, currentOrderConfirmation[currentKey].id);
            }
          }
        });
      }
      else if (typeof api.orderConfirmation[key] !== 'object' && typeof api.orderConfirmation[key] !== 'function') {
        console.log(`[Checkout UI Ext - ${targetName}] api.orderConfirmation key [${key}] value:`, api.orderConfirmation[key]);
      }
    });
  }

  if (api.data && typeof api.data === 'object') {
    const dataKeys = Object.keys(api.data);
    console.log(`[Checkout UI Ext - ${targetName}] api.data ACTUAL keys:`, dataKeys);
    dataKeys.forEach(key => {
      console.log(`[Checkout UI Ext - ${targetName}] api.data key [${key}] type: ${typeof api.data[key]}`);
      if (typeof api.data[key] === 'object' && api.data[key] !== null) {
        console.log(`[Checkout UI Ext - ${targetName}] api.data key [${key}] (is object) - its keys:`, Object.keys(api.data[key]));
        if (api.data[key].id) {
          console.log(`[Checkout UI Ext - ${targetName}] api.data key [${key}] has .id:`, api.data[key].id);
        }
      }
    });
  } else {
    console.log(`[Checkout UI Ext - ${targetName}] api.data is not an object or is null/undefined.`, api.data);
  }


  // --- State Management ---
  let isLoading = true;
  let stationFlag = null;
  let arrLocation = null;
  let orderNumber = null;
  let showError = false;
  let retryCount = 0;
  const MAX_RETRIES = 10;
  const INITIAL_RETRY_DELAY = 500; // Start with 500ms

  // --- Get Order ID ---
  let shopifyOrderId = null;
  let numericOrderId = null;

  // --- Wait for Shopify Data Function ---
  function waitForShopifyData() {
    return new Promise((resolve, reject) => {
      let attempts = 0;
      const maxAttempts = MAX_RETRIES;

      function checkData() {
        attempts++;
        retryCount = attempts; // Update global retry count for loading message
        console.log(`[Checkout UI Ext - ${targetName}] Attempt ${attempts}/${maxAttempts} to get Shopify data`);

        // Update loading message to show progress
        if (attempts > 1) {
          renderApp(); // Re-render to show updated loading message
        }

        let hasData = false;

        if (targetName === "purchase.thank-you.block.render") {
          if (api.orderConfirmation && api.orderConfirmation.current) {
            if (api.orderConfirmation.current.name ||
              (api.orderConfirmation.current.order && api.orderConfirmation.current.order.name) ||
              api.orderConfirmation.current.id ||
              (api.orderConfirmation.current.order && api.orderConfirmation.current.order.id)) {
              hasData = true;
              console.log(`[Checkout UI Ext - ${targetName}] Shopify data available on attempt ${attempts}`);
            }
          }
        } else if (targetName === "customer-account.order-status.block.render") {
          if (api.order && api.order.current && api.order.current.name) {
            hasData = true;
            console.log(`[Checkout UI Ext - ${targetName}] Shopify data available on attempt ${attempts}`);
          }
        }

        if (hasData) {
          resolve();
          return;
        }

        if (attempts >= maxAttempts) {
          console.warn(`[Checkout UI Ext - ${targetName}] Max attempts reached, proceeding without Shopify data`);
          resolve(); // Resolve anyway to continue with fallback
          return;
        }

        // Exponential backoff: 500ms, 1000ms, 2000ms, 4000ms, etc.
        const delay = INITIAL_RETRY_DELAY * Math.pow(2, attempts - 1);
        console.log(`[Checkout UI Ext - ${targetName}] Waiting ${delay}ms before retry ${attempts + 1}`);
        setTimeout(checkData, delay);
      }

      checkData();
    });
  }

  // --- Viewport State & Subscription ---
  function classifyViewport(viewport) {
    console.log(`[Checkout UI Ext - ${targetName}] Classifying viewport:`, JSON.stringify(viewport));

    // More aggressive mobile detection - better to show mobile layout on smaller devices
    if (viewport.isSmall || (viewport.width && viewport.width < 768)) {
      console.log(`[Checkout UI Ext - ${targetName}] Viewport classified as 'small' (mobile) - width: ${viewport.width}, isSmall: ${viewport.isSmall}`);
      return 'small';
    }
    console.log(`[Checkout UI Ext - ${targetName}] Viewport classified as 'large' (desktop) - width: ${viewport.width}, isSmall: ${viewport.isSmall}`);
    return 'large';
  }

  // Default to 'large' since we're targeting desktop primarily
  let currentViewportSize = 'large';
  let hasViewportAPI = false;

  if (api.viewport) {
    hasViewportAPI = true;
    if (api.viewport.current) {
      console.log(`[Checkout UI Ext - ${targetName}] Raw api.viewport.current:`, JSON.parse(JSON.stringify(api.viewport.current)));
      currentViewportSize = classifyViewport(api.viewport.current);
      console.log(`[Checkout UI Ext - ${targetName}] Initial viewport size from api.viewport.current: ${currentViewportSize}`);
    } else {
      console.warn(`[Checkout UI Ext - ${targetName}] api.viewport.current not available at initialization. Defaulting viewport size to "large".`);
    }

    if (typeof api.viewport.subscribe === 'function') {
      console.log(`[Checkout UI Ext - ${targetName}] Setting up viewport subscription`);
      api.viewport.subscribe((newViewport) => {
        console.log(`[Checkout UI Ext - ${targetName}] Viewport changed:`, JSON.stringify(newViewport));
        const newSizeClass = classifyViewport(newViewport);
        if (newSizeClass !== currentViewportSize) {
          console.log(`[Checkout UI Ext - ${targetName}] Viewport size class changed from ${currentViewportSize} to ${newSizeClass}`);
          currentViewportSize = newSizeClass;
          if (!isLoading || (isLoading && !showError)) {
            console.log(`[Checkout UI Ext - ${targetName}] Re-rendering due to viewport change.`);
            renderApp();
          }
        } else {
          console.log(`[Checkout UI Ext - ${targetName}] Viewport changed but size class remains ${currentViewportSize}`);
        }
      });
    } else {
      console.warn(`[Checkout UI Ext - ${targetName}] api.viewport.subscribe is not available. Viewport changes will not trigger re-renders.`);
    }
  } else {
    console.warn(`[Checkout UI Ext - ${targetName}] Viewport API is NOT available. Using fixed layout.`);
    hasViewportAPI = false;
    // Default to mobile layout if user agent suggests mobile device
    if (isMobile) {
      console.log(`[Checkout UI Ext - ${targetName}] User agent indicates mobile device. Setting currentViewportSize to 'small'.`);
      currentViewportSize = 'small';
    } else {
      console.log(`[Checkout UI Ext - ${targetName}] User agent indicates desktop device. Keeping currentViewportSize as 'large'.`);
    }
  }

  function initializeAndFetch() {
    console.log(`[Checkout UI Ext - ${targetName}] Starting initializeAndFetch with retry mechanism`);

    // First, wait for Shopify data to become available
    waitForShopifyData().then(() => {
      console.log(`[Checkout UI Ext - ${targetName}] Shopify data check completed, proceeding with order ID extraction`);

      if (targetName === "customer-account.order-status.block.render") {
        console.log(`[Checkout UI Ext - ${targetName}] Full API object at initializeAndFetch start:`, JSON.parse(JSON.stringify(api)));
        console.log(`[Checkout UI Ext - ${targetName}] Checking for api.order:`, api.order);
        if (api.order) {
          console.log(`[Checkout UI Ext - ${targetName}] api.order exists. Checking for api.order.current:`, api.order.current);
          if (api.order.current) {
            console.log(`[Checkout UI Ext - ${targetName}] api.order.current exists. Checking for api.order.current.id:`, api.order.current.id);
            shopifyOrderId = api.order.current.id;
            console.log(`[Checkout UI Ext - ${targetName}] Order ID from api.order.current.id:`, shopifyOrderId);
          } else {
            console.log(`[Checkout UI Ext - ${targetName}] api.order.current is undefined/null.`);
          }
        } else {
          console.log(`[Checkout UI Ext - ${targetName}] api.order is undefined/null.`);
        }
      } else if (targetName === "purchase.thank-you.block.render") {
        if (api.orderConfirmation && api.orderConfirmation.current) {
          if (api.orderConfirmation.current.order && api.orderConfirmation.current.order.id) {
            shopifyOrderId = api.orderConfirmation.current.order.id;
            console.log(`[Checkout UI Ext - ${targetName}] Order ID from api.orderConfirmation.current.order.id:`, shopifyOrderId);
          } else if (api.orderConfirmation.current.id) {
            shopifyOrderId = api.orderConfirmation.current.id;
            console.log(`[Checkout UI Ext - ${targetName}] Order ID from api.orderConfirmation.current.id:`, shopifyOrderId);
          }
        }
      }

      // Fallback for api.extension.order.id (might be useful for some customer account contexts)
      if (!shopifyOrderId && targetName === "customer-account.order-status.block.render" && api.extension && api.extension.order && api.extension.order.id) {
        console.log(`[Checkout UI Ext - ${targetName}] Attempting fallback to api.extension.order.id`);
        shopifyOrderId = api.extension.order.id;
        console.log(`[Checkout UI Ext - ${targetName}] Order ID from api.extension.order.id:`, shopifyOrderId);
      }

      // Fallback for api.data.order.id (less likely, but kept for now)
      if (!shopifyOrderId && api.data && api.data.order && api.data.order.id) {
        shopifyOrderId = api.data.order.id;
        console.log(`[Checkout UI Ext - ${targetName}] Order ID from api.data.order.id:`, shopifyOrderId);
      }

      if (!shopifyOrderId) {
        console.error(`[Checkout UI Ext - ${targetName}] Order ID could not be extracted after waiting for Shopify data. See preceding logs for object structures.`);
        showError = true;
        isLoading = false;
        console.error(`[Checkout UI Ext - ${targetName}] Cannot proceed without Order ID. Rendering error.`);
        renderApp();
        return;
      }

      if (shopifyOrderId) {
        const idParts = shopifyOrderId.toString().split('/');
        numericOrderId = idParts[idParts.length - 1];
        console.log(`[Checkout UI Ext - ${targetName}] Numeric Order ID:`, numericOrderId);
      } else {
        console.error(`[Checkout UI Ext - ${targetName}] Numeric Order ID could not be derived. shopifyOrderId is null.`);
        showError = true;
        isLoading = false;
        console.error(`[Checkout UI Ext - ${targetName}] Cannot proceed without Numeric Order ID. Rendering error.`);
        renderApp();
        return;
      }

      if (numericOrderId) {
        fetchOrderData();
      } else {
        console.error(`[Checkout UI Ext - ${targetName}] fetchOrderData not called because numericOrderId is missing.`);
        showError = true;
        isLoading = false;
        renderApp();
      }
    }).catch((error) => {
      console.error(`[Checkout UI Ext - ${targetName}] Error in waitForShopifyData:`, error);
      showError = true;
      isLoading = false;
      renderApp();
    });
  }

  const apiBaseUrl = 'https://dev.sushi.catering';

  async function fetchOrderData() {
    try {
      console.log(`[Checkout UI Ext - ${targetName}] Starting fetchOrderData with retry mechanism`);

      // First try to get the order number from Shopify's API with retry
      let orderNumberFromShopify = null;

      // Wait for Shopify data to be available before proceeding
      await waitForShopifyData();

      if (targetName === "purchase.thank-you.block.render") {
        if (api.orderConfirmation && api.orderConfirmation.current) {
          // For Thank You page
          if (api.orderConfirmation.current.name) {
            // The order name typically contains the order number (e.g., "#1001")
            orderNumberFromShopify = api.orderConfirmation.current.name.replace('#', '');
            console.log(`[Checkout UI Ext - ${targetName}] Extracted Order Number from orderConfirmation.current.name: ${orderNumberFromShopify}`);
          } else if (api.orderConfirmation.current.order && api.orderConfirmation.current.order.name) {
            orderNumberFromShopify = api.orderConfirmation.current.order.name.replace('#', '');
            console.log(`[Checkout UI Ext - ${targetName}] Extracted Order Number from orderConfirmation.current.order.name: ${orderNumberFromShopify}`);
          }
          // Note: We intentionally don't use api.orderConfirmation.current.number as it's a confirmation number,
          // not the sequential order number needed for QR codes
        }
      } else if (targetName === "customer-account.order-status.block.render") {
        // For Order Status page
        if (api.order && api.order.current) {
          if (api.order.current.name) {
            orderNumberFromShopify = api.order.current.name.replace('#', '');
            console.log(`[Checkout UI Ext - ${targetName}] Extracted Order Number from order.current.name: ${orderNumberFromShopify}`);
          }
        }
      }

      // If we successfully got the order number from Shopify's API, use it
      if (orderNumberFromShopify) {
        orderNumber = orderNumberFromShopify;
        console.log(`[Checkout UI Ext - ${targetName}] Successfully got order number from Shopify API: ${orderNumber}`);
        // Set default values for other properties we would normally get from the server
        stationFlag = stationFlag || 'N'; // Default to not having a station
        arrLocation = arrLocation || { name: 'Default' };
      } else {
        // Fall back to the external API call if Shopify API didn't provide the order number
        console.log(`[Checkout UI Ext - ${targetName}] Could not get order number from Shopify API, falling back to external API call.`);
        const url = `${apiBaseUrl}/api/getordernumber/${numericOrderId}`;
        const response = await fetch(url);
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();

        orderNumber = data.order_number?.toString();
        stationFlag = data.arrLocation?.no_station;
        arrLocation = data.arrLocation;

        console.log(`[Checkout UI Ext - ${targetName}] Fetched Order Number from API: ${orderNumber}`);
        console.log(`[Checkout UI Ext - ${targetName}] Fetched No Station: ${stationFlag}`);
        console.log(`[Checkout UI Ext - ${targetName}] Fetched Location Details:`, arrLocation);
      }

      if (!orderNumber) {
        console.error(`[Checkout UI Ext - ${targetName}] Order number not found in Shopify API or external API after retry.`);
        throw new Error('Order number not found in Shopify API or external API after retry.');
      }

      console.log(`[Checkout UI Ext - ${targetName}] Successfully obtained order number: ${orderNumber}`);
      isLoading = false;
      showError = false;
      retryCount = 0; // Reset retry count on success
    } catch (error) {
      console.error(`[Checkout UI Ext - ${targetName}] Error getting order data:`, error);
      isLoading = false;
      showError = true;
    } finally {
      console.log(`[Checkout UI Ext - ${targetName}] In fetchOrderData finally. isLoading: ${isLoading}, showError: ${showError}. Calling renderApp.`);
      renderApp();
    }
  }

  // Shared function for QR code rendering to reduce redundancy
  function renderQRCodeSection(root, orderNumber, shouldShowMobileLayout) {
    console.log(`[Checkout UI Ext - ${targetName}] Rendering QR code with mobile layout: ${shouldShowMobileLayout}`);

    return root.createComponent(
      BlockStack, // This is the outerQRSectionContainer
      {
        spacing: 'base',
        padding: shouldShowMobileLayout ? 'none' : 'base', // Conditional padding
        border: 'base',
        borderRadius: 'base',
        background: 'surface'  // White background
      },
      [
        shouldShowMobileLayout
          ? (
            // Mobile layout (stacked)
            console.log(`[Checkout UI Ext - ${targetName}] Using MOBILE layout for QR code`),
            root.createComponent(
              BlockStack, // mobileMainStack
              {
                spacing: 'loose'
              },
              [
                // QR Code centered and full width
                root.createComponent(
                  BlockStack, // mobileQRCodeContainer
                  {
                    inlineAlignment: 'center',
                    maxInlineSize: '100%',
                    background: 'surface',
                    padding: 'none',
                    margin: 'none'
                  },
                  [
                    root.createComponent(
                      QRCode,
                      {
                        content: orderNumber.toString(),
                        size: 'fill',
                        accessibilityLabel: `QR-Code für Bestellung ${orderNumber}`
                      }
                    )
                  ]
                ),

                // Text content below QR code
                root.createComponent(
                  BlockStack, // mobileTextContainer
                  {
                    spacing: 'tight',
                    padding: 'base' // Added padding for mobile text
                  },
                  [
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      'Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Stelle hierfür die maximale Helligkeit deines Mobilgeräts ein und halte dein Mobilgerät senkrecht, mittig und im Abstand von ca. 10cm vor den Scanner.'
                    ),
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      'Bitte achte darauf, dass der QR-Code die volle Breite deines Bildschirms ausfüllen muss. Falls der QR-Code im Browser zu klein angezeigt wird, kannst du hineinzoomen oder den QR-Code aus deiner E-Mail verwenden.'
                    ),
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      'Nach dem Scannen, folge den Anweisungen auf dem Monitor. Wenn deine Bestellung nicht gefunden wurde, versuche es nach 30 Sekunden erneut. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail.'
                    ),
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      [
                        'Fragen und Antworten rund um deine Bestellung findest du ',
                        root.createComponent(
                          Link,
                          {
                            to: 'https://sushi.catering/pages/faq',
                            external: true
                          },
                          'hier'
                        ),
                        '.'
                      ]
                    )
                  ]
                )
              ]
            )
          )
          : // Desktop layout (side-by-side)
          (
            console.log(`[Checkout UI Ext - ${targetName}] Using DESKTOP layout for QR code`),
            root.createComponent(
              InlineLayout,
              {
                spacing: 'loose',
                columns: ['40%', '60%'],  // QR code takes 40%, text takes 60%
                blockAlignment: 'start'
              },
              [
                // QR Code (left column)
                root.createComponent(
                  QRCode,
                  {
                    content: orderNumber.toString(),
                    size: 'large',
                    accessibilityLabel: `QR-Code für Bestellung ${orderNumber}`
                  }
                ),

                // Text content (right column)
                root.createComponent(
                  BlockStack,
                  {
                    spacing: 'tight'
                  },
                  [
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      'Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Stelle hierfür die maximale Helligkeit deines Mobilgeräts ein und halte dein Mobilgerät senkrecht, mittig und im Abstand von ca. 10cm vor den Scanner.'
                    ),
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      'Bitte achte darauf, dass der QR-Code die volle Breite deines Bildschirms ausfüllen muss. Falls der QR-Code im Browser zu klein angezeigt wird, kannst du hineinzoomen oder den QR-Code aus deiner E-Mail verwenden.'
                    ),
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      'Nach dem Scannen, folge den Anweisungen auf dem Monitor. Wenn deine Bestellung nicht gefunden wurde, versuche es nach 30 Sekunden erneut. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail.'
                    ),
                    root.createComponent(
                      TextBlock,
                      { size: 'medium', inlineAlignment: 'start' },
                      [
                        'Fragen und Antworten rund um deine Bestellung findest du ',
                        root.createComponent(
                          Link,
                          {
                            to: 'https://sushi.catering/pages/faq',
                            external: true
                          },
                          'hier'
                        ),
                        '.'
                      ]
                    )
                  ]
                )
              ]
            )
          )
      ]
    );
  }

  function renderApp() {
    console.log(`[Checkout UI Ext - ${targetName}] renderApp CALLED. isLoading: ${isLoading}, showError: ${showError}`);

    // Ensure consistent mobile detection for both extensions
    // If viewport API is not available, use our user agent detection
    if (!hasViewportAPI) {
      if (isMobile) {
        console.log(`[Checkout UI Ext - ${targetName}] No viewport API available, using mobile detection from user agent.`);
        currentViewportSize = 'small';
      } else {
        console.log(`[Checkout UI Ext - ${targetName}] No viewport API available, using desktop detection from user agent.`);
        currentViewportSize = 'large';
      }
    }

    if (!root || typeof root.appendChild !== 'function') {
      console.error(`[Checkout UI Ext - ${targetName}] CRITICAL: root object is not available or does not support appendChild. Cannot render UI.`);
      if (typeof Text !== 'undefined') {
        console.error(`[Checkout UI Ext - ${targetName}] Critical: UI rendering impossible. root.createComponent might be needed if Text is a component type.`);
      }
      return;
    }
    console.log(`[Checkout UI Ext - ${targetName}] Before clearing, root.children.length (approx via lastChild): ${root.lastChild ? 'at least 1' : '0'}`);

    if (typeof root.replaceChildren === 'function') {
      console.log(`[Checkout UI Ext - ${targetName}] Attempting to clear root using root.replaceChildren().`);
      root.replaceChildren();
      console.log(`[Checkout UI Ext - ${targetName}] Cleared children using root.replaceChildren().`);
    } else if (typeof root.removeChild === 'function') {
      console.warn(`[Checkout UI Ext - ${targetName}] root.replaceChildren is not available. Falling back to removeChild loop.`);
      let removedCount = 0;
      while (root.lastChild) {
        root.removeChild(root.lastChild);
        removedCount++;
      }
      console.log(`[Checkout UI Ext - ${targetName}] Cleared ${removedCount} children from root using removeChild loop.`);
    } else {
      console.warn(`[Checkout UI Ext - ${targetName}] Neither root.replaceChildren nor root.removeChild is a function. Previous content might not be cleared.`);
    }
    console.log(`[Checkout UI Ext - ${targetName}] After clearing, root.children.length (approx via lastChild): ${root.lastChild ? 'at least 1 (should be 0)' : '0'}`);

    if (typeof BlockStack === 'undefined' || typeof View === 'undefined' || typeof Text === 'undefined' || typeof TextBlock === 'undefined' || typeof Image === 'undefined' || typeof Heading === 'undefined' || typeof Link === 'undefined' || typeof Spinner === 'undefined' || typeof QRCode === 'undefined' || typeof InlineStack === 'undefined' || typeof InlineLayout === 'undefined') {
      console.error(`[Checkout UI Ext - ${targetName}] One or more UI component types (from components object) are UNDEFINED.`);
      if (typeof Text !== 'undefined' && root.createComponent) {
        try {
          const errorComponent = root.createComponent(Text, { appearance: 'critical' }, 'Critical Error: Essential UI component types are missing. Please contact support.');
          root.appendChild(errorComponent);
        } catch (e) {
          console.error(`[Checkout UI Ext - ${targetName}] Failed to render critical component type error message AND Text component itself might be an issue.`, e);
        }
      } else {
        console.error(`[Checkout UI Ext - ${targetName}] Critical Error: Essential UI component types are missing AND Text component is not available for rendering an error message.`);
      }
      return;
    }

    if (!isLoading && !showError) {
      console.log(`[Checkout UI Ext - ${targetName}] renderApp: Rendering SUCCESS path.`);
      const appContainer = root.createComponent(BlockStack, { spacing: 'base' });

      if (stationFlag === 'Y' || arrLocation?.name === 'Delivery') {
        appContainer.appendChild(
          root.createComponent(Heading, { level: 2 },
            arrLocation?.name === 'Delivery' ? 'Lieferinformationen' : 'Abholinformationen'
          )
        );
        const instructionTextContent = arrLocation?.name === 'Delivery'
          ? (arrLocation?.checkout_note || 'Ihre Bestellung wird geliefert. Weitere Informationen finden Sie in Ihrer Bestätigungs-E-Mail.')
          : 'Wir liefern alle bestellten Gerichte in einer Sammellieferung täglich und frisch an die Rezeption. Achten Sie bitte darauf, dass die Gerichte bis zum Verzehr kühl gehalten werden müssen, z.B. im Kühlschrank. Um wie viel Uhr wir genau anliefern, entnehmen Sie bitte der Seite auf die Sie weitergeleitet werden, nachdem Sie den Standort festgelegt haben.';
        appContainer.appendChild(root.createComponent(Text, null, instructionTextContent));

        if (arrLocation?.name === 'Delivery' && arrLocation?.checkout_hyperlink) {
          appContainer.appendChild(
            root.createComponent(Link, { to: arrLocation.checkout_hyperlink, external: true },
              arrLocation.checkout_hyperlink_text || 'Weitere Details zur Lieferung'
            )
          );
        }
      }

      if (arrLocation?.name !== 'Delivery' && stationFlag !== 'Y') {
        if (orderNumber) {
          // Use shared function to render QR code section
          console.log(`[Checkout UI Ext - ${targetName}] Rendering QR code section. Current viewport size: ${currentViewportSize}, hasViewportAPI: ${hasViewportAPI}`);

          const shouldShowMobileLayout = currentViewportSize === 'small';
          console.log(`[Checkout UI Ext - ${targetName}] shouldShowMobileLayout determined as: ${shouldShowMobileLayout}`);

          appContainer.appendChild(
            renderQRCodeSection(root, orderNumber, shouldShowMobileLayout)
          );
        } else {
          appContainer.appendChild(root.createComponent(Text, { appearance: 'warning' }, 'Bestellnummer nicht gefunden, QR-Code kann nicht angezeigt werden.'));
        }
      }

      if (appContainer.children.length === 0 && !isLoading) {
        console.log(`[Checkout UI Ext - ${targetName}] No specific content to render, showing default message.`);
        appContainer.appendChild(root.createComponent(Text, null, 'Ihre Bestellung wurde erfolgreich übermittelt. Weitere Details finden Sie in Ihrer Bestätigungs-E-Mail.'));
      }
      root.appendChild(appContainer);
    } else if (isLoading) {
      console.log(`[Checkout UI Ext - ${targetName}] renderApp: Rendering LOADING path.`);
      const spinnerComponent = root.createComponent(Spinner);

      // Show different loading messages based on retry status
      let loadingMessage = 'Details werden geladen. Bitte warten!...';
      if (retryCount > 0) {
        loadingMessage = `Details werden geladen... Versuch ${retryCount}/${MAX_RETRIES}`;
      }

      const loadingTextComponent = root.createComponent(Text, null, loadingMessage);
      root.appendChild(spinnerComponent);
      root.appendChild(loadingTextComponent);
    } else if (showError) {
      console.log(`[Checkout UI Ext - ${targetName}] renderApp: Rendering ERROR path.`);
      const errorTextComponent = root.createComponent(Text, { appearance: 'critical' }, 'Fehler beim Laden der Bestelldetails. Bitte versuchen Sie es später erneut oder prüfen Sie Ihre E-Mail.');
      root.appendChild(errorTextComponent);
    }
    console.log(`[Checkout UI Ext - ${targetName}] renderApp FINISHED.`);
  }

  renderApp();
  initializeAndFetch();
}

// --- Extension Exports ---

const checkoutComponents = {
  BlockStack: CheckoutBlockStack,
  View: CheckoutView,
  Text: CheckoutText,
  TextBlock: CheckoutTextBlock,
  Image: CheckoutImage,
  Heading: CheckoutHeading,
  Link: CheckoutLink,
  Spinner: CheckoutSpinner,
  QRCode: CheckoutQRCode,
  InlineStack: CheckoutInlineStack,
  InlineLayout: CheckoutInlineLayout,
};

const customerAccountComponents = {
  BlockStack: CustomerAccountBlockStack,
  View: CustomerAccountView,
  Text: CustomerAccountText,
  TextBlock: CustomerAccountTextBlock,
  Image: CustomerAccountImage, // Assuming available, may need to check docs
  Heading: CustomerAccountHeading,
  Link: CustomerAccountLink,
  Spinner: CustomerAccountSpinner,
  QRCode: CustomerAccountQRCode, // Assuming available, may need to check docs
  InlineStack: CustomerAccountInlineStack, // Assuming available
  InlineLayout: CustomerAccountInlineLayout, // Assuming available
};

// Export for Thank You page
export const thankYouExtension = checkoutExtension(
  "purchase.thank-you.block.render",
  (root, api) => {
    AppLogic(root, api, "purchase.thank-you.block.render", checkoutComponents);
  }
);

// Export for Order Status page
export const orderStatusExtension = customerAccountExtension(
  "customer-account.order-status.block.render",
  (root, api) => {
    AppLogic(root, api, "customer-account.order-status.block.render", customerAccountComponents);
  }
);

// Remove default export if it exists, or ensure it's not used by toml
// export default ... (No default export needed if using named exports for targets)