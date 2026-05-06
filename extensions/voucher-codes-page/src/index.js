const API_BASE_URL = "https://dev.sushi.catering";
const LOG_PREFIX = "[VoucherCodesPage]";

const TEXT = {
  title: "Gutscheincodes",
  loading: "Gutscheincodes werden geladen...",
  empty: "In diesem Kundenkonto wurden noch keine Gutscheincodes gefunden.",
  errorTitle: "Gutscheincodes konnten nicht geladen werden",
  errorText: "Bitte versuchen Sie es spaeter erneut oder kontaktieren Sie Sushi Catering.",
  searchLabel: "Gutscheincodes durchsuchen",
  searchPlaceholder: "Code, Bestellung oder Produkt suchen",
  codeLabel: "Code",
  copy: "Code kopieren",
  copied: "Code kopiert",
  noCode: "Code nicht verfuegbar",
  noMatches: "Keine passenden Gutscheincodes gefunden.",
  total: (count) => `${count} Gutscheincode${count === 1 ? "" : "s"}`,
  filteredTotal: (shown, total) => `${shown} von ${total} Gutscheincodes`,
};

function debug(message, data = undefined) {
  if (data === undefined) {
    console.log(`${LOG_PREFIX} ${message}`);
    return;
  }

  console.log(`${LOG_PREFIX} ${message}`, data);
}

function debugError(message, error) {
  console.error(`${LOG_PREFIX} ${message}`, {
    message: error?.message,
    name: error?.name,
    stack: error?.stack,
    error,
  });
}

debug("module loaded", {
  apiBaseUrl: API_BASE_URL,
  target: "customer-account.page.render",
});

// Shopify customer-account full-page extensions on the 2026 runtime execute the
// module export with no Remote UI root argument. UI is rendered by adding
// Shopify web components directly to document.body, and runtime APIs are read
// from the global shopify object.
export async function voucherCodesPageExtension() {
  debug("extension function started", {
    hasDocumentBody: Boolean(document?.body),
    hasShopifyGlobal: typeof shopify !== "undefined",
    shopifyKeys: typeof shopify !== "undefined" ? Object.keys(shopify) : [],
    hasSessionToken: typeof shopify?.sessionToken?.get === "function",
  });

  const app = new VoucherCodesPageApp();
  app.initialize();
}

export default voucherCodesPageExtension;

class VoucherCodesPageApp {
  constructor() {
    this.state = {
      isLoading: true,
      error: null,
      vouchers: [],
      search: "",
    };
  }

  initialize() {
    debug("initialize started");

    try {
      this.render();
      this.fetchVouchers();
    } catch (error) {
      debugError("initialize failed before async voucher loading", error);
      throw error;
    }
  }

  async fetchVouchers() {
    debug("fetchVouchers started");

    try {
      if (typeof shopify?.sessionToken?.get !== "function") {
        throw new Error("Shopify sessionToken API is unavailable in this customer-account page.");
      }

      const sessionToken = await shopify.sessionToken.get();
      debug("session token received", {
        hasToken: Boolean(sessionToken),
        tokenLength: typeof sessionToken === "string" ? sessionToken.length : null,
      });

      const url = new URL(`${API_BASE_URL}/api/customer/voucher-codes`);
      debug("voucher API request prepared", {
        url: url.toString(),
        hasAuthorizationHeader: Boolean(sessionToken),
      });

      const response = await fetch(url.toString(), {
        method: "GET",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${sessionToken}`,
        },
      });

      debug("voucher API response received", {
        ok: response.ok,
        status: response.status,
        statusText: response.statusText,
        contentType: response.headers.get("content-type"),
      });

      if (!response.ok) {
        throw new Error(`Voucher API returned ${response.status}`);
      }

      const payload = await response.json();
      debug("voucher API JSON parsed", {
        payloadKeys: payload && typeof payload === "object" ? Object.keys(payload) : [],
        responseCustomerId: payload?.customer_id,
        voucherCount: Array.isArray(payload?.vouchers) ? payload.vouchers.length : 0,
        firstVoucher: Array.isArray(payload?.vouchers) && payload.vouchers.length > 0
          ? this.safeVoucherForLog(payload.vouchers[0])
          : null,
      });

      this.state.vouchers = Array.isArray(payload.vouchers) ? payload.vouchers : [];
      this.state.error = null;
      debug("voucher state updated", {
        voucherCount: this.state.vouchers.length,
        statuses: this.state.vouchers.map((voucher) => voucher.status).filter(Boolean),
      });
    } catch (error) {
      debugError("voucher codes failed to load", error);
      this.state.error = error;
      this.state.vouchers = [];
    } finally {
      this.state.isLoading = false;
      debug("fetchVouchers finished, rendering final state", {
        isLoading: this.state.isLoading,
        hasError: Boolean(this.state.error),
        voucherCount: this.state.vouchers.length,
      });
      this.render();
    }
  }

  render() {
    debug("render started", {
      isLoading: this.state.isLoading,
      hasError: Boolean(this.state.error),
      voucherCount: this.state.vouchers.length,
      search: this.state.search,
      hasDocumentBody: Boolean(document?.body),
    });

    if (!document?.body) {
      throw new Error("Voucher page document body is unavailable.");
    }

    document.body.replaceChildren(this.renderPage());
    debug("render finished");
  }

  renderPage() {
    return this.createElement("s-page", {}, [
      this.createElement("s-section", {}, [
        this.createElement("s-stack", { direction: "block", gap: "base" }, [
          this.createElement("s-heading", {}, TEXT.title),
          this.renderContent(),
        ]),
      ]),
    ]);
  }

  renderContent() {
    if (this.state.isLoading) {
      return this.createElement("s-text", {}, TEXT.loading);
    }

    if (this.state.error) {
      return this.createElement("s-banner", { tone: "critical", heading: TEXT.errorTitle }, TEXT.errorText);
    }

    if (this.state.vouchers.length === 0) {
      return this.createElement("s-text", {}, TEXT.empty);
    }

    return this.renderVoucherOverview();
  }

  renderVoucherOverview() {
    const filteredVouchers = this.filteredVouchers();
    const children = [
      this.createElement(
        "s-text",
        { type: "small" },
        this.state.search
          ? TEXT.filteredTotal(filteredVouchers.length, this.state.vouchers.length)
          : TEXT.total(this.state.vouchers.length)
      ),
      this.createElement("s-text-field", {
        label: TEXT.searchLabel,
        placeholder: TEXT.searchPlaceholder,
        value: this.state.search,
        onInput: (event) => this.updateSearch(event?.target?.value),
        onChange: (event) => this.updateSearch(event?.target?.value),
      }),
    ];

    if (filteredVouchers.length === 0) {
      children.push(this.createElement("s-text", {}, TEXT.noMatches));
      return this.createElement("s-stack", { direction: "block", gap: "base" }, children);
    }

    filteredVouchers.forEach((voucher, index) => {
      children.push(this.renderVoucherCard(voucher, index));
    });

    return this.createElement("s-stack", { direction: "block", gap: "base" }, children);
  }

  renderVoucherCard(voucher, index) {
    debug("renderVoucherCard", {
      index,
      voucher: this.safeVoucherForLog(voucher),
    });

    const children = [
      this.createElement("s-heading", { level: "3" }, voucher.product_title || TEXT.title),
      this.createElement("s-text", { type: "small" }, this.orderLabel(voucher)),
      this.createElement("s-text", {}, this.moneyLabel(voucher)),
    ];

    if (voucher.variant_title) {
      children.push(this.createElement("s-text", { type: "small" }, voucher.variant_title));
    }

    children.push(this.createElement("s-text", { type: "small" }, this.statusLabel(voucher.status)));
    children.push(this.renderVoucherCode(voucher));

    if (voucher.message) {
      children.push(this.createElement("s-text", { type: "small" }, voucher.message));
    }

    return this.createElement("s-section", {}, [
      this.createElement("s-stack", { direction: "block", gap: "small" }, children),
    ]);
  }

  renderVoucherCode(voucher) {
    const code = voucher.code || voucher.masked_code || TEXT.noCode;
    const children = [
      this.createElement("s-text", { type: "small" }, TEXT.codeLabel),
      this.createElement("s-text", { emphasis: voucher.code ? "bold" : undefined }, code),
    ];

    if (voucher.code) {
      children.push(
        this.createElement("s-button", {
          onClick: () => this.copyVoucherCode(voucher.code),
        }, TEXT.copy)
      );
    }

    return this.createElement("s-stack", { direction: "block", gap: "small" }, children);
  }

  async copyVoucherCode(code) {
    debug("copyVoucherCode requested", {
      hasCode: Boolean(code),
      hasClipboard: Boolean(navigator?.clipboard?.writeText),
    });

    try {
      await navigator.clipboard.writeText(code);
      await shopify?.toast?.show?.(TEXT.copied);
    } catch (error) {
      debugError("copyVoucherCode failed", error);
    }
  }

  updateSearch(value) {
    this.state.search = typeof value === "string" ? value : "";
    debug("search updated", {
      search: this.state.search,
      totalVoucherCount: this.state.vouchers.length,
      filteredVoucherCount: this.filteredVouchers().length,
    });

    this.render();
  }

  filteredVouchers() {
    const search = this.state.search.trim().toLowerCase();
    if (!search) return this.state.vouchers;

    return this.state.vouchers.filter((voucher) => {
      return [
        voucher.code,
        voucher.masked_code,
        voucher.order_number,
        voucher.product_title,
        voucher.variant_title,
        voucher.amount,
        voucher.currency,
        voucher.status,
      ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(search));
    });
  }

  orderLabel(voucher) {
    return voucher.order_number ? `Bestellung #${voucher.order_number}` : "Bestellung";
  }

  moneyLabel(voucher) {
    const amount = Number(voucher.amount);
    if (!Number.isFinite(amount)) {
      return voucher.currency || "";
    }

    try {
      return new Intl.NumberFormat("de-DE", {
        style: "currency",
        currency: voucher.currency || "EUR",
      }).format(amount);
    } catch (error) {
      return `${amount.toFixed(2)} ${voucher.currency || ""}`.trim();
    }
  }

  statusLabel(status) {
    const labels = {
      active: "Aktiv",
      pending: "In Erstellung",
      failed: "Fehlgeschlagen",
      disabled: "Deaktiviert",
      native_unavailable: "Per E-Mail versendet",
    };

    return labels[status] || status || "Unbekannt";
  }

  createElement(tagName, attributes = {}, children = []) {
    const element = document.createElement(tagName);

    Object.entries(attributes).forEach(([name, value]) => {
      if (value === undefined || value === null) {
        return;
      }

      if (name.startsWith("on") && typeof value === "function") {
        element.addEventListener(name.slice(2).toLowerCase(), value);
        return;
      }

      element.setAttribute(name, String(value));
    });

    const normalizedChildren = Array.isArray(children) ? children : [children];
    normalizedChildren.forEach((child) => {
      if (child === undefined || child === null) {
        return;
      }

      element.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
    });

    return element;
  }

  safeVoucherForLog(voucher) {
    if (!voucher || typeof voucher !== "object") {
      return voucher;
    }

    return {
      order_number: voucher.order_number,
      product_title: voucher.product_title,
      variant_title: voucher.variant_title,
      amount: voucher.amount,
      currency: voucher.currency,
      hasCode: Boolean(voucher.code),
      hasMaskedCode: Boolean(voucher.masked_code),
      status: voucher.status,
      source: voucher.source,
      created_at: voucher.created_at,
    };
  }
}
