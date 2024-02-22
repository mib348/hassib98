window.onload = function () {
  if (window.jQuery) {
    let $ = window.jQuery;

    simulateProgress();

    var location = sessionStorage.getItem('location');
    var date = sessionStorage.getItem('date');

    location = location ? location : '';
    date = date ? date : '';

    var options = { weekday: 'long', timeZone: 'Europe/Berlin' };
    var now = new Date();
    var day = new Intl.DateTimeFormat('en-US', options).format(now);

    function extractQtyForDate(metafields, date) {
      var quantity = 0;

      metafields.forEach(function (metafield) {
        if (metafield.key === "date_and_quantity") {
          var dateQuantities = JSON.parse(metafield.value);
          dateQuantities.forEach(function (dateQuantity) {
            var [dateStr, qty] = dateQuantity.split(":");
            if (dateStr === date) {
              quantity = qty;
            }
          });
        }
      });

      return quantity;
    }

    updateProgressBar(50);

    $.ajax({
      url: "https://sushicatering.digitalmib.com/api/getProductsJson",
      type: "GET",
      data: {
        location: location,
        date: date,
        day: day
      },
      beforeSend: function () {
        updateProgressBar(60);
      },
      xhrFields: {
        withCredentials: true
      },
      cache: false,
      dataType: "json",
      complete: function (xhr) {
        updateProgressBar(100);
        $(".progress_bar_box").remove();
      },
      success: function (data) {
        updateProgressBar(80);
        var newHtml = '';

        // Loop through each product in the data array
        $.each(data, function (index, product) {
          var json = {
            id: product.id,
            handle: product.handle,
            title: product.title,
            type: product.product_type,
            url: '/products/' + product.handle,
            vendor: product.vendor,
            variants: product.variants.map(function (variant) {
              return {
                id: variant.id,
                title: variant.title,
                option1: variant.option1,
                option2: variant.option2,
                option3: variant.option3,
                sku: variant.sku,
                requires_shipping: variant.requires_shipping,
                taxable: variant.taxable,
                featured_image: null,
                available: true,
                name: product.title,
                public_title: null,
                options: ["Default Title"],
                price: variant.price,
                weight: variant.weight,
                compare_at_price: null,
                inventory_management: variant.inventory_management,
                barcode: variant.barcode,
                requires_selling_plan: false,
                selling_plan_allocations: []
              };
            }),
            options: product.options.map(function (option) {
              return option.name;
            }),
            media: product.images.map(function (image) {
              return {
                alt: image.alt,
                id: image.id,
                position: image.position,
                preview_image: {
                  aspect_ratio: 1.0,
                  height: image.height,
                  width: image.width,
                  src: image.src
                },
                aspect_ratio: 1.0,
                height: image.height,
                media_type: "image",
                src: image.src,
                width: image.width
              };
            }),
            has_only_default_variant: true,
            options_with_values: product.options.map(function (option) {
              return {
                name: option.name,
                position: option.position,
                values: option.values
              };
            }),
            selected_variant: null,
            selected_or_first_available_variant: product.variants[0] ? {
              id: product.variants[0].id,
              title: product.variants[0].title,
              option1: product.variants[0].option1,
              option2: product.variants[0].option2,
              option3: product.variants[0].option3,
              sku: product.variants[0].sku,
              requires_shipping: product.variants[0].requires_shipping,
              taxable: product.variants[0].taxable,
              featured_image: null,
              available: true,
              name: product.title,
              public_title: null,
              options: ["Default Title"],
              price: product.variants[0].price,
              weight: product.variants[0].weight,
              compare_at_price: null,
              inventory_management: product.variants[0].inventory_management,
              barcode: product.variants[0].barcode,
              requires_selling_plan: false,
              selling_plan_allocations: []
            } : null,
            tags: product.tags.split(','),
            template_suffix: product.template_suffix,
            featured_image: product.image.src,
            featured_media: {
              alt: product.image.alt,
              id: product.image.id,
              position: product.image.position,
              preview_image: {
                aspect_ratio: 1.0,
                height: product.image.height,
                width: product.image.width,
                src: product.image.src
              },
              aspect_ratio: 1.0,
              height: product.image.height,
              media_type: "image",
              src: product.image.src,
              width: product.image.width
            },
            images: product.images.map(function (image) {
              return image.src;
            }),
            quantity: product.variants.map(function (variant) {
              return variant.id + ':' + variant.inventory_quantity;
            })
          };
          window.__pageflyProducts[product.id] = json;

          // Start constructing the HTML for each product
          newHtml += '<div class="pf-slide pf-c">';
          newHtml += '<div data-product-id="' + product.id + '" data-pf-type="ProductBox" class="sc-bGaVxB gunEPM pf-534_ product_details">';
          newHtml += '      <form method="post" action="/cart/add" id="product_form_' + product.id + '" accept-charset="UTF-8" class="pf-product-form" enctype="multipart/form-data" data-productid="' + product.id + '">';
          newHtml += '         <input type="hidden" name="form_type" value="product"><input type="hidden" name="utf8" value="✓">';
          newHtml += '         <input type="hidden" name="order_date" value="' + sessionStorage.getItem('date') + '"><input type="hidden" name="location" value="' + sessionStorage.getItem('location') + '">';
          newHtml += '         <div class="sc-iCfMLu eehqFj pf-533_ product_row pf-r pf-r-eh" style="--s-xs:0px" data-pf-type="Row">';
          newHtml += '            <div class="pf-c" style="--c-xs:12;--c-md:12;--c-lg:12">';
          newHtml += '               <div data-pf-type="Column" class="sc-XxNYO eWQzGj pf-532_">';
          newHtml += '                  <div data-product-id="' + product.id + '" data-media-id="10078379950" data-pf-type="ProductMedia2" class="sc-kHdrYz Dnmob pf-517_">';
          newHtml += '                     <div class="sc-jYmNlR kYHThe   pf-lg-hide-list pf-md-hide-list pf-sm-hide-list pf-xs-hide-list product-media2-inner product-media-loading">';
          newHtml += '                        <div class="sc-bzPmhk jCzOHU pmw pf-main-media-wrapper">';
          newHtml += '                           <div data-pf-type="MediaMain" class="sc-bxDdli klaLlZ pf-513_ pf-main-media" data-href="/products/' + product.handle + '" data-action="url">';
          newHtml += '                              <div class="sc-PZsNp ckbNQA">';
          newHtml += '                                 <div class="pf-media-slider scrollfix" data-id="slider-a79b1f12-6248-40a0-a4b2-cf07d201a366">';
          newHtml += '                                    <div class="pf-slide-main-media" data-media-type="image" data-media-id="' + product.image.id + '"><img loading="lazy" class="sc-dGXBhE hQXffb active" data-action="1" alt="' + product.image.alt + '" width="' + product.image.width + '" height="' + product.image.height + '" src="' + product.image.src + '"></div>';
          newHtml += '                                 </div>';
          newHtml += '                              </div>';
          newHtml += '                           </div>';
          newHtml += '                        </div>';
          newHtml += '                        <div class="sc-jWaEpP bJWHWt pf-515_ pf-list-media pf-hide pf-sm-hide pf-md-hide pf-lg-hide" data-pf-type="MediaList2">';
          newHtml += '                           <div class="pf-media-slider scrollfix " style="--dpi-xs:0%;--gap-xs:10px" data-id="slider-9b1f1262-48c0-40e4-b2cf-07d201a36656">';
          newHtml += '                              <div class="sc-jftFmt iDwIfs pf-514_ pf-slide-list-media" data-img-id="' + product.image.id + '" data-media-type="image" data-pf-type="MediaListItem2"><img src="' + product.image.src + '" alt="' + product.image.alt + '" loading="lazy"></div>';
          newHtml += '                           </div>';
          newHtml += '                        </div>';
          newHtml += '                     </div>';
          newHtml += '                  </div>';
          newHtml += '                  <h3 data-product-type="title" data-product-id="' + product.id + '" data-href="/products/' + product.handle + '" data-pf-type="ProductTitle" class="sc-itWPBs ckyPHz pf-518_">' + product.title + '</h3>';
          newHtml += '                  <div data-product-id="' + product.id + '" data-pf-type="ProductMetafield" class="sc-hLVXRe fPihTC pf-521_">';
          newHtml += '                     <span data-pf-type="MetafieldLabel" class="sc-hlGDCY fIdUDm pf-519_">Quantität:</span>';

          if (product.metafields.length) {
            var extractedQuantity = extractQtyForDate(product.metafields, date);

            if(product.b_date_product){
                newHtml += '                     <span data-pf-type="MetafieldValue" class="sc-gA-DPUo caFxOX pf-520_ qty_list" data-product-type="date">';
            }
            else{
                extractedQuantity = product.variants[0].inventory_quantity; 
                newHtml += '                     <span data-pf-type="MetafieldValue" class="sc-gA-DPUo caFxOX pf-520_ qty_list" data-product-type="day">';
            }
            newHtml += extractedQuantity;
                                            newHtml += `<div data-product-id="` + product.id + `" data-hidespinner="true" data-pf-type="ProductQuantity" class="sc-eBTqsU cZVaxr pf-525_ order_qty" listener="true">
                                                            <button data-quantity-action="decrease" type="button" data-pf-type="QuantityButton" class="sc-iseIHH cyJzLA pf-522_" disabled="disabled">
                                                            <svg style="--h-xs:30" viewBox="0 -20 50 50" fill="currentColor">
                                                                <path d="M47.9167 0.833374H2.08333C0.932292 0.833374 0 1.76567 0 2.91671V7.08337C0 8.23442 0.932292 9.16671 2.08333 9.16671H47.9167C49.0677 9.16671 50 8.23442 50 7.08337V2.91671C50 1.76567 49.0677 0.833374 47.9167 0.833374Z"></path>
                                                            </svg>
                                                            </button>
                                                            <input min="1" max="` + extractedQuantity + `" type="number" data-hidespinner="true" name="quantity" data-variants-continue="" data-pf-type="QuantityField" class="sc-ezHhwS emjRZh pf-523_" value="1" autocomplete="off">
                                                            <button data-quantity-action="increase" type="button" data-pf-type="QuantityButton" class="sc-iseIHH cyJzLA pf-524_">
                                                            <svg style="--h-xs:30" viewBox="0 0 50 50" fill="currentColor">
                                                                <path d="M47.9167 20.8333H29.1667V2.08333C29.1667 0.932292 28.2344 0 27.0833 0H22.9167C21.7656 0 20.8333 0.932292 20.8333 2.08333V20.8333H2.08333C0.932292 20.8333 0 21.7656 0 22.9167V27.0833C0 28.2344 0.932292 29.1667 2.08333 29.1667H20.8333V47.9167C20.8333 49.0677 21.7656 50 22.9167 50H27.0833C28.2344 50 29.1667 49.0677 29.1667 47.9167V29.1667H47.9167C49.0677 29.1667 50 28.2344 50 27.0833V22.9167C50 21.7656 49.0677 20.8333 47.9167 20.8333Z"></path>
                                                            </svg>
                                                            </button>
                                                        </div>
                                                        `;
            newHtml += '                     </span>';
          }

          newHtml += '                  </div>';
          newHtml += '                  <div data-show-button="true" data-more="" data-less="" data-product-type="content" data-product-id="' + product.id + '" data-pf-type="ProductDescription" class="sc-igXgud iPIMpk pf-526_">' + product.body_html + '</div>';
          newHtml += '                  <div data-pf-type="ProductPrice2" class="sc-jdhwqr jnAcXR pf-529_">';

          var priceWithDot = product.variants[0].price; 
          var priceWithComma = priceWithDot.replace('.', ',');

          newHtml += '                     <div data-product-type="price" data-product-id="' + product.id + '" data-product-price="true" data-pf-type="ProductPrice2Item" class="sc-fkJVfC gmaILq pf-527_">€' + priceWithComma + '</div>';
          newHtml += '                  </div>';

          if(!extractedQuantity || extractedQuantity < 1)
            newHtml += '                  <button disabled data-id="' + product['variants'][0]['id'] + '" data-product-id="' + product.id + '" data-checkout="link" data-soldout="Sold out" data-adding="Adding..." data-added="Thank you!" name="add" type="button" tabindex="0" spellcheck="false" data-pf-type="ProductATC2" class="sc-cQMzAB dqKFZr pf-531_ add_to_cart">Add To Cart</button>';
          else
            newHtml += '                  <button data-quantity="' + extractedQuantity + '" data-id="' + product['variants'][0]['id'] + '" data-product-id="' + product.id + '" data-checkout="link" data-soldout="Sold out" data-adding="Adding..." data-added="Thank you!" name="add" type="button" tabindex="0" spellcheck="false" data-pf-type="ProductATC2" class="sc-cQMzAB dqKFZr pf-531_ add_to_cart">Add To Cart</button>';

          newHtml += '               </div>';
          newHtml += '            </div>';
          newHtml += '         </div>';
          newHtml += '         <input type="hidden" name="id" value="' + product.variants[0].id + '"><input type="hidden" name="product-id" value="' + product.id + '"><input type="hidden" name="section-id" value="template--20856906416476__pf-b9ef5afd">';
          newHtml += '      </form>';
          newHtml += '   </div>';
          newHtml += '</div>';

        });

        console.log(window.__pageflyProducts);

        // alert(newHtml);

        // $(".pf-slider").append(newHtml);
        $(".pf-slider").html(newHtml);
        $(".pf-slider").each(function() {
            this.style.setProperty('display', 'flex', 'important');
        });
        

        updateProgressBar(90);

        $(".add_to_cart").click(function (e) {
          e.preventDefault();
          var btn = $(this);
          btn.html(btn.data('adding'));

          sessionStorage.setItem($(this).data('product-id'), $(this).data('quantity'));

          $.ajax({
            type: 'POST',
            url: window.Shopify.routes.root + 'cart/add.js',
            data: btn.closest('form').serialize() + '&properties[location]=' + sessionStorage.getItem('location') + '&properties[date]=' + sessionStorage.getItem('date'),
            dataType: 'json',
            success: function (response) {
              btn.html(btn.data('added'));
              btn.attr('disabled', true);
            },
            error: function (xhr, status, error) {
              console.log('Add to cart error:', error);
            }
          });

        });

        $('.pf-main-media, .ckyPHz').click(function(e) {
          e.preventDefault();
            window.location.href=$(this).data('href');
        });

        $('button[data-quantity-action]').click(function(e) {
          e.preventDefault();
            var $button = $(this);
            var $input = $button.siblings('input[name="quantity"]');
            var oldValue = parseInt($input.val(), 10);
            var newVal = oldValue;
    
            if ($button.data('quantity-action') === 'increase') {
                newVal = oldValue + 1;
            } else if ($button.data('quantity-action') === 'decrease') {
                newVal = Math.max(oldValue - 1, 1); // Ensure newVal doesn't go below 1
            }
    
            $input.val(newVal).trigger('change'); // Update and trigger change to handle states
        });
    
        // Handle key up and paste events for the input
        $('input[name="quantity"]').on('keyup paste', function(e) {
          e.preventDefault();
            var $input = $(this);
            setTimeout(function() { // Timeout for paste event to get the pasted value
                var value = parseInt($input.val(), 10) || 1; // Default to 1 if input is not a number
                var min = parseInt($input.attr('min'), 10) || 1;
                var max = parseInt($input.attr('max'), 10);
    
                // Correct the value if it's outside the min/max range
                if (max && value > max) {
                    $input.val(max);
                } else if (value < min) {
                    $input.val(min);
                } else {
                    $input.val(value);
                }
    
                updateButtonStates($input); // Update button states
            }, 0);
        }).change(function() {
            updateButtonStates($(this)); // Also update button states when input changes
        });
    
        // Initial update for button states
        $('input[name="quantity"]').each(function() {
            updateButtonStates($(this));
        });

        updateProgressBar(95);
      },

      error: function (request, status, error) {
        updateProgressBar(100);
        console.log('products api error', error);
      }
    });

    function simulateProgress() {
      window.progress = 10;
      var interval = setInterval(function () {
        progress += 10; // Increment by 10%
        updateProgressBar(progress);
        if (progress >= 55) {
          clearInterval(interval);
        }
      }, 500); //milliseconds
    }

    function updateProgressBar(percent) {
      progress = percent;
      document.querySelectorAll('.pf-progress-bar-inner').forEach(function (element) {
        element.style.setProperty('--percent', percent + '%');
      });
      const elements = document.querySelectorAll('.pf-progress-bar-inner .inside');
      elements.forEach(function (element) {
        element.textContent = percent + '%';
      });
      const outside = document.querySelectorAll('.pf-progress-bar-inner .outside');
      outside.forEach(function (element) {
        element.textContent = percent + '%';
      });
    }

    function updateButtonStates($input) {
        var min = parseInt($input.attr('min'), 10) || 1; // Default minimum to 1 if not set
        var max = parseInt($input.attr('max'), 10); // Max can be undefined
        var value = parseInt($input.val(), 10);

        $input.siblings('button[data-quantity-action="decrease"]').prop('disabled', value <= min);
        $input.siblings('button[data-quantity-action="increase"]').prop('disabled', value >= max);
    }

  }
}