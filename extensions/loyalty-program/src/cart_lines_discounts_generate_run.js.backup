/**
 * Shopify Function: Loyalty Program Automatic Discounts
 *
 * This function automatically applies "Buy 4 Get 1 Free" discounts for loyalty program members.
 * It reads customer loyalty metafields and applies 100% discounts to eligible items
 * without requiring customers to enter discount codes manually.
 *
 * The function:
 * 1. Checks if the customer is a loyalty program member
 * 2. Reads their free item eligibility from metafields
 * 3. Identifies eligible cart items (excluding delivery/service items)
 * 4. Applies 100% discounts to the cheapest eligible items
 * 5. Returns discount operations for Shopify to apply
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
 * @param {RunInput} input - Cart and customer data from GraphQL query
 * @returns {CartLinesDiscountsGenerateRunResult} Discount operations configuration
 */
export function cartLinesDiscountsGenerateRun(input) {
  try {
    // Validate input structure
    if (!input?.cart || !input.cart.lines.length) {
      console.error('No cart data or cart lines provided to loyalty discount function');
      return { operations: [] };
    }

    // Check if product discount class is enabled for this discount
    const hasProductDiscountClass = input.discount?.discountClasses?.includes(
      DiscountClass.Product,
    );

    if (!hasProductDiscountClass) {
      // Product discount class not enabled, cannot apply loyalty discounts
      return { operations: [] };
    }

    // Extract customer information from cart
    const customer = input.cart.buyerIdentity?.customer;

    if (!customer) {
      // No customer logged in - no loyalty discounts available
      return { operations: [] };
    }

    // Get customer loyalty metafields from individual metafield queries
    const loyaltyMetafields = extractLoyaltyMetafields(customer);

    // Check if customer is an active loyalty member
    if (!isActiveLoyaltyMember(loyaltyMetafields)) {
      return { operations: [] };
    }

    // Get number of free items the customer is eligible for
    // Note: Even if free_items = 0, we still need to check for yesterday item discounts
    const freeItemsAvailable = getFreeItemsAvailable(loyaltyMetafields);

    // Find eligible cart items for loyalty discounts
    const eligibleCartLines = getEligibleCartLines(input.cart.lines);

    if (eligibleCartLines.length === 0) {
      // No eligible items in cart
      return { operations: [] };
    }

    // Separate eligible items into yesterday items and regular groups
    // Yesterday items: Have line property yesterday_item="Y"
    // These can still get 100% loyalty discount (better discount wins strategy)
    // But if not covered by loyalty, they'll get 50% discount as fallback
    const yesterdayItems = [];
    const regularEligibleLines = [];

    eligibleCartLines.forEach(line => {
      if (isYesterdayItem(line)) {
        yesterdayItems.push(line);
      } else {
        regularEligibleLines.push(line);
      }
    });

    // DEBUG: Log yesterday item detection results
    console.log('DEBUG: Eligible cart lines:', eligibleCartLines.length);
    console.log('DEBUG: Yesterday items:', yesterdayItems.length);
    console.log('DEBUG: Regular eligible lines:', regularEligibleLines.length);

    // Generate combined discount operations using "better discount wins" strategy:
    // 1. Apply 100% loyalty discount to cheapest items (includes yesterday items)
    // 2. Apply 50% discount to remaining yesterday items not covered by loyalty
    const operations = generateCombinedDiscountOperations(
      eligibleCartLines,        // All eligible items (loyalty can apply to any)
      freeItemsAvailable,       // Number of free items from loyalty program
      yesterdayItems            // Track which items need 50% fallback discount
    );

    console.log(`Applied ${operations.length} discount operations for customer ${customer.email}`);
    if (yesterdayItems.length > 0) {
      console.log(`Yesterday items found: ${yesterdayItems.length}`);
    }

    return {
      operations,
    };

  } catch (error) {
    console.error('Error in loyalty discount function:', error);
    // Return empty operations on error to avoid breaking checkout
    return { operations: [] };
  }
}

/**
 * Extract loyalty-related metafields from customer object
 * Handles both proper loyalty namespace and admin-created custom namespace formats
 * The GraphQL query returns individual metafield queries with aliases
 * @param {Object} customer - Customer object with metafield properties
 * @returns {Object} Object containing loyalty metafield values
 */
function extractLoyaltyMetafields(customer) {
  const loyaltyData = {
    status: null,
    points: 0,
    freeItems: 0,
    tier: 'bronze',
    itemsPurchased: 0,
    lifetimePoints: 0
  };

  // DEBUG: Log raw metafield values from GraphQL
  console.log('DEBUG: Raw metafields from GraphQL:');
  console.log('  custom.loyalty_status:', customer.customLoyaltyStatus?.value);
  console.log('  custom.loyalty_free_items:', customer.customLoyaltyFreeItems?.value);
  console.log('  loyalty.status:', customer.loyaltyStatus?.value);
  console.log('  loyalty.free_items:', customer.loyaltyFreeItems?.value);

  // Extract from custom namespace metafields (preferred format - actively used)
  // These are created when merchants use Shopify admin to create metafields
  // Check if custom namespace metafield EXISTS (not just if it has a truthy value)
  // This is critical: customLoyaltyFreeItems with value "0" should be used, not overwritten
  if (customer.customLoyaltyStatus !== undefined) {
    loyaltyData.status = customer.customLoyaltyStatus?.value || null;
    console.log('DEBUG: Using custom.loyalty_status =', loyaltyData.status);
  } else if (customer.loyaltyStatus !== undefined) {
    // Fall back to loyalty namespace ONLY if custom namespace doesn't exist at all
    loyaltyData.status = customer.loyaltyStatus?.value || null;
    console.log('DEBUG: Fallback to loyalty.status =', loyaltyData.status);
  }

  if (customer.customLoyaltyFreeItems !== undefined) {
    // Parse the value, even if it's "0" - this is intentional and should not be overwritten
    loyaltyData.freeItems = parseInt(customer.customLoyaltyFreeItems?.value || '0') || 0;
    console.log('DEBUG: Using custom.loyalty_free_items =', loyaltyData.freeItems);
  } else if (customer.loyaltyFreeItems !== undefined) {
    // Fall back to loyalty namespace ONLY if custom namespace doesn't exist at all
    loyaltyData.freeItems = parseInt(customer.loyaltyFreeItems?.value || '0') || 0;
    console.log('DEBUG: Fallback to loyalty.free_items =', loyaltyData.freeItems);
  }

  console.log('DEBUG: Final loyaltyData.freeItems =', loyaltyData.freeItems);

  return loyaltyData;
}

/**
 * Check if customer is an active loyalty program member
 * @param {Object} loyaltyData - Extracted loyalty metafield values
 * @returns {boolean} True if customer is active loyalty member
 */
function isActiveLoyaltyMember(loyaltyData) {
  return loyaltyData.status === 'active';
}

/**
 * Get number of free items customer is eligible for
 * @param {Object} loyaltyData - Extracted loyalty metafield values
 * @returns {number} Number of free items available
 */
function getFreeItemsAvailable(loyaltyData) {
  return Math.max(0, loyaltyData.freeItems);
}

/**
 * Calculate yesterday's date from shop's current local date
 * IMPORTANT: Cannot use new Date() in Shopify Functions - returns epoch time (1970-01-01)
 * Must use shop.localTime.date from GraphQL query which provides the store's current date
 * in its local timezone, ensuring accurate date comparisons for immediate inventory discounts
 * @param {string} currentDate - ISO date string (YYYY-MM-DD) from shop.localTime.date
 * @returns {string} Yesterday's date in YYYY-MM-DD format
 */
function getYesterdayDate(currentDate) {
  // Parse the ISO date string (YYYY-MM-DD) into year, month, day components
  const [year, month, day] = currentDate.split('-').map(Number);

  // Create Date object from parsed values (month is 0-indexed in JavaScript)
  const date = new Date(year, month - 1, day);

  // Subtract one day (86400000 milliseconds = 24 hours)
  date.setTime(date.getTime() - 86400000);

  // Format back to YYYY-MM-DD string with zero-padding
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');

  return `${y}-${m}-${d}`;
}

/**
 * Normalize DD-MM-YYYY date format to YYYY-MM-DD for comparison
 * Handles the date format used in line item properties and converts it to ISO format
 * for consistent date comparison with yesterday's calculated date
 * @param {string} dateStr - Date in DD-MM-YYYY format (e.g., "04-12-2025")
 * @returns {string} Date in YYYY-MM-DD format (e.g., "2025-12-04")
 */
function normalizeDateString(dateStr) {
  // Handle DD-MM-YYYY format (user specified format with dashes)
  // Example: "04-12-2025" becomes "2025-12-04"
  if (/^\d{2}-\d{2}-\d{4}$/.test(dateStr)) {
    const [day, month, year] = dateStr.split('-');
    return `${year}-${month}-${day}`;
  }

  // Already in ISO format (YYYY-MM-DD) - return as-is
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    return dateStr;
  }

  // Unknown format - log warning and return as-is (will fail date comparison)
  console.warn(`Unexpected date format: ${dateStr}. Expected DD-MM-YYYY or YYYY-MM-DD`);
  return dateStr;
}

/**
 * Check if cart line is a yesterday item eligible for 50% discount
 * Yesterday items are identified by a single line property: yesterday_item="Y"
 * This is simpler than date-based tracking and more reliable
 * @param {Object} line - Cart line with attribute fields from GraphQL query
 * @returns {boolean} True if eligible for 50% yesterday item discount
 */
function isYesterdayItem(line) {
  // Extract yesterday_item attribute value from GraphQL response
  const yesterdayItemValue = line.yesterdayItemAttribute?.value;

  // DEBUG: Log line attributes
  console.log('DEBUG: Checking line for yesterday item eligibility:', line.id);
  console.log('  yesterdayItemAttribute:', yesterdayItemValue);

  // Attribute must be present and equal "Y" (case-insensitive for robustness)
  // Examples: "Y", "y" both pass, but "N", "n", "", null, undefined fail
  if (!yesterdayItemValue) {
    console.log('DEBUG: yesterday_item attribute missing - not eligible');
    return false;
  }

  const isEligible = yesterdayItemValue.toUpperCase() === 'Y';
  console.log('DEBUG: yesterday_item eligibility result:', isEligible);
  return isEligible;
}

/**
 * Filter cart lines to find items eligible for loyalty discounts
 * Excludes delivery, shipping, and service items
 * @param {Array} cartLines - Array of cart line items
 * @returns {Array} Array of eligible cart lines
 */
function getEligibleCartLines(cartLines) {
  return cartLines.filter(line => {
    // Only process ProductVariant merchandise
    if (line.merchandise.__typename !== 'ProductVariant') {
      return false;
    }

    const product = line.merchandise.product;
    const productTitle = (product.title || '').toLowerCase();
    const productType = (product.productType || '').toLowerCase();
    const variantTitle = (line.merchandise.title || '').toLowerCase();

    // Define exclusion patterns for non-eligible items
    const exclusionPatterns = [
      // Delivery and shipping
      'delivery', 'lieferung', 'versand', 'shipping', 'transport',
      // Service items and fees
      'service', 'fee', 'gebühr', 'tip', 'trinkgeld',
      // Gift cards and credits
      'gift card', 'geschenkkarte', 'store credit', 'gutschein',
      // Discounts and special offers
      'discount', 'rabatt', 'sale'
    ];

    // Check if any exclusion pattern matches in title, type, or variant title
    const isExcluded = exclusionPatterns.some(pattern => {
      return productTitle.includes(pattern) ||
             productType.includes(pattern) ||
             variantTitle.includes(pattern);
    });

    return !isExcluded;
  });
}

/**
 * Generate combined discount operations following "better discount wins" strategy
 * This implements a two-tier discount system:
 * - Priority 1: Apply 100% loyalty discount to cheapest items (includes yesterday items)
 * - Priority 2: Apply 50% discount to remaining yesterday items not covered by loyalty
 * The strategy ensures customers always get the best possible discount on each item
 *
 * CRITICAL: Handles lines with quantity > 1 correctly by tracking quantities, not just line IDs
 * Example: If line has quantity=2 and gets 1x 100% discount, the remaining 1 can still get 50% discount
 *
 * @param {Array} allEligibleLines - All cart lines eligible for discounts (includes yesterday items)
 * @param {number} freeItemsCount - Number of free items customer gets from loyalty program
 * @param {Array} yesterdayItems - Lines with yesterday_item="Y" property
 * @returns {Array} Array of discount operations with both loyalty and yesterday item discounts
 */
function generateCombinedDiscountOperations(allEligibleLines, freeItemsCount, yesterdayItems) {
  const candidates = [];
  // Track how many items in each line received 100% discount
  // This is CRITICAL for handling lines with quantity > 1
  // Map<lineId, quantityWithLoyaltyDiscount>
  const loyaltyDiscountedQuantities = new Map();

  // DEBUG: Log inputs to discount generation
  console.log('DEBUG: generateCombinedDiscountOperations called with:');
  console.log('  allEligibleLines:', allEligibleLines.length);
  console.log('  freeItemsCount:', freeItemsCount);
  console.log('  yesterdayItems:', yesterdayItems.length);

  // PART 1: Apply 100% loyalty discounts to cheapest eligible items
  // This follows existing loyalty program logic: "Buy 4 Get 1 Free"
  // Yesterday items CAN receive this discount if they're among the cheapest
  if (freeItemsCount > 0) {
    console.log('DEBUG: Applying 100% loyalty discounts (freeItemsCount > 0)');
    // Sort all eligible items by price (cheapest first) to maximize customer value
    // This ensures the lowest-priced items get the 100% discount regardless of type
    const sortedLines = [...allEligibleLines].sort((a, b) => {
      const priceA = parseFloat(a.cost.amountPerQuantity.amount);
      const priceB = parseFloat(b.cost.amountPerQuantity.amount);
      return priceA - priceB;
    });

    let remainingFreeItems = freeItemsCount;

    // Iterate through sorted cart lines and apply loyalty discounts up to free items limit
    for (const line of sortedLines) {
      if (remainingFreeItems === 0) break;

      const lineQuantity = line.quantity;
      // Discount either all items in this line, or remaining free items (whichever is smaller)
      const quantityToDiscount = Math.min(remainingFreeItems, lineQuantity);

      console.log('DEBUG: Adding 100% loyalty discount for line:', line.id, 'quantity:', quantityToDiscount, '/', lineQuantity);

      candidates.push({
        // Message displayed to customer in cart/checkout (German for consistency)
        message: "Treueprogramm: Kauf 4, erhalte 1 gratis",
        // Target specific quantity within cart line (not entire line)
        targets: [{
          cartLine: {
            id: line.id,
            quantity: quantityToDiscount,
          },
        }],
        // Apply 100% discount (make items free)
        value: {
          percentage: { value: 100 },
        },
      });

      // Track QUANTITY that received 100% discount (not just the line ID)
      // This is critical for lines with quantity > 1
      loyaltyDiscountedQuantities.set(line.id, quantityToDiscount);
      remainingFreeItems -= quantityToDiscount;
    }
  } else {
    console.log('DEBUG: Skipping 100% loyalty discounts (freeItemsCount = 0)');
  }

  // PART 2: Apply 50% discount to yesterday items NOT covered by loyalty
  // These are items with yesterday_item="Y" property
  // Only apply if they didn't already receive the better 100% loyalty discount
  console.log('DEBUG: Processing 50% yesterday item discounts');
  console.log('DEBUG: loyaltyDiscountedQuantities:', Object.fromEntries(loyaltyDiscountedQuantities));

  for (const line of yesterdayItems) {
    console.log('DEBUG: Checking yesterday item line:', line.id, 'quantity:', line.quantity);

    // Check how many items in this line already got 100% loyalty discount
    const loyaltyQuantity = loyaltyDiscountedQuantities.get(line.id) || 0;
    // Calculate how many items still need 50% discount
    const remainingQuantity = line.quantity - loyaltyQuantity;

    console.log('DEBUG:   loyaltyQuantity:', loyaltyQuantity, ', remainingQuantity:', remainingQuantity);

    // Skip if all items in this line already got 100% loyalty discount (better discount wins)
    if (remainingQuantity <= 0) {
      console.log('DEBUG: Skipping line (all items already have 100% discount):', line.id);
      continue;
    }

    console.log('DEBUG: Adding 50% yesterday item discount for line:', line.id, 'quantity:', remainingQuantity);

    candidates.push({
      // Message displayed to customer in cart/checkout
      // German: "50% discount on items from the previous day"
      message: "50% Rabatt auf Artikel vom Vortag",
      // Target only the remaining quantity that didn't get 100% discount
      targets: [{
        cartLine: {
          id: line.id,
          quantity: remainingQuantity,
        },
      }],
      // Apply 50% discount
      value: {
        percentage: { value: 50 },
      },
    });
  }

  console.log('DEBUG: Total candidates generated:', candidates.length);

  // Return empty array if no discount candidates were generated
  if (candidates.length === 0) {
    return [];
  }

  // Return single operation with all discount candidates
  // Using FIRST selection strategy ensures all non-conflicting discounts are applied
  // Shopify automatically handles any conflicts (won't apply both discounts to same item)
  return [{
    productDiscountsAdd: {
      candidates,
      selectionStrategy: ProductDiscountSelectionStrategy.First,
    },
  }];
}

/**
 * Development and testing helper - logs function execution details
 * This function provides detailed logging for debugging and monitoring
 * @param {Object} input - Function input data
 * @param {Array} operations - Generated discount operations
 */
function logFunctionExecution(input, operations) {
  const customer = input.cart?.buyerIdentity?.customer;

  console.log('Loyalty Discount Function Execution:', {
    customerId: customer?.id,
    customerEmail: customer?.email,
    cartLineCount: input.cart?.lines?.length || 0,
    operationsGenerated: operations.length,
    timestamp: new Date().toISOString()
  });

  if (operations.length > 0) {
    operations.forEach(op => {
      if (op.productDiscountsAdd) {
        console.log('Product discounts candidates:', op.productDiscountsAdd.candidates.map(c => ({
          target: c.targets[0]?.cartLine?.id,
          percentage: c.value.percentage.value,
          message: c.message
        })));
      }
    });
  }
}
