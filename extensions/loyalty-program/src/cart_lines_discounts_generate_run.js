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

    // Get shop's current date for immediate inventory discount calculations
    // CRITICAL: Cannot use new Date() in Shopify Functions - returns epoch time (1970-01-01)
    // Must use shop.localTime.date from GraphQL query for accurate date in store's timezone
    const shopCurrentDate = input.shop?.localTime?.date;

    if (!shopCurrentDate) {
      console.error('Shop localTime.date not available - immediate inventory discounts disabled');
    }

    // Calculate yesterday's date for immediate inventory eligibility checks
    // This will be used to identify items with yesterday's date that qualify for 50% discount
    const yesterdayDate = shopCurrentDate ? getYesterdayDate(shopCurrentDate) : null;

    // Get customer loyalty metafields from individual metafield queries
    const loyaltyMetafields = extractLoyaltyMetafields(customer);

    // Check if customer is an active loyalty member
    if (!isActiveLoyaltyMember(loyaltyMetafields)) {
      return { operations: [] };
    }

    // Get number of free items the customer is eligible for
    // Note: Even if free_items = 0, we still need to check for immediate inventory discounts
    const freeItemsAvailable = getFreeItemsAvailable(loyaltyMetafields);

    // Find eligible cart items for loyalty discounts
    const eligibleCartLines = getEligibleCartLines(input.cart.lines);

    if (eligibleCartLines.length === 0) {
      // No eligible items in cart
      return { operations: [] };
    }

    // Separate eligible items into immediate inventory and regular groups
    // Immediate inventory items: Have yesterday's date + immediate_inventory="Y"
    // These can still get 100% loyalty discount (better discount wins strategy)
    // But if not covered by loyalty, they'll get 50% discount as fallback
    const immediateInventoryLines = [];
    const regularEligibleLines = [];

    eligibleCartLines.forEach(line => {
      if (yesterdayDate && isEligibleForImmediateInventoryDiscount(line, yesterdayDate)) {
        immediateInventoryLines.push(line);
      } else {
        regularEligibleLines.push(line);
      }
    });

    // DEBUG: Log immediate inventory detection results
    console.log('DEBUG: Shop current date:', shopCurrentDate);
    console.log('DEBUG: Yesterday date:', yesterdayDate);
    console.log('DEBUG: Eligible cart lines:', eligibleCartLines.length);
    console.log('DEBUG: Immediate inventory lines:', immediateInventoryLines.length);
    console.log('DEBUG: Regular eligible lines:', regularEligibleLines.length);

    // Generate combined discount operations using "better discount wins" strategy:
    // 1. Apply 100% loyalty discount to cheapest items (includes immediate inventory items)
    // 2. Apply 50% discount to remaining immediate inventory items not covered by loyalty
    const operations = generateCombinedDiscountOperations(
      eligibleCartLines,        // All eligible items (loyalty can apply to any)
      freeItemsAvailable,       // Number of free items from loyalty program
      immediateInventoryLines   // Track which items need 50% fallback discount
    );

    console.log(`Applied ${operations.length} discount operations for customer ${customer.email}`);
    if (immediateInventoryLines.length > 0) {
      console.log(`Immediate inventory items found: ${immediateInventoryLines.length}`);
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
 * Check if cart line is eligible for 50% immediate inventory discount
 * Item must have all three line item properties:
 * - date: Must match yesterday's date (in DD-MM-YYYY format)
 * - day: Must be present (validates date is intentional, not missing)
 * - immediate_inventory: Must equal "Y" (case-insensitive)
 * @param {Object} line - Cart line with attribute fields from GraphQL query
 * @param {string} yesterdayDate - Yesterday's date in YYYY-MM-DD format
 * @returns {boolean} True if eligible for 50% immediate inventory discount
 */
function isEligibleForImmediateInventoryDiscount(line, yesterdayDate) {
  // Extract line item attribute values from GraphQL response
  const dateValue = line.dateAttribute?.value;
  const daynameValue = line.daynameAttribute?.value;
  const immediateInventoryValue = line.immediateInventoryAttribute?.value;

  // DEBUG: Log line attributes
  console.log('DEBUG: Checking line for immediate inventory eligibility:', line.id);
  console.log('  dateAttribute:', dateValue);
  console.log('  daynameAttribute:', daynameValue);
  console.log('  immediateInventoryAttribute:', immediateInventoryValue);
  console.log('  yesterdayDate:', yesterdayDate);

  // All three attributes must be present for eligibility
  // If any are missing, this is not an immediate inventory item
  if (!dateValue || !daynameValue || !immediateInventoryValue) {
    console.log('DEBUG: Missing required attributes - not eligible');
    return false;
  }

  // Check immediate_inventory flag must be "Y" (case-insensitive for robustness)
  // Examples: "Y", "y" both pass, but "N", "n", "" fail
  if (immediateInventoryValue.toUpperCase() !== 'Y') {
    console.log('DEBUG: immediate_inventory not "Y" - not eligible');
    return false;
  }

  // Normalize the date from line item property (DD-MM-YYYY) to ISO format (YYYY-MM-DD)
  // Then compare with yesterday's calculated date
  const normalizedDate = normalizeDateString(dateValue);
  console.log('DEBUG: Normalized date:', normalizedDate);
  const isEligible = normalizedDate === yesterdayDate;
  console.log('DEBUG: Date match result:', isEligible);
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
 * - Priority 1: Apply 100% loyalty discount to cheapest items (includes immediate inventory items)
 * - Priority 2: Apply 50% discount to remaining immediate inventory items not covered by loyalty
 * The strategy ensures customers always get the best possible discount on each item
 * @param {Array} allEligibleLines - All cart lines eligible for discounts (includes immediate inventory)
 * @param {number} freeItemsCount - Number of free items customer gets from loyalty program
 * @param {Array} immediateInventoryLines - Lines with immediate inventory flag (yesterday's date + immediate_inventory=Y)
 * @returns {Array} Array of discount operations with both loyalty and immediate inventory discounts
 */
function generateCombinedDiscountOperations(allEligibleLines, freeItemsCount, immediateInventoryLines) {
  const candidates = [];
  const discountedLineIds = new Set(); // Track which lines received 100% loyalty discount

  // DEBUG: Log inputs to discount generation
  console.log('DEBUG: generateCombinedDiscountOperations called with:');
  console.log('  allEligibleLines:', allEligibleLines.length);
  console.log('  freeItemsCount:', freeItemsCount);
  console.log('  immediateInventoryLines:', immediateInventoryLines.length);

  // PART 1: Apply 100% loyalty discounts to cheapest eligible items
  // This follows existing loyalty program logic: "Buy 4 Get 1 Free"
  // Immediate inventory items CAN receive this discount if they're among the cheapest
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

      console.log('DEBUG: Adding 100% loyalty discount for line:', line.id);

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

      // Track this line as receiving 100% loyalty discount
      // This prevents applying both discounts to the same item (better discount wins)
      discountedLineIds.add(line.id);
      remainingFreeItems -= quantityToDiscount;
    }
  } else {
    console.log('DEBUG: Skipping 100% loyalty discounts (freeItemsCount = 0)');
  }

  // PART 2: Apply 50% discount to immediate inventory items NOT covered by loyalty
  // These are items with yesterday's date and immediate_inventory="Y" flag
  // Only apply if they didn't already receive the better 100% loyalty discount
  console.log('DEBUG: Processing 50% immediate inventory discounts');
  console.log('DEBUG: discountedLineIds:', Array.from(discountedLineIds));

  for (const line of immediateInventoryLines) {
    console.log('DEBUG: Checking immediate inventory line:', line.id);
    // Skip if this item already got 100% loyalty discount (better discount wins)
    if (discountedLineIds.has(line.id)) {
      console.log('DEBUG: Skipping line (already has 100% discount):', line.id);
      continue;
    }

    console.log('DEBUG: Adding 50% immediate inventory discount for line:', line.id);

    candidates.push({
      // Message displayed to customer in cart/checkout
      // German: "50% discount on items from the previous day"
      message: "50% Rabatt auf Artikel vom Vortag",
      // Target the entire cart line quantity (all items in this line get 50% off)
      targets: [{
        cartLine: {
          id: line.id,
          quantity: line.quantity,
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
