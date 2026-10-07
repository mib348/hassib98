/**
 * Target: customer-account.order-status.block.render  (Order status page)
 * Runtime: 2026-07 Polaris web components.
 *
 * Same 2026 contract as thank-you.js (default export, no args, render into
 * document.body, global `shopify`). On the order-status page the order lives on
 * `shopify.order` (a signal) with shape:
 *   { id: 'gid://shopify/Order/123', name: '#1000', confirmationNumber, ... }
 * Even when `name` is available, the backend lookup is still required: it also
 * tells us whether this is delivery, pickup without a scanner, or a QR order.
 * Passing only the GID prevents an early QR from hiding those instructions.
 */
import { runOrderExtension } from './index.js';

function orderStatusExtension() {
  // Return immediately so the host can reveal the loader while metadata is
  // pending. The shared loader still handles all retries and errors itself.
  void runOrderExtension(() => {
    const order = typeof shopify !== 'undefined' ? shopify.order?.value : null;
    return {
      gid: order?.id || null
    };
  }, () => typeof shopify !== 'undefined' ? shopify.order : null);
}

// Named export kept to match the toml `export` field; default export is what
// the 2026 bundler actually binds for this target.
export { orderStatusExtension };
export default orderStatusExtension;
