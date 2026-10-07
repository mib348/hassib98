/**
 * Target: purchase.thank-you.block.render  (Checkout Thank-you page)
 * Runtime: 2026-07 Polaris web components.
 *
 * The 2026 runtime imports this module's DEFAULT export and calls it with NO
 * arguments; UI is rendered into document.body and runtime data is read from
 * the global `shopify` object. On the Thank-you page the order identity lives
 * on `shopify.orderConfirmation` (a signal) with shape:
 *   { isFirstOrder, number, order: { id: 'gid://shopify/Order/123' } }
 * IMPORTANT: `number` is an alphanumeric confirmation identifier, NOT the
 * numeric order number that our pickup station scans. Pass only the order GID
 * to the shared loader, which obtains the correct number and location metadata
 * from our existing backend endpoint before deciding what to display.
 */
import { runOrderExtension } from './order-view.js';

function thankYouExtension() {
  // The host reveals the extension after this entrypoint returns. Start the
  // loader without waiting so buyers can see its loading and retry states.
  void runOrderExtension(() => {
    // Read the order confirmation signal defensively — it may be absent until
    // Shopify finishes creating the order.
    const oc = typeof shopify !== 'undefined' ? shopify.orderConfirmation?.value : null;
    return {
      gid: oc?.order?.id || null
    };
  }, () => typeof shopify !== 'undefined' ? shopify.orderConfirmation : null);
}

// Named export kept to match the toml `export` field; default export is what
// the 2026 bundler actually binds for this target.
export { thankYouExtension };
export default thankYouExtension;
