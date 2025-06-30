(function () {
  if (window.__PF_DYNAMIC_LOADER__) {
    console.log('[PF Loader] already initialized');
    return;
  }
  window.__PF_DYNAMIC_LOADER__ = true;
  console.log('[PF Loader] initializing');

  function initializeLoader() {
    var container = document.querySelector('[data-pf-type="ProductList2"]');
    if (!container) {
      console.warn('[PF Loader] ProductList2 container not found');
      return;
    }

    // Check if we have already processed this container
    if (container.classList.contains('ajax_fetched')) {
      console.log('[PF Loader] Container already processed, skipping');
      return;
    }

    /* ---------- Find existing loading icon in PageFly section ---------- */
    var loadingIcon = document.querySelector('.loading_icon');
    var productList = document.querySelector('.product_list');

    // Loading icon is visible by default, product list is hidden by default
    console.log('[PF Loader] Loading icon is visible by default, product list is hidden, starting AJAX call');

    /* ---------- Snapshot PageFly-generated classes from original section ---------- */
    var classMap = {};

    // Get all elements with data-pf-type and capture their classes
    container.querySelectorAll('[data-pf-type]').forEach(function (el) {
      var type = el.getAttribute('data-pf-type');
      if (type && !classMap[type]) {
        classMap[type] = el.className;
        console.log('[PF Loader] Captured classes for', type + ':', el.className);
      }
    });

    // Capture special classes for elements that might not have data-pf-type
    var specialSelectors = {
      'pf-slide': '.pf-slide',
      'add_to_cart': '.add_to_cart',
      'product_details': '.product_details',
      'product_row': '.product_row',
      'qty_portion': '.qty_portion',
      'order_qty': '.order_qty',
      'qty_list': '.qty_list'
    };

    Object.keys(specialSelectors).forEach(function (key) {
      var el = container.querySelector(specialSelectors[key]);
      if (el && !classMap[key]) {
        classMap[key] = el.className;
        console.log('[PF Loader] Captured classes for', key + ':', el.className);
      }
    });

    // Mark container as being processed
    container.classList.add('ajax_fetched');

    var qs = window.location.search || '';
    var url = window.location.pathname + '?section_id=dynamic-location-inventory';
    if (qs && qs.length > 1) { url += '&' + qs.slice(1); }

    console.log('[PF Loader] Fetching:', url);

    // Product list has visibility:hidden by default in PageFly section
    console.log('[PF Loader] Product list is hidden by default via CSS');

    fetch(url)
      .then(function (r) { return r.text(); })
      .then(function (html) {
        console.log('[PF Loader] HTML fetched, length', html.length);

        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var newSlider = tmp.querySelector('.pf-slider');
        var originalSlider = container.querySelector('.pf-slider');

        if (newSlider && originalSlider) {
          console.log('[PF Loader] New slider content found. Injecting into original slider element.');
          originalSlider.innerHTML = '';
          while (newSlider.firstChild) {
            originalSlider.appendChild(newSlider.firstChild);
          }
        } else {
          console.warn('[PF Loader] Could not find original or new slider. Falling back to full HTML replacement.');
          container.innerHTML = html;
        }

        /* ---------- Re-apply captured PageFly classes to dynamic content ---------- */
        console.log('[PF Loader] Applying classes from original to dynamic content');

        Object.keys(classMap).forEach(function (key) {
          var selector;
          var specialSelectors = {
            'pf-slide': '.pf-slide',
            'add_to_cart': '.add_to_cart',
            'product_details': '.product_details',
            'product_row': '.product_row',
            'qty_portion': '.qty_portion',
            'order_qty': '.order_qty',
            'qty_list': '.qty_list'
          };

          if (specialSelectors[key]) {
            selector = specialSelectors[key];
          } else {
            selector = '[data-pf-type="' + key + '"]';
          }

          var elements = container.querySelectorAll(selector);
          console.log('[PF Loader] Applying', key, 'classes to', elements.length, 'elements');

          elements.forEach(function (el) {
            // Add all classes from the original element, but don't remove existing ones,
            // as that can break styled-components classes.
            classMap[key].split(/\s+/).forEach(function (cls) {
              if (cls && cls.trim() && !el.classList.contains(cls)) {
                el.classList.add(cls);
              }
            });
          });
        });

        console.log('[PF Loader] markup injected & cleaned. Relying on PageFly handlers...');

        if (window.jQuery) { console.log('[PF Loader] jQuery present – letting PageFly manage button logic'); }

        if (!document.getElementById('pf-dynamic-qty-style')) {
          var styleTag = document.createElement('style');
          styleTag.id = 'pf-dynamic-qty-style';
          styleTag.textContent = '.qty_list ul{margin:0;padding:0}.qty_list ul li{list-style-type:none}.progress_bar{margin-top:0!important}.add_to_cart[disabled]{cursor:not-allowed!important}.order_qty{float:right}' +
            /* Cart notification styles to ensure full width */
            '.cart-notification__item .cart-item{width:100%!important;max-width:100%!important}' +
            '#cart-notification-product{width:100%!important}' +
            '.cart-notification-product__image{flex-shrink:0}' +
            '.cart-item{display:flex!important;align-items:center!important;gap:1rem!important;width:100%!important}' +
            '.cart-item > div:last-child{flex:1!important}' +
            /* Sold out styles */
            '.sold-out-btn{cursor:not-allowed!important;opacity:0.7!important}' +
            '.sold-out .product_details{opacity:0.8}';
          document.head.appendChild(styleTag);
        }

        console.log('[PF Loader] Executing inline product scripts...');
        container.querySelectorAll('script').forEach(function (oldScript) {
          var newScript = document.createElement('script');
          newScript.textContent = oldScript.textContent;
          document.head.appendChild(newScript).parentNode.removeChild(newScript);
          oldScript.remove();
        });

        // Keep loading icon visible a bit longer to ensure it's seen, then show content
        setTimeout(function () {
          if (productList) {
            productList.style.visibility = 'visible';
            console.log('[PF Loader] Product list now visible');
          }
          if (loadingIcon) {
            loadingIcon.style.display = 'none';
            console.log('[PF Loader] Loading icon hidden');
          }
        }, 500); // Show loading icon for at least 500ms

        // Notify any listeners (e.g., quantity / ATC handler script) that the
        // product list has been replaced so they can re-initialize.
        document.dispatchEvent(new CustomEvent('pf:products:replaced', { detail: container }));

        // Log button classes for debugging
        var buttons = container.querySelectorAll('.add_to_cart');
        console.log('[PF Loader] Found', buttons.length, 'add to cart buttons with classes:');
        buttons.forEach(function (btn, i) {
          console.log('[PF Loader] Button', i + 1, 'classes:', btn.className);
        });

        // Use event delegation for add to cart, which is more robust
        console.log('[PF Loader] Setting up delegated add to cart handler...');
        container.addEventListener('click', function (e) {
          var button = e.target.closest('.add_to_cart');

          if (!button) {
            return; // Click was not on an ATC button or its child
          }

          e.preventDefault();
          console.log('[PF Loader] Delegated Add to cart clicked');

          // Check if product is sold out
          if (button.disabled || button.classList.contains('sold-out-btn')) {
            console.log('[PF Loader] Product is sold out, preventing add to cart');
            return;
          }

          var variantId = button.getAttribute('data-variant-id');
          var form = button.closest('form');

          if (!form || !variantId) {
            console.error('[PF Loader] Missing form or variant ID');
            return;
          }

          // Update button state
          var originalText = button.textContent;
          button.textContent = button.getAttribute('data-adding') || 'Hinzufügen...';
          button.disabled = true;

          // Prepare form data
          var formData = new FormData(form);
          formData.set('id', variantId);

          // Submit to cart
          fetch('/cart/add.js', {
            method: 'POST',
            body: formData
          })
            .then(function (response) {
              if (!response.ok) throw new Error('Failed to add to cart');
              return response.json();
            })
            .then(function (item) {
              console.log('[PF Loader] Item added to cart:', item);

              // Update button state
              button.textContent = button.getAttribute('data-added') || 'Hinzugefügt!';

              // Replicate the theme's native cart update mechanism
              fetch('/?sections=cart-notification-product,cart-notification-button,cart-icon-bubble')
                .then(response => response.json())
                .then(sections => {
                  const cartNotification = document.querySelector('cart-notification');
                  if (!cartNotification) {
                    console.error('[PF Loader] Cart notification element not found.');
                    return;
                  }

                  const productContainerId = 'cart-notification-product';
                  const buttonContainerId = 'cart-notification-button';
                  const iconBubbleId = 'cart-icon-bubble';

                  const rawProductHTML = sections[productContainerId];
                  if (rawProductHTML) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = rawProductHTML;
                    let cartItems = tempDiv.querySelectorAll('[data-cart-item-key]');
                    if (cartItems.length === 0) {
                      cartItems = tempDiv.querySelectorAll('.cart-notification-product__item, .cart-item, [id*="CartItem-"]');
                    }
                    if (cartItems.length > 1) {
                      for (let i = 0; i < cartItems.length - 1; i++) cartItems[i].remove();
                    }
                    const productElement = cartNotification.querySelector(`#${productContainerId}`);
                    if (productElement) productElement.innerHTML = tempDiv.innerHTML;
                  }

                  const buttonElement = cartNotification.querySelector(`#${buttonContainerId}`);
                  if (buttonElement && sections[buttonContainerId]) {
                    buttonElement.innerHTML = sections[buttonContainerId];
                  }

                  const bubbleContainer = document.getElementById(iconBubbleId);
                  if (bubbleContainer && sections[iconBubbleId]) {
                    bubbleContainer.innerHTML = sections[iconBubbleId];
                  }

                  if (typeof cartNotification.open === 'function') {
                    cartNotification.open();
                  } else {
                    cartNotification.classList.add('active');
                  }
                })
                .catch(e => console.error("[PF Loader] Error updating cart sections:", e));

              // Reset button after delay
              setTimeout(function () {
                button.textContent = originalText;
                button.disabled = false;
              }, 2000);
            })
            .catch(function (error) {
              console.error('[PF Loader] Add to cart error:', error);
              button.textContent = originalText;
              button.disabled = false;
              alert('Fehler beim Hinzufügen zum Warenkorb');
            });
        });

        console.log('[PF Loader] product list ready');
      })
      .catch(function (err) {
        console.error('[PF Loader] fetch failed', err);
        if (productList) {
          productList.style.visibility = 'visible';
          console.log('[PF Loader] Product list shown after error');
        }
        if (loadingIcon) {
          loadingIcon.style.display = 'none';
          console.log('[PF Loader] Loading icon hidden after error');
        }
      });
  }

  // Set up event listeners with improved logic
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLoader);
  } else {
    // DOM is already loaded, run immediately
    initializeLoader();
  }
})(); 