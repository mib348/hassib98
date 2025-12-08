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
    const freeItemsAvailable = getFreeItemsAvailable(loyaltyMetafields);

    // Find eligible cart items for loyalty discounts
    const eligibleCartLines = getEligibleCartLines(input.cart.lines);

    if (eligibleCartLines.length === 0) {
      // No eligible items in cart
      return { operations: [] };
    }

    // Generate loyalty discount operations (100% discount on cheapest items)
    const operations = generateLoyaltyDiscountOperations(
      eligibleCartLines,
      freeItemsAvailable
    );

    console.log(`Applied ${operations.length} discount operations for customer ${customer.email}`);

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
 * Generate loyalty discount operations for eligible items
 * Applies 100% discount to the cheapest eligible items based on free items available
 *
 * @param {Array} eligibleLines - All cart lines eligible for loyalty discounts
 * @param {number} freeItemsCount - Number of free items customer gets from loyalty program
 * @returns {Array} Array of discount operations with loyalty discounts
 */
function generateLoyaltyDiscountOperations(eligibleLines, freeItemsCount) {
  // Return empty operations if no free items available
  if (freeItemsCount === 0) {
    console.log('DEBUG: No free items available - skipping loyalty discounts');
    return [];
  }

  const candidates = [];

  // DEBUG: Log inputs to discount generation
  console.log('DEBUG: generateLoyaltyDiscountOperations called with:');
  console.log('  eligibleLines:', eligibleLines.length);
  console.log('  freeItemsCount:', freeItemsCount);

  // Sort all eligible items by price (cheapest first) to maximize customer value
  // This ensures the lowest-priced items get the 100% discount
  const sortedLines = [...eligibleLines].sort((a, b) => {
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

    remainingFreeItems -= quantityToDiscount;
  }

  console.log('DEBUG: Total loyalty discount candidates generated:', candidates.length);

  // Return empty array if no discount candidates were generated
  if (candidates.length === 0) {
    return [];
  }

  // Return single operation with all discount candidates
  // Using FIRST selection strategy ensures all non-conflicting discounts are applied
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
