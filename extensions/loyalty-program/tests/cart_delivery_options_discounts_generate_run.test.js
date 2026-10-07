import {describe, it, expect} from "vitest";

import {cartDeliveryOptionsDiscountsGenerateRun} from "../src/cart_delivery_options_discounts_generate_run";
import {
  DeliveryDiscountSelectionStrategy,
  DiscountClass,
} from "../generated/api";

/**
  * @typedef {import("../generated/api").CartDeliveryOptionsDiscountsGenerateRunResult} CartDeliveryOptionsDiscountsGenerateRunResult
  * @typedef {import("../generated/api").DeliveryInput} DeliveryInput
  */

describe("cartDeliveryOptionsDiscountsGenerateRun", () => {
  const baseInput = {
    cart: {
      deliveryGroups: [
        {
          id: "gid://shopify/DeliveryGroup/0",
        },
      ],
    },
    discount: {
      discountClasses: [],
    },
  };

  it("returns empty operations when no discount classes are present", () => {
    const input = {
      ...baseInput,
      discount: {
        discountClasses: [],
      },
    };

    const result = cartDeliveryOptionsDiscountsGenerateRun(input);
    expect(result.operations).toHaveLength(0);
  });

  it("returns delivery discount when shipping discount class is present", () => {
    const input = {
      ...baseInput,
      discount: {
        discountClasses: [DiscountClass.Shipping],
      },
    };

    const result = cartDeliveryOptionsDiscountsGenerateRun(input);
    expect(result.operations).toHaveLength(1);
    expect(result.operations[0]).toMatchObject({
      deliveryDiscountsAdd: {
        candidates: [
          {
            message: "FREE DELIVERY",
            targets: [
              {
                deliveryGroup: {
                  id: "gid://shopify/DeliveryGroup/0",
                },
              },
            ],
            value: {
              percentage: {
                value: 100,
              },
            },
          },
        ],
        selectionStrategy: DeliveryDiscountSelectionStrategy.All,
      },
    });
  });

  it("throws error when no delivery groups are present", () => {
    const input = {
      cart: {
        deliveryGroups: [],
      },
      discount: {
        discountClasses: [DiscountClass.Shipping],
      },
    };

    expect(() => cartDeliveryOptionsDiscountsGenerateRun(input)).toThrow(
      "No delivery groups found",
    );
  });

  // This helper is not configured as a deployed target. Preserve its existing
  // first-group behavior so regenerating the shared API types cannot change it.
  it("discounts only the first delivery group when several groups exist", () => {
    const result = cartDeliveryOptionsDiscountsGenerateRun({
      cart: {deliveryGroups: [{id: "first"}, {id: "second"}]},
      discount: {discountClasses: [DiscountClass.Shipping]},
    });
    expect(result.operations[0].deliveryDiscountsAdd.candidates[0].targets).toEqual([
      {deliveryGroup: {id: "first"}},
    ]);
  });
});
