const API_BASE_URL = 'https://dev.sushi.catering';

const TEXT = {
  title: 'Gutscheincodes',
  loading: 'Gutscheincodes werden geladen...',
  empty: 'In diesem Kundenkonto wurden noch keine Gutscheincodes gefunden.',
  errorTitle: 'Gutscheincodes konnten nicht geladen werden',
  errorText: 'Bitte versuchen Sie es spaeter erneut oder kontaktieren Sie Sushi Catering.',
  searchLabel: 'Gutscheincodes durchsuchen',
  searchPlaceholder: 'Code, Bestellung oder Produkt suchen',
  codeLabel: 'Code',
  copy: 'Code kopieren',
  copied: 'Code kopiert',
  copyError: 'Code konnte nicht kopiert werden. Bitte kopieren Sie ihn manuell.',
  noCode: 'Code nicht verfuegbar',
  noMatches: 'Keine passenden Gutscheincodes gefunden.',
  total: (count) => `${count} Gutscheincode${count === 1 ? '' : 's'}`,
  filteredTotal: (shown, total) => `${shown} von ${total} Gutscheincodes`,
};

// The 2026 runtime calls the default export without a Remote UI root or API
// argument. Shopify supplies document.body and the global shopify APIs instead.
export default async function voucherCodesPageExtension() {
  const app = new VoucherCodesPageApp();
  await app.initialize();
}

class VoucherCodesPageApp {
  constructor() {
    this.state = { isLoading: true, error: null, vouchers: [], search: '' };
    this.count = null;
    this.results = null;
  }

  initialize() {
    this.render();
    return this.fetchVouchers();
  }

  async fetchVouchers() {
    try {
      if (typeof shopify === 'undefined' || typeof shopify.sessionToken?.get !== 'function') {
        throw new Error('Shopify customer-account session token API is unavailable.');
      }

      // Ask Shopify for a fresh token on every request. The backend validates
      // its signature and customer claim before returning redeemable codes.
      const sessionToken = await shopify.sessionToken.get();
      if (typeof sessionToken !== 'string' || !sessionToken.trim()) {
        throw new Error('Shopify customer-account session token is missing.');
      }

      const url = new URL(`${API_BASE_URL}/api/customer/voucher-codes`);
      const customerId = this.readCustomerId();
      if (customerId) url.searchParams.set('customer_id', customerId);

      const response = await fetch(url.toString(), {
        method: 'GET',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${sessionToken}`,
        },
      });

      if (!response.ok) throw new Error(`Voucher API returned ${response.status}`);

      const payload = await response.json();
      // Keep the existing Laravel response contract: {customer_id, vouchers}.
      // Missing voucher data is rendered as an empty account, as before.
      this.state.vouchers = Array.isArray(payload?.vouchers) ? payload.vouchers : [];
      this.state.error = null;
    } catch (error) {
      console.error('Voucher codes failed to load', error);
      this.state.error = error;
      this.state.vouchers = [];
    } finally {
      this.state.isLoading = false;
      this.render();
    }
  }

  readCustomerId() {
    const source = shopify.authenticatedAccount?.customer;
    const customer = source?.value ?? source?.current;
    const id = customer?.id;
    if (typeof id !== 'string') return null;
    const match = id.match(/^gid:\/\/shopify\/Customer\/(\d+)$/);
    return match ? match[1] : null;
  }

  render() {
    this.count = null;
    this.results = null;
    const wrapper = this.createElement('s-page', { heading: TEXT.title }, [
      this.createElement('s-section', {}, [
        this.createElement('s-stack', { direction: 'block', gap: 'base' }, this.renderContent()),
      ]),
    ]);
    document.body.replaceChildren(wrapper);
  }

  renderContent() {
    if (this.state.isLoading) {
      return this.createElement('s-stack', { direction: 'inline', gap: 'base', alignItems: 'center' }, [
        this.createElement('s-spinner', { accessibilityLabel: TEXT.loading }),
        this.createElement('s-text', {}, TEXT.loading),
      ]);
    }
    if (this.state.error) {
      return this.createElement('s-banner', { tone: 'critical', heading: TEXT.errorTitle }, [
        this.createElement('s-paragraph', {}, TEXT.errorText),
      ]);
    }
    if (this.state.vouchers.length === 0) {
      return this.createElement('s-paragraph', {}, TEXT.empty);
    }
    return this.renderVoucherOverview();
  }

  renderVoucherOverview() {
    this.count = this.createElement('s-text', { type: 'small' });
    this.results = this.createElement('s-stack', { direction: 'block', gap: 'base' });
    const search = this.createElement('s-text-field', {
      label: TEXT.searchLabel,
      placeholder: TEXT.searchPlaceholder,
      value: this.state.search,
      onInput: (event) => this.updateSearch(event.currentTarget.value),
      onChange: (event) => this.updateSearch(event.currentTarget.value),
    });
    this.updateVoucherResults();
    return this.createElement('s-stack', { direction: 'block', gap: 'base' }, [
      this.count, search, this.results,
    ]);
  }

  updateSearch(value) {
    this.state.search = typeof value === 'string' ? value : '';
    // Replace only the matching cards. Replacing the whole root on each key
    // press would recreate the search field and interrupt typing or focus.
    this.updateVoucherResults();
  }

  updateVoucherResults() {
    const filtered = this.filteredVouchers();
    this.count.textContent = this.state.search
      ? TEXT.filteredTotal(filtered.length, this.state.vouchers.length)
      : TEXT.total(this.state.vouchers.length);
    const cards = filtered.length
      ? filtered.map((voucher, index) => this.renderVoucherCard(voucher, index))
      : [this.createElement('s-paragraph', {}, TEXT.noMatches)];
    this.results.replaceChildren(...cards);
  }

  filteredVouchers() {
    const search = this.state.search.trim().toLowerCase();
    if (!search) return this.state.vouchers;
    return this.state.vouchers.filter((voucher) => [
      voucher.code,
      voucher.masked_code,
      voucher.order_number,
      voucher.product_title,
      voucher.variant_title,
      voucher.amount,
      voucher.currency,
      voucher.status,
    ].filter((value) => value !== null && value !== undefined)
      .some((value) => String(value).toLowerCase().includes(search)));
  }

  renderVoucherCard(voucher, index) {
    const stack = this.createElement('s-stack', { direction: 'block', gap: 'small' }, [
      this.renderVoucherHeader(voucher),
      this.renderVoucherMeta(voucher),
      this.renderVoucherCode(voucher, index),
    ]);
    if (voucher.message) {
      stack.appendChild(this.createElement('s-text', { type: 'small' }, voucher.message));
    }
    return this.createElement('s-box', { border: 'base', borderRadius: 'base', padding: 'base' }, stack);
  }

  renderVoucherHeader(voucher) {
    return this.createElement('s-stack', {
      direction: 'inline', gap: 'base', alignItems: 'center', justifyContent: 'space-between',
    }, [
      this.createElement('s-stack', { direction: 'block', gap: 'none' }, [
        this.createElement('s-text', { type: 'strong' }, voucher.product_title || TEXT.title),
        this.createElement('s-text', { type: 'small' }, this.orderLabel(voucher)),
      ]),
      this.createElement('s-text', { type: 'strong' }, this.moneyLabel(voucher)),
    ]);
  }

  renderVoucherMeta(voucher) {
    const children = [];
    if (voucher.variant_title) {
      children.push(this.createElement('s-text', { type: 'small' }, voucher.variant_title));
    }
    children.push(this.createElement('s-text', { type: 'small' }, this.statusLabel(voucher.status)));
    children.push(this.createElement('s-divider'));
    return this.createElement('s-stack', { direction: 'block', gap: 'none' }, children);
  }

  renderVoucherCode(voucher, index) {
    const code = voucher.code || voucher.masked_code || TEXT.noCode;
    const children = [
      this.createElement('s-stack', { direction: 'block', gap: 'none' }, [
        this.createElement('s-text', { type: 'small' }, TEXT.codeLabel),
        this.createElement('s-text', { type: voucher.code ? 'strong' : 'generic' }, code),
      ]),
    ];

    if (voucher.code) {
      const clipboardId = `voucher-code-${index}`;
      const feedback = this.createElement('s-text', { type: 'small' });
      // Clipboard access belongs to Shopify's native component. A worker cannot
      // use navigator.clipboard; copy/copyerror tell us whether the host copied.
      children.push(this.createElement('s-button', {
        variant: 'secondary',
        command: '--copy',
        commandFor: clipboardId,
      }, TEXT.copy));
      children.push(this.createElement('s-clipboard-item', {
        id: clipboardId,
        text: voucher.code,
        onCopy: () => this.showCopyResult(feedback, TEXT.copied),
        onCopyError: () => this.showCopyResult(feedback, TEXT.copyError),
      }));
      children.push(this.createElement('s-stack', { accessibilityRole: 'status' }, feedback));
    }

    return this.createElement('s-stack', { direction: 'inline', gap: 'base', alignItems: 'center' }, children);
  }

  async showCopyResult(feedback, message) {
    feedback.textContent = message;
    try {
      await shopify.toast?.show?.(message);
    } catch (error) {
      // Inline feedback remains available even if the optional toast fails.
      console.error('Voucher clipboard notification failed', error);
    }
  }

  orderLabel(voucher) {
    return voucher.order_number ? `Bestellung #${voucher.order_number}` : 'Bestellung';
  }

  moneyLabel(voucher) {
    const amount = Number(voucher.amount);
    if (!Number.isFinite(amount)) return voucher.currency || '';
    try {
      return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: voucher.currency || 'EUR',
      }).format(amount);
    } catch (error) {
      return `${amount.toFixed(2)} ${voucher.currency || ''}`.trim();
    }
  }

  statusLabel(status) {
    const labels = {
      active: 'Aktiv',
      pending: 'In Erstellung',
      failed: 'Fehlgeschlagen',
      disabled: 'Deaktiviert',
      native_unavailable: 'Per E-Mail versendet',
    };
    return labels[status] || status || 'Unbekannt';
  }

  createElement(tagName, attributes = {}, children = []) {
    const element = document.createElement(tagName);
    Object.entries(attributes).forEach(([name, value]) => {
      if (value === undefined || value === null) return;
      if (name.startsWith('on') && typeof value === 'function') {
        element.addEventListener(name.slice(2).toLowerCase(), value);
      } else {
        // The worker preserves attribute case, while the host reads lowercase
        // names such as commandfor. Normalize here so copy targets and layout
        // reach the native component; event handlers were attached above.
        element.setAttribute(name.toLowerCase(), String(value));
      }
    });
    const normalizedChildren = Array.isArray(children) ? children : [children];
    normalizedChildren.forEach((child) => {
      if (child === undefined || child === null) return;
      element.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
    });
    return element;
  }
}
