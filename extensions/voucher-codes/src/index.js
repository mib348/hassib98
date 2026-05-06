import {
  Banner,
  BlockStack,
  Button,
  ClipboardItem,
  Divider,
  Heading,
  InlineLayout,
  InlineStack,
  Spinner,
  Text,
  TextBlock,
  TextField,
  View,
  extension,
} from "@shopify/ui-extensions/customer-account";

const API_BASE_URL = "https://dev.sushi.catering";

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
  total: (count) => `${count} Gutscheincode${count === 1 ? "" : "s"}`,
  filteredTotal: (shown, total) => `${shown} von ${total} Gutscheincodes`,
};

// In api_version 2025-04 the bundler uses named exports keyed off the
// export field in shopify.extension.toml. The extension(target, callback)
// wrapper returns the descriptor the Shopify host expects.
export const voucherCodesProfileExtension = extension(
  "customer-account.profile.block.render",
  (root, api) => {
    const app = new VoucherCodesApp(root, api);
    app.initialize();
  }
);

class VoucherCodesApp {
  constructor(root, api) {
    this.root = root;
    this.api = api;
    this.state = {
      isLoading: true,
      error: null,
      vouchers: [],
      search: "",
    };
  }

  initialize() {
    this.render();
    this.fetchVouchers();
  }

  render() {
    const { isLoading, error, vouchers, search } = this.state;
    const filtered = search
      ? vouchers.filter((v) =>
          [v.code, v.orderNumber, v.productTitle]
            .join(" ")
            .toLowerCase()
            .includes(search.toLowerCase())
        )
      : vouchers;

    const container = this.root.createComponent(BlockStack, { spacing: "loose" });

    // Title
    container.appendChild(
      this.root.createComponent(Heading, { level: 2 }, TEXT.title)
    );

    // Search field
    const searchField = this.root.createComponent(TextField, {
      label: TEXT.searchLabel,
      value: search,
      placeholder: TEXT.searchPlaceholder,
      onChange: (value) => {
        this.state.search = value;
        this.rerender();
      },
    });
    container.appendChild(searchField);

    // Loading / Error / Empty / Grid
    if (isLoading) {
      container.appendChild(this.root.createComponent(Spinner));
      container.appendChild(this.root.createComponent(Text, null, TEXT.loading));
    } else if (error) {
      container.appendChild(
        this.root.createComponent(Banner, { status: "critical" }, [
          this.root.createComponent(TextBlock, { size: "medium", inlineAlignment: "start" }, TEXT.errorTitle),
          this.root.createComponent(TextBlock, { size: "small", inlineAlignment: "start" }, TEXT.errorText),
        ])
      );
    } else if (filtered.length === 0) {
      container.appendChild(this.root.createComponent(Text, null, TEXT.empty));
    } else {
      // Results count
      container.appendChild(
        this.root.createComponent(Text, { size: "small", appearance: "subdued" },
          search ? TEXT.filteredTotal(filtered.length, vouchers.length) : TEXT.total(vouchers.length)
        )
      );

      // Voucher grid
      filtered.forEach((voucher) => {
        const card = this.createVoucherCard(voucher);
        container.appendChild(card);
      });
    }

    this.root.replaceChildren();
    this.root.appendChild(container);
  }

  createVoucherCard(voucher) {
    // The backend returns snake_case keys; map them to the camelCase names
    // the rest of the component expects.
    const code = voucher.code;
    const maskedCode = voucher.masked_code;
    const status = voucher.status;
    const productTitle = voucher.product_title;
    const orderNumber = voucher.order_number;

    const card = this.root.createComponent(View, { border: "base", padding: "base", cornerRadius: "base" });

    const header = this.root.createComponent(InlineStack, { spacing: "tight", blockAlignment: "center" });
    header.appendChild(
      this.root.createComponent(Text, { appearance: "subdued", size: "small" }, TEXT.codeLabel)
    );

    // Show copy button if code is available
    if (code) {
      const copyBtn = this.root.createComponent(Button, {
        accessibilityLabel: TEXT.copy,
        onPress: () => {
          // Clipboard copy is handled by ClipboardItem, if available, or manual fallback
          if (ClipboardItem && navigator?.clipboard?.write) {
            navigator.clipboard.write([new ClipboardItem({ "text/plain": new Blob([code], { type: "text/plain" }) })]);
          } else {
            // Fallback: focus text field and select to allow manual copy
          }
        },
      }, TEXT.copy);
      header.appendChild(copyBtn);
    }

    card.appendChild(header);

    // Code text (masked or full)
    const codeText = code
      ? this.root.createComponent(TextField, { value: code, readonly: true })
      : this.root.createComponent(Text, { appearance: "warning" }, TEXT.noCode);
    card.appendChild(codeText);

    // Product / Order info
    if (productTitle || orderNumber) {
      const metaBlock = this.root.createComponent(BlockStack, { spacing: "none" });
      if (productTitle) {
        metaBlock.appendChild(this.root.createComponent(Text, { size: "small", appearance: "subdued" }, productTitle));
      }
      if (orderNumber) {
        metaBlock.appendChild(this.root.createComponent(Text, { size: "small", appearance: "subdued" }, `Bestellung: #${orderNumber}`));
      }
      card.appendChild(metaBlock);
    }

    // Status badge
    if (status) {
      const statusText = status === "active" ? "Aktiv" : status === "used" ? "Verwendet" : status;
      card.appendChild(this.root.createComponent(Text, { size: "small", appearance: "subdued" }, `Status: ${statusText}`));
    }

    return card;
  }

  rerender() {
    this.render();
  }

  async fetchVouchers() {
    try {
      // Get the Shopify customer-account session token so the backend can verify
      // the caller. The token is a JWT signed with the app secret.
      const sessionToken = await this.api.sessionToken.get();

      const response = await fetch(`${API_BASE_URL}/api/customer/voucher-codes`, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${sessionToken}`,
        },
      });

      if (!response.ok) {
        const text = await response.text();
        let message = text;
        try {
          const json = JSON.parse(text);
          message = json.message || json.error || text;
        } catch {}
        throw new Error(message || `HTTP ${response.status}`);
      }

      const data = await response.json();
      // Normalize array response
      const vouchers = Array.isArray(data) ? data : data.vouchers || data.data || [];
      this.state.vouchers = vouchers;
    } catch (err) {
      this.state.error = err?.message || err?.toString?.() || "Fehler";
    } finally {
      this.state.isLoading = false;
      this.rerender();
    }
  }
}
