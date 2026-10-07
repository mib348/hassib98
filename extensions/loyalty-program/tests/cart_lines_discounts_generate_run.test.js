import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

import {cartLinesDiscountsGenerateRun} from '../src/cart_lines_discounts_generate_run';
import {DiscountClass, ProductDiscountSelectionStrategy} from '../generated/api';

// Fixtures use the same aliases and money strings as the Function's GraphQL input.
// Keep mocked cart/customer data here in tests; production still reads Shopify data.
function cartLine(id, amount = '10.00', quantity = 1, product = {}) {
  return {
    id,
    quantity,
    cost: {amountPerQuantity: {amount}},
    merchandise: {
      __typename: 'ProductVariant',
      title: 'Default Title',
      product: {title: 'Salmon Maki', productType: 'Sushi', ...product},
    },
  };
}

function customer(freeItems = '1', status = 'active') {
  return {
    id: 'gid://shopify/Customer/1',
    email: 'member@example.test',
    customLoyaltyStatus: {value: status},
    customLoyaltyFreeItems: {value: freeItems},
    loyaltyStatus: null,
    loyaltyFreeItems: null,
  };
}

function input(lines = [cartLine('line-1')], member = customer(), classes = [DiscountClass.Product]) {
  return {
    cart: {lines, buyerIdentity: {customer: member}},
    discount: {discountClasses: classes},
  };
}

describe('cartLinesDiscountsGenerateRun loyalty rules', () => {
  beforeEach(() => {
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });
  afterEach(() => vi.restoreAllMocks());

  it.each([undefined, null, {}, {cart: {lines: []}}])('returns no discounts for absent or empty cart data (%j)', value => {
    expect(cartLinesDiscountsGenerateRun(value)).toEqual({operations: []});
  });

  it.each([[], [DiscountClass.Order], [DiscountClass.Shipping]].map(classes => [classes]))('requires the product discount class (%j)', classes => {
    expect(cartLinesDiscountsGenerateRun(input(undefined, undefined, classes))).toEqual({operations: []});
  });

  it('does not give guests loyalty discounts', () => {
    expect(cartLinesDiscountsGenerateRun(input(undefined, null))).toEqual({operations: []});
  });

  it.each(['inactive', 'paused', '', 'ACTIVE'])('requires the existing active membership value (%s)', status => {
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer('1', status)))).toEqual({operations: []});
  });

  it.each(['0', '-2', 'invalid', ''])('does not discount when free items are unavailable (%s)', freeItems => {
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer(freeItems)))).toEqual({operations: []});
  });

  it('discounts the cheapest quantities first and stops at the member allowance', () => {
    const value = input([
      cartLine('expensive', '12.50', 3),
      cartLine('cheapest', '4.00', 2),
      cartLine('middle', '8.00', 4),
    ], customer('3'));
    const original = JSON.stringify(value);

    expect(cartLinesDiscountsGenerateRun(value)).toEqual({operations: [{
      productDiscountsAdd: {
        candidates: [
          {message: 'Treueprogramm: Kauf 4, erhalte 1 gratis', targets: [{cartLine: {id: 'cheapest', quantity: 2}}], value: {percentage: {value: 100}}},
          {message: 'Treueprogramm: Kauf 4, erhalte 1 gratis', targets: [{cartLine: {id: 'middle', quantity: 1}}], value: {percentage: {value: 100}}},
        ],
        selectionStrategy: ProductDiscountSelectionStrategy.First,
      },
    }]});
    expect(JSON.stringify(value)).toBe(original);
  });

  it('caps discounted quantity at the quantity actually in the cart', () => {
    const result = cartLinesDiscountsGenerateRun(input([cartLine('line-1', '10.00', 2)], customer('9')));
    expect(result.operations[0].productDiscountsAdd.candidates[0].targets).toEqual([{cartLine: {id: 'line-1', quantity: 2}}]);
  });

  // Custom metafields deliberately take priority even when the count is zero/null.
  // Changing that during an API migration could grant a previously spent free item.
  it('keeps custom zero allowance ahead of an older loyalty namespace allowance', () => {
    const member = {...customer('0'), loyaltyStatus: {value: 'active'}, loyaltyFreeItems: {value: '5'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, member))).toEqual({operations: []});
  });

  it('keeps an explicitly null custom status ahead of the older namespace', () => {
    const member = {...customer('1'), customLoyaltyStatus: null, loyaltyStatus: {value: 'active'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, member))).toEqual({operations: []});
  });

  it('preserves legacy namespace support when custom aliases are absent', () => {
    const member = {email: 'legacy@example.test', loyaltyStatus: {value: 'active'}, loyaltyFreeItems: {value: '1'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, member)).operations[0].productDiscountsAdd.candidates).toHaveLength(1);
  });

  it.each(['Delivery', 'Lieferung', 'Service Fee', 'Gift Card', 'Gutschein', 'Pfand', 'Deposit'])('excludes %s products from free item rewards', title => {
    const lines = [cartLine('excluded', '1.00', 1, {title}), cartLine('eligible', '10.00')];
    expect(cartLinesDiscountsGenerateRun(input(lines)).operations[0].productDiscountsAdd.candidates[0].targets).toEqual([{cartLine: {id: 'eligible', quantity: 1}}]);
  });

  it('also excludes service product types and deposit variant titles', () => {
    const typed = cartLine('service', '1.00', 1, {productType: 'service'});
    const variant = cartLine('deposit', '1.00');
    variant.merchandise.title = 'Pfand';
    expect(cartLinesDiscountsGenerateRun(input([typed, variant]))).toEqual({operations: []});
  });

  it('does not process merchandise other than ProductVariant', () => {
    const line = {...cartLine('custom'), merchandise: {__typename: 'CustomProduct'}};
    expect(cartLinesDiscountsGenerateRun(input([line]))).toEqual({operations: []});
  });

  it('returns no operations instead of breaking checkout on malformed merchandise', () => {
    expect(cartLinesDiscountsGenerateRun(input([{id: 'malformed'}]))).toEqual({operations: []});
    expect(console.error).toHaveBeenCalled();
  });
});
