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

    if (freeItemsAvailable === 0) {
      // Customer has no free items available
      return { operations: [] };
    }

    // Find eligible cart items for loyalty discounts
    const eligibleCartLines = getEligibleCartLines(input.cart.lines);

    if (eligibleCartLines.length === 0) {
      // No eligible items in cart
      return { operations: [] };
    }

    // Generate automatic discount operations for free items
    const operations = generateLoyaltyDiscountOperations(eligibleCartLines, freeItemsAvailable);

    console.log(`Applied ${operations.length} loyalty discount operations for customer ${customer.email}`);

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

  // Extract from loyalty namespace metafields (preferred format)
  if (customer.loyaltyStatus?.value) {
    loyaltyData.status = customer.loyaltyStatus.value;
  }
  if (customer.loyaltyFreeItems?.value) {
    loyaltyData.freeItems = parseInt(customer.loyaltyFreeItems.value) || 0;
  }

  // Fall back to custom namespace metafields if loyalty namespace not found
  // These are created when merchants use Shopify admin to create metafields
  if (!loyaltyData.status && customer.customLoyaltyStatus?.value) {
    loyaltyData.status = customer.customLoyaltyStatus.value;
  }
  if (loyaltyData.freeItems === 0 && customer.customLoyaltyFreeItems?.value) {
    loyaltyData.freeItems = parseInt(customer.customLoyaltyFreeItems.value) || 0;
  }

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
 * Applies 100% discount to the cheapest eligible items up to the free item limit
 * Uses modern cart lines discount operations format
 * @param {Array} eligibleLines - Cart lines eligible for loyalty discounts
 * @param {number} freeItemsCount - Number of free items to apply
 * @returns {Array} Array of discount operations
 */
function generateLoyaltyDiscountOperations(eligibleLines, freeItemsCount) {
  // Sort eligible items by price (cheapest first) to maximize customer value
  const sortedLines = [...eligibleLines].sort((a, b) => {
    const priceA = parseFloat(a.cost.amountPerQuantity.amount);
    const priceB = parseFloat(b.cost.amountPerQuantity.amount);
    return priceA - priceB;
  });

  // If no free items to apply, return empty array
  if (freeItemsCount === 0) {
    return [];
  }

  // Create candidates array for all free items
  // Track remaining free items to distribute across cart lines
  const candidates = [];
  let remainingFreeItems = freeItemsCount;

  // Iterate through sorted cart lines and apply discounts up to quantity limits
  for (const line of sortedLines) {
    // Stop if we've used all free items
    if (remainingFreeItems === 0) break;

    const lineQuantity = line.quantity;
    // Discount either all items in this line, or remaining free items (whichever is smaller)
    const quantityToDiscount = Math.min(remainingFreeItems, lineQuantity);

    candidates.push({
      // Message displayed to customer in cart/checkout
      message: "Treueprogramm: Kauf 4, erhalte 1 gratis",
      // Target specific quantity within cart line (CRITICAL: prevents discounting entire line)
      targets: [
        {
          cartLine: {
            id: line.id,
            quantity: quantityToDiscount,  // KEY FIX: Only discount this many items, not entire line
          },
        },
      ],
      // Apply 100% discount (make items free)
      value: {
        percentage: {
          value: 100,
        },
      },
    });

    // Reduce remaining free items by the quantity we just discounted
    remainingFreeItems -= quantityToDiscount;
  }

  // Return single operation with all candidates
  // Using FIRST selection strategy to ensure all loyalty discounts are applied
  return [
    {
      productDiscountsAdd: {
        candidates,
        selectionStrategy: ProductDiscountSelectionStrategy.First,
      },
    },
  ];
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
