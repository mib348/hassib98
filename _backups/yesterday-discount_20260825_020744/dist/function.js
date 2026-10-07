// node_modules/@shopify/shopify_function/run.ts
function run_default(userfunction) {
  try {
    ShopifyFunction;
  } catch (e) {
    throw new Error(
      "ShopifyFunction is not defined. Please rebuild your function using the latest version of Shopify CLI."
    );
  }
  const input_obj = ShopifyFunction.readInput();
  const output_obj = userfunction(input_obj);
  ShopifyFunction.writeOutput(output_obj);
}

// extensions/yesterday-discount/src/cart_lines_discounts_generate_run.js
function cartLinesDiscountsGenerateRun(input) {
  try {
    if (!input?.cart || !input.cart.lines.length) {
      console.error("No cart data or cart lines provided to yesterday items discount function");
      return { operations: [] };
    }
    const hasProductDiscountClass = input.discount?.discountClasses?.includes(
      "PRODUCT" /* Product */
    );
    if (!hasProductDiscountClass) {
      console.log("Product discount class not enabled - skipping yesterday items discount");
      return { operations: [] };
    }
    const customer = input.cart.buyerIdentity?.customer;
    if (customer) {
      const loyaltyEligibility = checkLoyaltyEligibility(customer);
      if (loyaltyEligibility.isActive && loyaltyEligibility.hasFreeItems) {
        console.log(`Customer ${customer.email} eligible for 100% loyalty discount - skipping 50% yesterday discount`);
        console.log(`  Loyalty status: ${loyaltyEligibility.status}, Free items: ${loyaltyEligibility.freeItems}`);
        return { operations: [] };
      }
      console.log(`Customer ${customer.email} not eligible for loyalty discount - proceeding with 50% yesterday discount`);
    } else {
      console.log("Guest customer - proceeding with 50% yesterday discount");
    }
    const yesterdayItems = getYesterdayEligibleCartLines(input.cart.lines);
    if (yesterdayItems.length === 0) {
      console.log("No yesterday items found in cart - skipping discount");
      return { operations: [] };
    }
    const operations = generateYesterdayItemDiscountOperations(yesterdayItems);
    console.log(`Applied 50% discount to ${yesterdayItems.length} yesterday items`);
    return {
      operations
    };
  } catch (error) {
    console.error("Error in yesterday items discount function:", error);
    return { operations: [] };
  }
}
function checkLoyaltyEligibility(customer) {
  let status = null;
  if (customer.customLoyaltyStatus !== void 0) {
    status = customer.customLoyaltyStatus?.value || null;
  } else if (customer.loyaltyStatus !== void 0) {
    status = customer.loyaltyStatus?.value || null;
  }
  let freeItems = 0;
  if (customer.customLoyaltyFreeItems !== void 0) {
    freeItems = parseInt(customer.customLoyaltyFreeItems?.value || "0") || 0;
  } else if (customer.loyaltyFreeItems !== void 0) {
    freeItems = parseInt(customer.loyaltyFreeItems?.value || "0") || 0;
  }
  const isActive = status === "active";
  const hasFreeItems = freeItems > 0;
  return {
    isActive,
    hasFreeItems,
    status,
    freeItems
  };
}
function getYesterdayEligibleCartLines(cartLines) {
  return cartLines.filter((line) => {
    if (line.merchandise.__typename !== "ProductVariant") {
      return false;
    }
    if (!isYesterdayItem(line)) {
      return false;
    }
    const product = line.merchandise.product;
    const productTitle = (product.title || "").toLowerCase();
    const productType = (product.productType || "").toLowerCase();
    const variantTitle = (line.merchandise.title || "").toLowerCase();
    const exclusionPatterns = [
      // Delivery and shipping
      "delivery",
      "lieferung",
      "versand",
      "shipping",
      "transport",
      // Service items and fees
      "service",
      "fee",
      "geb\xFChr",
      "tip",
      "trinkgeld",
      // Gift cards and credits
      "gift card",
      "geschenkkarte",
      "store credit",
      "gutschein",
      // Discounts and special offers
      "discount",
      "rabatt",
      "sale",
      // Deposit items (German bottle deposit law - Pfand is not discountable)
      "pfand",
      "deposit"
    ];
    const isExcluded = exclusionPatterns.some((pattern) => {
      return productTitle.includes(pattern) || productType.includes(pattern) || variantTitle.includes(pattern);
    });
    return !isExcluded;
  });
}
function isYesterdayItem(line) {
  const yesterdayItemValue = line.yesterdayItemAttribute?.value;
  console.log("DEBUG: Checking line for yesterday item eligibility:", line.id);
  console.log("  yesterdayItemAttribute:", yesterdayItemValue);
  if (!yesterdayItemValue) {
    console.log("DEBUG: yesterday_item attribute missing - not eligible");
    return false;
  }
  const isEligible = yesterdayItemValue.toUpperCase() === "Y";
  console.log("DEBUG: yesterday_item eligibility result:", isEligible);
  return isEligible;
}
function generateYesterdayItemDiscountOperations(yesterdayItems) {
  const candidates = [];
  for (const line of yesterdayItems) {
    console.log("DEBUG: Adding 50% yesterday item discount for line:", line.id, "quantity:", line.quantity);
    candidates.push({
      // Message displayed to customer in cart/checkout
      // German: "50% discount on items from the previous day"
      message: "50% Rabatt auf Artikel vom Vortag",
      // Target all quantities in this line
      // The quantity ensures the entire line receives the discount
      targets: [{
        cartLine: {
          id: line.id,
          quantity: line.quantity
        }
      }],
      // Apply 50% discount
      // This reduces the price by half
      value: {
        percentage: { value: 50 }
      }
    });
  }
  console.log("DEBUG: Total yesterday item discount candidates generated:", candidates.length);
  if (candidates.length === 0) {
    return [];
  }
  return [{
    productDiscountsAdd: {
      candidates,
      selectionStrategy: "FIRST" /* First */
    }
  }];
}

// <stdin>
function cartLinesDiscountsGenerateRun2() {
  return run_default(cartLinesDiscountsGenerateRun);
}
export {
  cartLinesDiscountsGenerateRun2 as cartLinesDiscountsGenerateRun
};
