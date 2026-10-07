import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

import {cartLinesDiscountsGenerateRun} from '../src/cart_lines_discounts_generate_run';
import {DiscountClass, ProductDiscountSelectionStrategy} from '../generated/api';

// This release keeps the rules already served by production release 34.
// The pending preorder exclusion is deferred; the API upgrade must not add it.
function cartLine(id = 'line-1', yesterday = 'Y', immediate = 'Y', quantity = 1, product = {}) {
  return {
    id,
    quantity,
    cost: {amountPerQuantity: {amount: '10.00'}},
    merchandise: {
      __typename: 'ProductVariant',
      title: 'Default Title',
      product: {title: 'Salmon Maki', productType: 'Sushi', ...product},
    },
    yesterdayItemAttribute: yesterday === null ? null : {value: yesterday},
    immediateInventoryAttribute: immediate === null ? null : {value: immediate},
  };
}

function input(lines = [cartLine()], customer = null, classes = [DiscountClass.Product]) {
  return {cart: {lines, buyerIdentity: {customer}}, discount: {discountClasses: classes}};
}

describe('cartLinesDiscountsGenerateRun yesterday rules', () => {
  beforeEach(() => {
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });
  afterEach(() => vi.restoreAllMocks());

  it.each([undefined, null, {}, {cart: {lines: []}}])('returns no discounts for absent or empty carts (%j)', value => {
    expect(cartLinesDiscountsGenerateRun(value)).toEqual({operations: []});
  });

  it.each([[], [DiscountClass.Order], [DiscountClass.Shipping]].map(classes => [classes]))('requires the product discount class (%j)', classes => {
    expect(cartLinesDiscountsGenerateRun(input(undefined, undefined, classes))).toEqual({operations: []});
  });

  it('gives guests 50 percent off the whole eligible line quantity', () => {
    expect(cartLinesDiscountsGenerateRun(input([cartLine('line-1', 'Y', 'Y', 3)]))).toEqual({operations: [{
      productDiscountsAdd: {
        candidates: [{message: '50% Rabatt auf Artikel vom Vortag', targets: [{cartLine: {id: 'line-1', quantity: 3}}], value: {percentage: {value: 50}}}],
        selectionStrategy: ProductDiscountSelectionStrategy.First,
      },
    }]});
  });

  it.each(['N', 'n'])('keeps release 34 yesterday eligibility regardless of the pending inventory flag (%s)', immediate => {
    expect(cartLinesDiscountsGenerateRun(input([cartLine('preorder', 'Y', immediate)])).operations[0].productDiscountsAdd.candidates[0].targets).toEqual([{cartLine: {id: 'preorder', quantity: 1}}]);
  });

  it.each(['Y', 'y', null, ''])('preserves immediate and legacy missing-flag eligibility (%j)', immediate => {
    expect(cartLinesDiscountsGenerateRun(input([cartLine('yesterday', 'y', immediate)])).operations[0].productDiscountsAdd.candidates[0].targets).toEqual([{cartLine: {id: 'yesterday', quantity: 1}}]);
  });

  it('preserves eligibility when the immediate_inventory alias is absent', () => {
    const line = cartLine();
    delete line.immediateInventoryAttribute;
    expect(cartLinesDiscountsGenerateRun(input([line])).operations).toHaveLength(1);
  });

  it.each(['N', 'n', '', null])('requires the yesterday flag rather than only immediate inventory (%j)', yesterday => {
    expect(cartLinesDiscountsGenerateRun(input([cartLine('today', yesterday)]))).toEqual({operations: []});
  });

  it('keeps release 34 yesterday-flag eligibility in a mixed cart without changing input', () => {
    const value = input([cartLine('preorder', 'Y', 'N'), cartLine('yesterday', 'Y', 'Y', 2), cartLine('today', 'N', 'Y')]);
    const original = JSON.stringify(value);
    const candidates = cartLinesDiscountsGenerateRun(value).operations[0].productDiscountsAdd.candidates;
    expect(candidates).toHaveLength(2);
    expect(candidates[0].targets).toEqual([{cartLine: {id: 'preorder', quantity: 1}}]);
    expect(candidates[1].targets).toEqual([{cartLine: {id: 'yesterday', quantity: 2}}]);
    expect(JSON.stringify(value)).toBe(original);
  });

  it('leaves a better loyalty reward to the loyalty Function', () => {
    const customer = {email: 'member@example.test', customLoyaltyStatus: {value: 'active'}, customLoyaltyFreeItems: {value: '1'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer))).toEqual({operations: []});
  });

  it.each(['0', '-1', 'invalid'])('still discounts yesterday items when loyalty free items are unavailable (%s)', freeItems => {
    const customer = {customLoyaltyStatus: {value: 'active'}, customLoyaltyFreeItems: {value: freeItems}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer)).operations).toHaveLength(1);
  });

  it('does not suppress yesterday discounts for inactive loyalty members', () => {
    const customer = {customLoyaltyStatus: {value: 'inactive'}, customLoyaltyFreeItems: {value: '4'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer)).operations).toHaveLength(1);
  });

  it('keeps custom zero allowance ahead of the older loyalty namespace', () => {
    const customer = {customLoyaltyStatus: {value: 'active'}, customLoyaltyFreeItems: {value: '0'}, loyaltyStatus: {value: 'active'}, loyaltyFreeItems: {value: '4'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer)).operations).toHaveLength(1);
  });

  it('preserves legacy loyalty priority when custom aliases are absent', () => {
    const customer = {loyaltyStatus: {value: 'active'}, loyaltyFreeItems: {value: '1'}};
    expect(cartLinesDiscountsGenerateRun(input(undefined, customer))).toEqual({operations: []});
  });

  it.each(['Delivery', 'Lieferung', 'Service Fee', 'Gift Card', 'Gutschein', 'Pfand', 'Deposit'])('excludes %s even if it carries a yesterday flag', title => {
    expect(cartLinesDiscountsGenerateRun(input([cartLine('excluded', 'Y', 'Y', 1, {title})]))).toEqual({operations: []});
  });

  it('also excludes service product types and deposit variant titles', () => {
    const typed = cartLine('service', 'Y', 'Y', 1, {productType: 'service'});
    const variant = cartLine('deposit');
    variant.merchandise.title = 'Pfand';
    expect(cartLinesDiscountsGenerateRun(input([typed, variant]))).toEqual({operations: []});
  });

  it('does not discount merchandise other than ProductVariant', () => {
    const line = {...cartLine(), merchandise: {__typename: 'CustomProduct'}};
    expect(cartLinesDiscountsGenerateRun(input([line]))).toEqual({operations: []});
  });

  it('returns no operations instead of breaking checkout on malformed merchandise', () => {
    expect(cartLinesDiscountsGenerateRun(input([{id: 'malformed'}]))).toEqual({operations: []});
    expect(console.error).toHaveBeenCalled();
  });
});
