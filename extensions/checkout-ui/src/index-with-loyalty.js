import * as CheckoutComponents from "@shopify/ui-extensions/checkout";
import * as CustomerAccountComponents from "@shopify/ui-extensions/customer-account";

// Constants for text content (including new loyalty texts)
const TEXTS = {
  LOADING: 'Details werden geladen. Bitte warten!...',
  LOADING_RETRY: (count, max) => `Details werden geladen... Versuch ${count}/${max}`,
  ERROR: 'Fehler beim Laden der Bestelldetails. Bitte versuchen Sie es später erneut oder prüfen Sie Ihre E-Mail.',
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
  DELIVERY_LINK_DEFAULT: 'Weitere Details zur Lieferung',

  // New loyalty program texts
  LOYALTY_HEADING: '🎯 Treueprogramm Status',
  LOYALTY_LOADING: 'Treueprogramm Status wird geladen...',
  LOYALTY_FREE_ITEMS_APPLIED: (count) => `🎉 ${count} GRATIS ARTIKEL ${count > 1 ? 'WURDEN' : 'WURDE'} AUTOMATISCH ANGEWENDET!`,
  LOYALTY_FREE_ITEMS_INFO: 'Der Rabatt wird automatisch auf die günstigsten Artikel angewendet.',
  LOYALTY_PROGRESS: (remaining) => `Noch ${remaining} ${remaining === 1 ? 'Artikel' : 'Artikel'} bis zu Ihrem nächsten Gratisartikel!`,
  LOYALTY_PURCHASE_COUNT: (count) => `Bisherige Käufe: ${count} Artikel`,
  LOYALTY_MEMBER_SINCE: (date) => `Mitglied seit: ${date}`,
  LOYALTY_NOT_MEMBER: 'Sie sind noch kein Mitglied unseres Treueprogramms.',
  LOYALTY_JOIN_INFO: 'Als Mitglied erhalten Sie jeden 5. Artikel gratis!',
  LOYALTY_ERROR: 'Fehler beim Laden des Treueprogramm Status.'
};

// Configuration - these values match shopify.app.toml [extension_config] section
const CONFIG = {
  MAX_RETRIES: 10,
  RETRY_DELAY: 500,
  API_BASE_URL: 'https://dev.sushi.catering'
};

// Loyalty program configuration
const LOYALTY_CONFIG = {
  ENABLED: true,
  ENDPOINTS: {
    STATUS: '/api/loyalty/status/'
  }
};

// Component resolver that works for both contexts (enhanced with Badge component)
function createComponentResolver(targetName) {
  const isCheckout = targetName === "purchase.thank-you.block.render";
  const components = isCheckout ? CheckoutComponents : CustomerAccountComponents;

  return {
    BlockStack: components.BlockStack,
    View: components.View,
    Text: components.Text,
    TextBlock: components.TextBlock,
    Image: components.Image,
    Heading: components.Heading,
    Link: components.Link,
    Spinner: components.Spinner,
    QRCode: components.QRCode,
    InlineStack: components.InlineStack,
    InlineLayout: components.InlineLayout,
    Badge: components.Badge, // Added for loyalty status display
    extension: isCheckout ? CheckoutComponents.extension : CustomerAccountComponents.extension
  };
}

// Utility functions
function log(targetName, level, message, ...args) {
  if (level === 'error' || (level === 'warn' && Math.random() < 0.1)) {
    console[level](`[${targetName}] ${message}`, ...args);
  }
}

function isMobileDevice() {
  if (typeof navigator === 'undefined') return false;
  const userAgent = navigator.userAgent || '';

  // Check for mobile device indicators
  const isMobileUA = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i.test(userAgent);

  // Also check for touch capability as additional indicator (guard window access)
  const isTouchDevice = (typeof window !== 'undefined' && 'ontouchstart' in window) ||
                        (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);

  // Check for small screen using matchMedia (more reliable than hardcoded breakpoints)
  const isSmallScreen = typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia('(max-width: 767px)').matches;

  // Mobile if: mobile user agent OR (touch device AND small screen)
  return isMobileUA || (isTouchDevice && isSmallScreen);
}

function classifyViewport(viewport) {
  // Use Shopify's built-in responsive classification - it knows best
  return viewport?.isSmall ? 'small' : 'large';
}

// Enhanced Application Logic with Loyalty Integration
function AppLogic(root, api, targetName) {
  const components = createComponentResolver(targetName);
  const { BlockStack, View, Text, TextBlock, Image, Heading, Link, Spinner, QRCode, InlineStack, InlineLayout, Badge } = components;

  log(targetName, 'info', 'Extension initialized with loyalty support');

  const isMobile = isMobileDevice();

  // Enhanced State Management (including loyalty state)
  let state = {
    isLoading: true,
    showError: false,
    retryCount: 0,
    orderNumber: null,
    stationFlag: null,
    arrLocation: null,
    shopifyOrderId: null,
    numericOrderId: null,

    // New loyalty state
    loyaltyData: null,
    loyaltyLoading: false,
    loyaltyError: null
  };

  // Optimized data waiting with reduced logging
  async function waitForShopifyData() {
    for (let attempt = 1; attempt <= CONFIG.MAX_RETRIES; attempt++) {
      state.retryCount = attempt;

      if (attempt > 1) renderApp(); // Show retry progress

      const hasData = checkShopifyDataAvailable();
      if (hasData) return true;

      if (attempt < CONFIG.MAX_RETRIES) {
        await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY));
      }
    }

    log(targetName, 'warn', 'Max retries reached, proceeding without Shopify data');
    return false;
  }

  function checkShopifyDataAvailable() {
    if (targetName === "purchase.thank-you.block.render") {
      const orderConfirmation = api.orderConfirmation?.current;
      if (!orderConfirmation) return false;

      const orderId = orderConfirmation.order?.id;
      if (orderId && isValidOrderId(orderId)) return true;

      return !!(orderConfirmation.name || orderConfirmation.order?.name || orderConfirmation.id);
    } else {
      return !!(api.order?.current?.name);
    }
  }

  function isValidOrderId(orderId) {
    if (typeof orderId !== 'string') return false;
    if (orderId.startsWith('gid://shopify/Order/') || orderId.startsWith('gid://shopify/OrderIdentity/')) {
      const extractedId = orderId.split('/').pop();
      return extractedId && extractedId !== '0' && !isNaN(parseInt(extractedId));
    }
    return orderId !== '0';
  }

  // Optimized viewport handling
  let currentViewportSize = 'large';
  let hasViewportAPI = false;

  if (api.viewport) {
    hasViewportAPI = true;
    if (api.viewport.current) {
      currentViewportSize = classifyViewport(api.viewport.current);
    }

    if (typeof api.viewport.subscribe === 'function') {
      api.viewport.subscribe((newViewport) => {
        const newSizeClass = classifyViewport(newViewport);
        if (newSizeClass !== currentViewportSize) {
          currentViewportSize = newSizeClass;
          if (!state.isLoading || !state.showError) {
            renderApp();
          }
        }
      });
    }
  } else {
    currentViewportSize = isMobile ? 'small' : 'large';
  }

  // New function: Extract customer email for loyalty API calls
  function getCustomerEmail() {
    try {
      let customerEmail = null;

      if (targetName === "purchase.thank-you.block.render") {
        const orderConfirmation = api.orderConfirmation?.current;
        customerEmail = orderConfirmation?.email || orderConfirmation?.customer?.email;
      } else {
        customerEmail = api.order?.current?.email || api.order?.current?.customer?.email;
      }

      return customerEmail;
    } catch (error) {
      log(targetName, 'error', 'Error extracting customer email:', error);
      return null;
    }
  }

  // New function: Fetch loyalty status from Laravel API
  async function fetchLoyaltyStatus() {
    if (!LOYALTY_CONFIG.ENABLED) {
      return null;
    }

    try {
      state.loyaltyLoading = true;

      const customerEmail = getCustomerEmail();
      if (!customerEmail) {
        log(targetName, 'info', 'No customer email available for loyalty lookup');
        state.loyaltyLoading = false;
        return null;
      }

      log(targetName, 'info', 'Fetching loyalty status for:', customerEmail);

      const response = await fetch(
        `${CONFIG.API_BASE_URL}${LOYALTY_CONFIG.ENDPOINTS.STATUS}${encodeURIComponent(customerEmail)}`
      );

      if (!response.ok) {
        if (response.status === 404 || response.status === 400) {
          // Customer not found or not a loyalty member - this is normal
          state.loyaltyData = { is_member: false };
        } else {
          throw new Error(`API error (${response.status})`);
        }
      } else {
        const data = await response.json();
        state.loyaltyData = data;
        log(targetName, 'info', 'Loyalty status retrieved:', data);
      }

      state.loyaltyLoading = false;
      state.loyaltyError = null;
      return state.loyaltyData;

    } catch (error) {
      log(targetName, 'error', 'Loyalty fetch error:', error);
      state.loyaltyError = error.message;
      state.loyaltyLoading = false;
      return null;
    }
  }

  // Enhanced initialization with loyalty data fetching
  async function initializeAndFetch() {
    try {
      await waitForShopifyData();

      // Start loyalty fetch in parallel with order data
      const loyaltyPromise = fetchLoyaltyStatus();

      const extractedData = extractOrderData();
      if (!extractedData.shopifyOrderId) {
        throw new Error('Order ID could not be extracted');
      }

      state.shopifyOrderId = extractedData.shopifyOrderId;
      state.numericOrderId = extractedData.numericOrderId;

      // Fetch order data and loyalty data in parallel for better performance
      const promises = [];

      if (state.numericOrderId && state.numericOrderId !== '0') {
        promises.push(fetchOrderData());
      } else {
        if (state.retryCount < CONFIG.MAX_RETRIES) {
          state.retryCount++;
          setTimeout(() => initializeAndFetch(), CONFIG.RETRY_DELAY);
          return;
        }
        throw new Error('Invalid order ID after max retries');
      }

      // Always try to fetch loyalty data
      promises.push(loyaltyPromise);

      // Wait for both to complete
      await Promise.all(promises);

    } catch (error) {
      log(targetName, 'error', 'Error in initializeAndFetch:', error);
      state.showError = true;
      state.isLoading = false;
      renderApp();
    }
  }

  function extractOrderData() {
    let shopifyOrderId = null;

    if (targetName === "customer-account.order-status.block.render") {
      shopifyOrderId = api.order?.current?.id;
    } else if (targetName === "purchase.thank-you.block.render") {
      const orderConfirmation = api.orderConfirmation?.current;
      shopifyOrderId = orderConfirmation?.order?.id || orderConfirmation?.id;
    }

    // Fallback options
    if (!shopifyOrderId) {
      shopifyOrderId = api.extension?.order?.id || api.data?.order?.id;
    }

    const numericOrderId = extractNumericId(shopifyOrderId);
    return { shopifyOrderId, numericOrderId };
  }

  function extractNumericId(orderId) {
    if (!orderId) return null;

    if (typeof orderId === 'string' &&
        (orderId.startsWith('gid://shopify/Order/') || orderId.startsWith('gid://shopify/OrderIdentity/'))) {
      const extractedId = orderId.split('/').pop();
      return (extractedId && extractedId !== '0' && !isNaN(parseInt(extractedId))) ? extractedId : null;
    }

    // Ensure orderId is not null/undefined before calling toString()
    return orderId ? orderId.toString() : null;
  }

  async function fetchOrderData() {
    try {
      // Try to get order number from Shopify first
      let orderNumberFromShopify = null;

      if (targetName === "purchase.thank-you.block.render") {
        const orderConfirmation = api.orderConfirmation?.current;
        const orderName = orderConfirmation?.name || orderConfirmation?.order?.name;
        if (orderName) {
          orderNumberFromShopify = orderName.replace('#', '');
        }
      } else {
        const orderName = api.order?.current?.name;
        if (orderName) {
          orderNumberFromShopify = orderName.replace('#', '');
        }
      }

      if (orderNumberFromShopify) {
        // Use Shopify data with defaults
        state.orderNumber = orderNumberFromShopify;
        state.stationFlag = state.stationFlag || 'N';
        state.arrLocation = state.arrLocation || { name: 'Default' };
      } else {
        // Fallback to external API
        const response = await fetch(`${CONFIG.API_BASE_URL}/api/getordernumber/${state.numericOrderId}`);

        if (!response.ok) {
          const errorMsg = response.status === 404
            ? `Order ID '${state.numericOrderId}' not found`
            : `API error (${response.status})`;
          throw new Error(errorMsg);
        }

        const data = await response.json();
        state.orderNumber = data.order_number?.toString();
        state.stationFlag = data.arrLocation?.no_station;
        state.arrLocation = data.arrLocation;
      }

      if (!state.orderNumber) {
        throw new Error('Order number not found');
      }

      // Success - update state and render
      state.isLoading = false;
      state.showError = false;
      state.retryCount = 0;
      renderApp();

    } catch (error) {
      log(targetName, 'error', 'Error fetching order data:', error);

      // Retry logic for recoverable errors
      if (error.message.includes('Order ID') && state.retryCount < CONFIG.MAX_RETRIES) {
        state.retryCount++;
        setTimeout(() => fetchOrderData(), CONFIG.RETRY_DELAY);
        return;
      }

      // Final error state
      state.isLoading = false;
      state.showError = true;
      renderApp();
    }
  }

  // New function: Create loyalty status display component
  function createLoyaltyDisplay() {
    try {
      // Don't show anything if loyalty is disabled or still loading
      if (!LOYALTY_CONFIG.ENABLED || state.loyaltyLoading) {
        return null;
      }

      // Don't show if there's no loyalty data or customer is not a member
      if (!state.loyaltyData || !state.loyaltyData.is_member) {
        return null;
      }

      const container = root.createComponent(BlockStack, {
        spacing: 'base',
        padding: 'base',
        border: 'base',
        cornerRadius: 'base'
      });

      // Loyalty header
      const header = root.createComponent(Heading, {
        level: 3
      }, TEXTS.LOYALTY_HEADING);
      container.appendChild(header);

      // Member status and points row
      const statusRow = root.createComponent(InlineStack, {
        spacing: 'base',
        blockAlignment: 'center'
      });

      // Tier badge with appropriate color
      const tierColors = {
        'gold': 'warning',
        'silver': 'info',
        'bronze': 'success'
      };

      const tierBadge = root.createComponent(Badge, {
        tone: tierColors[state.loyaltyData.tier] || 'success'
      }, `${state.loyaltyData.tier.toUpperCase()} MITGLIED`);

      const pointsText = root.createComponent(Text, {
        size: 'medium',
        emphasis: 'bold'
      }, `${state.loyaltyData.points} Punkte`);

      statusRow.appendChild(tierBadge);
      statusRow.appendChild(pointsText);
      container.appendChild(statusRow);

      // Free items notification or progress
      if (state.loyaltyData.free_items_available > 0) {
        // Customer has free items applied
        const freeItemNotice = root.createComponent(BlockStack, {
          padding: 'tight',
          background: 'subdued',
          cornerRadius: 'base',
          spacing: 'tight'
        });

        const noticeText = root.createComponent(Text, {
          size: 'medium',
          appearance: 'success',
          emphasis: 'bold'
        }, TEXTS.LOYALTY_FREE_ITEMS_APPLIED(state.loyaltyData.free_items_available));

        const infoText = root.createComponent(Text, {
          size: 'small',
          appearance: 'subdued'
        }, TEXTS.LOYALTY_FREE_ITEMS_INFO);

        freeItemNotice.appendChild(noticeText);
        freeItemNotice.appendChild(infoText);
        container.appendChild(freeItemNotice);

      } else if (state.loyaltyData.next_free_item_in > 0) {
        // Show progress towards next free item
        const progressText = root.createComponent(Text, {
          size: 'small',
          appearance: 'subdued'
        }, TEXTS.LOYALTY_PROGRESS(state.loyaltyData.next_free_item_in));
        container.appendChild(progressText);
      }

      // Purchase count
      const purchaseText = root.createComponent(Text, {
        size: 'small'
      }, TEXTS.LOYALTY_PURCHASE_COUNT(state.loyaltyData.items_purchased));
      container.appendChild(purchaseText);

      // Member since date if available
      if (state.loyaltyData.member_since) {
        const memberSinceText = root.createComponent(Text, {
          size: 'small',
          appearance: 'subdued'
        }, TEXTS.LOYALTY_MEMBER_SINCE(state.loyaltyData.member_since));
        container.appendChild(memberSinceText);
      }

      return container;

    } catch (error) {
      log(targetName, 'error', 'Error creating loyalty display:', error);
      return null;
    }
  }

  // Optimized QR code rendering with unified logic (unchanged)
  function createQRCodeSection(orderNumber, isMobile) {
    if (!root?.createComponent) return null;

    const qrCode = root.createComponent(QRCode, {
      content: orderNumber.toString(),
      size: isMobile ? 'fill' : 'large',
      accessibilityLabel: `QR-Code für Bestellung ${orderNumber}`
    });

    const textBlocks = createTextBlocks();
    const textContainer = root.createComponent(BlockStack, {
      spacing: 'tight',
      ...(isMobile && { padding: 'base' })
    });

    textBlocks.forEach(block => textContainer.appendChild(block));

    if (isMobile) {
      // Mobile: stacked layout
      const qrContainer = root.createComponent(BlockStack, {
        inlineAlignment: 'center',
        maxInlineSize: '100%',
        background: 'surface'
      });
      qrContainer.appendChild(qrCode);

      const mainStack = root.createComponent(BlockStack, { spacing: 'loose' });
      mainStack.appendChild(qrContainer);
      mainStack.appendChild(textContainer);
      return mainStack;
    } else {
      // Desktop: side-by-side layout
      const inlineLayout = root.createComponent(InlineLayout, {
        spacing: 'loose',
        columns: ['40%', '60%'],
        blockAlignment: 'start'
      });
      inlineLayout.appendChild(qrCode);
      inlineLayout.appendChild(textContainer);
      return inlineLayout;
    }
  }

  function createTextBlocks() {
    const faqLink = root.createComponent(Link, {
      to: TEXTS.FAQ_LINK,
      external: true
    }, TEXTS.FAQ_TEXT);

    return [
      ...TEXTS.QR_INSTRUCTIONS.map(text =>
        root.createComponent(TextBlock, { size: 'medium', inlineAlignment: 'start' }, text)
      ),
      root.createComponent(TextBlock, { size: 'medium', inlineAlignment: 'start' }, [
        TEXTS.FAQ_PREFIX,
        faqLink,
        '.'
      ])
    ];
  }

  function renderApp() {
    // Validate prerequisites
    if (!root?.appendChild || !root?.createComponent) {
      log(targetName, 'error', 'Root object invalid');
      return;
    }

    // Update viewport size if no API available
    if (!hasViewportAPI) {
      currentViewportSize = isMobile ? 'small' : 'large';
    }

    // Clear previous content
    try {
      if (root.replaceChildren) {
        root.replaceChildren();
      } else {
        while (root.lastChild) root.removeChild(root.lastChild);
      }
    } catch (error) {
      log(targetName, 'warn', 'Could not clear root content');
    }

    // Render based on current state
    if (state.isLoading) {
      renderLoadingState();
    } else if (state.showError) {
      renderErrorState();
    } else {
      renderSuccessState();
    }
  }

  function renderLoadingState() {
    const spinner = root.createComponent(Spinner);
    const message = state.retryCount > 0
      ? TEXTS.LOADING_RETRY(state.retryCount, CONFIG.MAX_RETRIES)
      : TEXTS.LOADING;
    const text = root.createComponent(Text, null, message);

    root.appendChild(spinner);
    root.appendChild(text);
  }

  function renderErrorState() {
    const error = root.createComponent(Text, { appearance: 'critical' }, TEXTS.ERROR);
    root.appendChild(error);
  }

  // Enhanced success state rendering with loyalty display
  function renderSuccessState() {
    const container = root.createComponent(BlockStack, { spacing: 'base' });

    // Add loyalty display first if available
    if (state.loyaltyData && state.loyaltyData.is_member) {
      const loyaltyDisplay = createLoyaltyDisplay();
      if (loyaltyDisplay) {
        container.appendChild(loyaltyDisplay);
      }
    }

    // Original delivery/pickup/QR code logic
    const isDelivery = state.arrLocation?.name === 'Delivery';
    const isStationPickup = state.stationFlag === 'Y';

    // Render delivery/pickup info if needed
    if (isDelivery || isStationPickup) {
      renderDeliveryInfo(container, isDelivery);
    }

    // Render QR code section if applicable
    if (!isDelivery && !isStationPickup && state.orderNumber) {
      const qrSection = createQRCodeSection(state.orderNumber, currentViewportSize === 'small');
      if (qrSection) container.appendChild(qrSection);
    } else if (!state.orderNumber && !isDelivery && !isStationPickup) {
      container.appendChild(
        root.createComponent(Text, { appearance: 'warning' }, TEXTS.NO_ORDER_NUMBER)
      );
    }

    // Default message if no content
    if (container.children.length === 0) {
      container.appendChild(
        root.createComponent(Text, null, TEXTS.SUCCESS_FALLBACK)
      );
    }

    root.appendChild(container);
  }

  function renderDeliveryInfo(container, isDelivery) {
    const heading = root.createComponent(
      Heading,
      { level: 2 },
      isDelivery ? TEXTS.DELIVERY_HEADING : TEXTS.PICKUP_HEADING
    );

    const instruction = isDelivery
      ? (state.arrLocation?.checkout_note || TEXTS.DELIVERY_FALLBACK)
      : TEXTS.PICKUP_INSTRUCTION;

    const text = root.createComponent(Text, null, instruction);

    container.appendChild(heading);
    container.appendChild(text);

    // Add delivery link if available
    if (isDelivery && state.arrLocation?.checkout_hyperlink) {
      const link = root.createComponent(
        Link,
        { to: state.arrLocation.checkout_hyperlink, external: true },
        state.arrLocation.checkout_hyperlink_text || TEXTS.DELIVERY_LINK_DEFAULT
      );
      container.appendChild(link);
    }
  }

  // Initialize the extension
  renderApp(); // Show loading state immediately
  initializeAndFetch(); // Start data fetching (now includes loyalty)
}

// Optimized Extension Exports (unchanged)
export const thankYouExtension = CheckoutComponents.extension(
  "purchase.thank-you.block.render",
  (root, api) => {
    try {
      AppLogic(root, api, "purchase.thank-you.block.render");
    } catch (error) {
      log("purchase.thank-you.block.render", 'error', 'Extension initialization failed:', error);
      // Fallback UI
      if (root?.createComponent && CheckoutComponents.Text) {
        const fallback = root.createComponent(
          CheckoutComponents.Text,
          { appearance: 'critical' },
          'Extension failed to load. Please refresh the page.'
        );
        root.appendChild?.(fallback);
      }
    }
  }
);

export const orderStatusExtension = CustomerAccountComponents.extension(
  "customer-account.order-status.block.render",
  (root, api) => {
    try {
      AppLogic(root, api, "customer-account.order-status.block.render");
    } catch (error) {
      log("customer-account.order-status.block.render", 'error', 'Extension initialization failed:', error);
      // Fallback UI
      if (root?.createComponent && CustomerAccountComponents.Text) {
        const fallback = root.createComponent(
          CustomerAccountComponents.Text,
          { appearance: 'critical' },
          'Extension failed to load. Please refresh the page.'
        );
        root.appendChild?.(fallback);
      }
    }
  }
);

// Remove default export if it exists, or ensure it's not used by toml
// export default ... (No default export needed if using named exports for targets)