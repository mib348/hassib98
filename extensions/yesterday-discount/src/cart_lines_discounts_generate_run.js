/**
 * Shopify Function: Yesterday Items Automatic Discount
 *
 * This function automatically applies 50% discounts to items from yesterday.
 * Items are identified by the line attribute: yesterday_item="Y"
 *
 * IMPORTANT: Discount Priority System
 * - If customer is eligible for 100% loyalty discount (active member with free items),
 *   this function will NOT apply the 50% discount
 * - This ensures customers always get the BETTER discount (100% > 50%)
 * - Guest customers and non-loyalty members still get 50% on yesterday items
 *
 * The function:
 * 1. Checks if product discount class is enabled
 * 2. Checks if customer is eligible for better 100% loyalty discount
 * 3. If loyalty eligible, skips 50% discount (let loyalty function handle it)
 * 4. If NOT loyalty eligible, filters cart lines for yesterday_item="Y" attribute
 * 5. Excludes delivery/service items from eligibility
 * 6. Applies 50% discount to all qualifying items
 * 7. Returns discount operations for Shopify to apply
 */

import {
  DiscountClass,
  ProductDiscountSelectionStrategy,
} from '../generated/api';

/**
 * @typedef {import("../generated/api").CartInput} RunInput
 * @typedef {import("../generated/api").CartLinesDiscountsGenerateRunResult} CartLinesDiscountsGenerateRunResult
 */

/**
 * Main function entry point called by Shopify
 * Export name must match kebab-case format in TOML: cart-lines-discounts-generate-run
 * @param {RunInput} input - Cart data from GraphQL query
 * @returns {CartLinesDiscountsGenerateRunResult} Discount operations configuration
 */
export function cartLinesDiscountsGenerateRun(input) {
  try {
    // Validate input structure
    // The input should contain cart data with lines array
    // If cart is empty or missing, we cannot apply discounts
    if (!input?.cart || !input.cart.lines.length) {
      console.error('No cart data or cart lines provided to yesterday items discount function');
      return { operations: [] };
    }

    // Check if product discount class is enabled for this discount
    // DiscountClass.Product allows discounting individual line items
    // If not enabled, this function cannot apply discounts
    const hasProductDiscountClass = input.discount?.discountClasses?.includes(
      DiscountClass.Product,
    );

    if (!hasProductDiscountClass) {
      // Product discount class not enabled, cannot apply discounts
      console.log('Product discount class not enabled - skipping yesterday items discount');
      return { operations: [] };
    }

    // PRIORITY CHECK: If customer is eligible for 100% loyalty discount, skip 50% yesterday discount
    // This ensures customers always get the BETTER discount (100% > 50%)
    // We check if customer is:
    // 1. Logged in (has customer object)
    // 2. Active loyalty member (status = "active")
    // 3. Has free items available (free_items > 0)
    // If all true, return empty operations and let loyalty function apply 100% discount
    const customer = input.cart.buyerIdentity?.customer;

    if (customer) {
      // Customer is logged in - check if they're eligible for better loyalty discount
      const loyaltyEligibility = checkLoyaltyEligibility(customer);

      if (loyaltyEligibility.isActive && loyaltyEligibility.hasFreeItems) {
        // Customer is eligible for 100% loyalty discount
        // Skip 50% yesterday discount to let loyalty function apply the better discount
        console.log(`Customer ${customer.email} eligible for 100% loyalty discount - skipping 50% yesterday discount`);
        console.log(`  Loyalty status: ${loyaltyEligibility.status}, Free items: ${loyaltyEligibility.freeItems}`);
        return { operations: [] };
      }

      console.log(`Customer ${customer.email} not eligible for loyalty discount - proceeding with 50% yesterday discount`);
    } else {
      console.log('Guest customer - proceeding with 50% yesterday discount');
    }

    // Find cart items eligible for yesterday item discount
    // This checks two criteria:
    // 1. Item has yesterday_item="Y" attribute
    // 2. Item is not a delivery/service item
    const yesterdayItems = getYesterdayEligibleCartLines(input.cart.lines);

    if (yesterdayItems.length === 0) {
      // No yesterday items in cart
      console.log('No yesterday items found in cart - skipping discount');
      return { operations: [] };
    }

    // Generate discount operations for all yesterday items
    // Each eligible item will receive a 50% discount candidate
    const operations = generateYesterdayItemDiscountOperations(yesterdayItems);

    console.log(`Applied 50% discount to ${yesterdayItems.length} yesterday items`);

    return {
      operations,
    };

  } catch (error) {
    // Catch any unexpected errors during execution
    // Log the error for debugging but return empty operations to avoid breaking checkout
    console.error('Error in yesterday items discount function:', error);
    // Return empty operations on error to avoid breaking checkout
    return { operations: [] };
  }
}

/**
 * Check if customer is eligible for 100% loyalty discount
 * This determines whether we should skip the 50% yesterday discount
 * to allow the better loyalty discount to apply
 *
 * Loyalty eligibility requires:
 * 1. Active loyalty status (status = "active")
 * 2. Free items available (free_items > 0)
 *
 * Priority: custom namespace > loyalty namespace
 *
 * @param {Object} customer - Customer object with metafield properties from GraphQL
 * @returns {Object} Eligibility info {isActive, hasFreeItems, status, freeItems}
 */
function checkLoyaltyEligibility(customer) {
  // Extract loyalty status from metafields (custom namespace takes priority)
  let status = null;
  if (customer.customLoyaltyStatus !== undefined) {
    status = customer.customLoyaltyStatus?.value || null;
  } else if (customer.loyaltyStatus !== undefined) {
    status = customer.loyaltyStatus?.value || null;
  }

  // Extract free items count from metafields (custom namespace takes priority)
  let freeItems = 0;
  if (customer.customLoyaltyFreeItems !== undefined) {
    // Use !== undefined check to handle "0" string correctly
    freeItems = parseInt(customer.customLoyaltyFreeItems?.value || '0') || 0;
  } else if (customer.loyaltyFreeItems !== undefined) {
    freeItems = parseInt(customer.loyaltyFreeItems?.value || '0') || 0;
  }

  const isActive = status === 'active';
  const hasFreeItems = freeItems > 0;

  return {
    isActive,
    hasFreeItems,
    status,
    freeItems
  };
}

/**
 * Filter cart lines to find items eligible for yesterday item discount
 *
 * Eligibility criteria:
 * 1. Must have line attribute yesterday_item="Y" (case-insensitive)
 * 2. Must be a ProductVariant (not custom line items)
 * 3. Must NOT be delivery/service/gift card items
 *
 * @param {Array} cartLines - Array of cart line items from GraphQL query
 * @returns {Array} Array of eligible yesterday item cart lines
 */
function getYesterdayEligibleCartLines(cartLines) {
  return cartLines.filter(line => {
    // Only process ProductVariant merchandise
    // Custom line items and other merchandise types are excluded
    if (line.merchandise.__typename !== 'ProductVariant') {
      return false;
    }

    // Check if item has yesterday_item="Y" attribute
    // This attribute is set when items are added to cart from yesterday's inventory
    if (!isYesterdayItem(line)) {
      return false;
    }

    // Exclude delivery, service, and other non-eligible items
    // These items should not receive the yesterday item discount even if they have the attribute
    const product = line.merchandise.product;
    const productTitle = (product.title || '').toLowerCase();
    const productType = (product.productType || '').toLowerCase();
    const variantTitle = (line.merchandise.title || '').toLowerCase();

    // Define exclusion patterns for non-eligible items
    // These are items that should never receive discounts:
    // - Delivery and shipping services
    // - Service fees and tips
    // - Gift cards and store credits
    // - Existing discounts and special offers
    const exclusionPatterns = [
      // Delivery and shipping
      'delivery', 'lieferung', 'versand', 'shipping', 'transport',
      // Service items and fees
      'service', 'fee', 'gebühr', 'tip', 'trinkgeld',
      // Gift cards and credits
      'gift card', 'geschenkkarte', 'store credit', 'gutschein',
      // Discounts and special offers
      'discount', 'rabatt', 'sale',
      // Deposit items (German bottle deposit law - Pfand is not discountable)
      'pfand', 'deposit'
    ];

    // Check if any exclusion pattern matches in title, type, or variant title
    // If a match is found, the item is excluded from the discount
    const isExcluded = exclusionPatterns.some(pattern => {
      return productTitle.includes(pattern) ||
             productType.includes(pattern) ||
             variantTitle.includes(pattern);
    });

    // Return true if item is eligible (not excluded)
    return !isExcluded;
  });
}

/**
 * Check if cart line is a yesterday item eligible for 50% discount
 *
 * Yesterday items are identified by a single line attribute: yesterday_item="Y"
 * This attribute is set when items are added to cart from yesterday's inventory
 *
 * @param {Object} line - Cart line with attribute fields from GraphQL query
 * @returns {boolean} True if eligible for 50% yesterday item discount
 */
function isYesterdayItem(line) {
  // Extract yesterday_item attribute value from GraphQL response
  // The attribute is queried as yesterdayItemAttribute in the GraphQL query
  const yesterdayItemValue = line.yesterdayItemAttribute?.value;

  // DEBUG: Log line attributes for troubleshooting
  console.log('DEBUG: Checking line for yesterday item eligibility:', line.id);
  console.log('  yesterdayItemAttribute:', yesterdayItemValue);

  // Attribute must be present and equal "Y" (case-insensitive for robustness)
  // Examples: "Y", "y" both pass, but "N", "n", "", null, undefined fail
  if (!yesterdayItemValue) {
    console.log('DEBUG: yesterday_item attribute missing - not eligible');
    return false;
  }

  // Check if the attribute value is "Y" (case-insensitive)
  // This allows for flexibility in how the attribute is set
  const isEligible = yesterdayItemValue.toUpperCase() === 'Y';
  console.log('DEBUG: yesterday_item eligibility result:', isEligible);
  return isEligible;
}

/**
 * Generate discount operations for yesterday items
 *
 * Applies 50% discount to all provided cart lines
 * Each line's full quantity receives the discount
 *
 * @param {Array} yesterdayItems - Cart lines eligible for yesterday item discount
 * @returns {Array} Array of discount operations to be applied by Shopify
 */
function generateYesterdayItemDiscountOperations(yesterdayItems) {
  const candidates = [];

  // Create discount candidate for each yesterday item
  // Each candidate specifies:
  // - message: What the customer sees in cart/checkout
  // - targets: Which cart line(s) and quantities to discount
  // - value: The discount percentage (50%)
  for (const line of yesterdayItems) {
    console.log('DEBUG: Adding 50% yesterday item discount for line:', line.id, 'quantity:', line.quantity);

    candidates.push({
      // Message displayed to customer in cart/checkout
      // German: "50% discount on items from the previous day"
      message: "50% Rabatt auf Artikel vom Vortag",
      // Target all quantities in this line
      // The quantity ensures the entire line receives the discount
      targets: [{
        cartLine: {
          id: line.id,
          quantity: line.quantity,
        },
      }],
      // Apply 50% discount
      // This reduces the price by half
      value: {
        percentage: { value: 50 },
      },
    });
  }

  console.log('DEBUG: Total yesterday item discount candidates generated:', candidates.length);

  // Return empty array if no discount candidates were generated
  // This handles edge cases where all items might have been filtered out
  if (candidates.length === 0) {
    return [];
  }

  // Return single operation with all discount candidates
  // Using FIRST selection strategy ensures all non-conflicting discounts are applied
  // If Shopify detects a conflict (e.g., another discount on the same item),
  // it will apply whichever discount runs first
  return [{
    productDiscountsAdd: {
      candidates,
      selectionStrategy: ProductDiscountSelectionStrategy.First,
    },
  }];
}
