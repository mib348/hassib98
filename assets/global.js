//mib348
// if (performance.navigation.type == 2 && (window.location.pathname === "/pages/bestellen" || window.location.pathname === "/pages/datum" || window.location.pathname === "/pages/order-menue")) {
//     sessionStorage.clear();
//     window.location.href = "/pages/bestellen";
// }


function generateShortUUID() {
    return 'xxxxxx'.replace(/[x]/g, function() {
        return (Math.random() * 16 | 0).toString(16);
    });
}

if (localStorage.getItem("uuid") == null) {
  const uuid = generateShortUUID();
  localStorage.setItem("uuid", uuid);
}

// if (localStorage.getItem("location") != null && sessionStorage.getItem("location") == null) {
//   sessionStorage.setItem("location", localStorage.getItem("location"));
// }

document.addEventListener('DOMContentLoaded', function() {
    if (window.location.pathname === "/pages/order-menue") {
        history.pushState(null, null, window.location.href); // Push current state to history

                // alert('from push state');
        window.onpopstate = function(event) {
            sessionStorage.clear();

            $.ajax({
              type: "POST",
              url: window.Shopify.routes.root + "cart/clear.js",
              dataType: "json",
              success: function (response) {
                // alert('from cart');
                window.location.href = "/pages/bestellen";
               },
              error: function (xhr, status, error) {
                alert("Cart clear error:");
                console.log("Cart clear error:", error);
              },
            });
          
        };
    }
    if (window.location.pathname === "/pages/menue") {
      // Select all elements with the class .product_media
      document.querySelectorAll('.product_media').forEach(function(element) {
        // Find the first .pf-main-media element within the current .product_media element
        var pfMainMedia = element.querySelector('.pf-main-media');
        
        // Get the 'data-href' attribute and append '?page=menue'
        var href = pfMainMedia.getAttribute('data-href') + '?page=menue';
    
        // Append additional query parameters if they exist in sessionStorage
        if (sessionStorage.getItem("location") != null)
          href += "&location=" + sessionStorage.getItem("location");
        if (sessionStorage.getItem("date") != null)
          href += "&date=" + sessionStorage.getItem("date");
        if (localStorage.getItem("uuid") != null)
          href += "&uuid=" + localStorage.getItem("uuid");
        if (sessionStorage.getItem("no_station") != null)
          href += "&no_station=" + sessionStorage.getItem("no_station");
        if (sessionStorage.getItem("immediate_inventory") != null)
          href += "&immediate_inventory=" + sessionStorage.getItem("immediate_inventory");
        if (sessionStorage.getItem("b_additional_inventory") != null)
          href += "&additional_inventory=" + sessionStorage.getItem("b_additional_inventory");
        if (sessionStorage.getItem("additional_inventory_time") != null)
          href += "&additional_inventory_time=" + sessionStorage.getItem("additional_inventory_time");
    
        // Set the updated href back as the 'data-href' attribute
        pfMainMedia.setAttribute('data-href', href);
      });
    }

    if (window.location.pathname.includes('/products/')) {
        const queryParams = new URLSearchParams(window.location.search);
    
        // Check if 'location', 'date', and 'uuid' parameters are missing in the URL
        if (!queryParams.has('location') || !queryParams.has('date') || !queryParams.has('uuid')) {
            // Get all elements with the specified class names and hide them
            var quantityElements = document.querySelectorAll('.product-form__quantity');
            var submitElements = document.querySelectorAll('.product-form__submit');
            var buttonElements = document.querySelectorAll('.product-form__buttons');
    
            // Hide quantity and submit elements
            quantityElements.forEach(function(element) {
                element.style.display = 'none';
            });
            submitElements.forEach(function(element) {
                element.style.display = 'none';
            });
    
            // Clear session storage to avoid adding different dates against the products to the cart.
            sessionStorage.clear();
            $.ajax({
              type: "POST",
              url: window.Shopify.routes.root + "cart/clear.js",
              dataType: "json",
              success: function (response) {
               },
              error: function (xhr, status, error) {
                console.log("Cart clear error:", error);
              },
            });
    
            // Update the innerHTML of the button elements
            buttonElements.forEach(function(element) {
                element.innerHTML = '<a class="product-form__submit button button--full-width button--primary" href="/pages/bestellen">Bitte bestellen Sie hier</a>';
            });
        }else {
            // Console log the parameters if they exist
            console.log('Location:', queryParams.get('location'));
            console.log('Date:', queryParams.get('date'));
            console.log('UUID:', queryParams.get('uuid'));
        }
    }


});


// Function to format today's date as dd-mm-yyyy for Germany
function getFormattedDate() {
    // Use Intl.DateTimeFormat to get the correct current date in Germany
    const options = {
        timeZone: 'Europe/Berlin',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour12: false
    };

    const formatter = new Intl.DateTimeFormat([], options);
    const parts = formatter.formatToParts(new Date());

    let dateObj = {};
    parts.forEach(({ type, value }) => {
        dateObj[type] = value;
    });

    return `${dateObj.day}-${dateObj.month}-${dateObj.year}`;
}

if (window.location.pathname === "/pages/order-menue" || (window.location.pathname === "/pages/datum" && sessionStorage.getItem("location") == null && localStorage.getItem("location") == null)) {
  // Check if the session storage 'date' exists and is not null
  if (sessionStorage.getItem("date") !== null) {
      const storedDate = sessionStorage.getItem("date");
      const todayDate = getFormattedDate();
  
      // Compare the stored date with today's date
      if (new Date(storedDate.split('-').reverse().join('-')) < new Date(todayDate.split('-').reverse().join('-'))) {
          // Update to today's date if stored date is less
          sessionStorage.setItem("date", todayDate);
      }
  } else {
      // If 'date' does not exist in sessionStorage, set it to today's date
      sessionStorage.setItem("date", getFormattedDate());
  }

  // alert('from session storage');
  const queryParams = new URLSearchParams(window.location.search);
  if(queryParams.get('location') && sessionStorage.getItem("location") == null){
    sessionStorage.setItem("location", queryParams.get('location'));
  }
  // alert(sessionStorage.getItem("location"));
}


if (window.jQuery) {
  let $ = window.jQuery;
  
  //skip inventory handling for Orders of Location: Delivery
  if (window.location.pathname === "/pages/order-menue") {
    if(sessionStorage.getItem("location") == "Delivery"){
      $(".order_qty").find("input").attr("max", 99);
      $(".qty_portion").hide();
      // alert('from Delivery page');
    }
  }
  
  // if (window.history && window.history.pushState) {
  //     window.history.pushState('', null, window.location.pathname);
  //     $(window).on('popstate', function() {
  //         sessionStorage.clear();
  //     });
  // }


//when the "bestellen" site loads, it should check whether their is already a location and date in the session -&gt; if yes it should redirect to the meunue page directly otherwise just display the normal page
if (window.location.pathname === "/pages/bestellen") {
  if(sessionStorage.getItem("location") == "Delivery" )
    sessionStorage.clear();
  
  if (
    sessionStorage.getItem("location") == null &&
    sessionStorage.getItem("date") == null && localStorage.getItem("location") == null
  ) {
  } else if (sessionStorage.getItem("location") == null && localStorage.getItem("location") != null) {
    $("#stationDropdown").html(localStorage.getItem("location"));
    $("#stationDropdown").attr('style', "background-color:black;");
    $('#next_button').css('display', 'inline-block');
  } else if (sessionStorage.getItem("date") == null) {
    window.location.replace("/pages/datum");
  } else {
    $.ajax({
          url:"https://app.sushi.catering/updateSelectedDate/" + sessionStorage.getItem("date"),
          type:"GET",
          data:{"uuid":localStorage.getItem("uuid")},
          cache:false,
          async:false,
          dataType:"json",
          success:function(data){
            // console.log(data);
            //window.location.href = "/pages/order-menue?location=" + sessionStorage.getItem("location") + "&date=" + sessionStorage.getItem("date");
            window.location.href = "/pages/order-menue?location=" + sessionStorage.getItem("location") + "&date=" + sessionStorage.getItem("date") + "&immediate_inventory=" + sessionStorage.getItem("immediate_inventory") + "&no_station=" + sessionStorage.getItem("no_station")  + "&additional_inventory=" + sessionStorage.getItem("b_additional_inventory")  + "&additional_inventory_time=" + sessionStorage.getItem("additional_inventory_time") + "&uuid=" + localStorage.getItem("uuid");
          },
          error: function (request, status, error) {
              alert('set selected date error: global ');
              console.log('set selected date error: global ', error);
          }
      });
  }
} 
else if(window.location.pathname === "/cart"){
  window.min_order_limit = 0;
  if(sessionStorage.getItem("location") == "Delivery"){
      $(".incorrent_item_agree_cb_portion").hide();

      $.ajax({
          type: "GET",
          url: "https://app.sushi.catering/getLocations/Delivery",
          async: false,
          cache: false,
          // data: {
          //     items: JSON.stringify(response.items)
          // },
          dataType: "json",
          success: function(data) {
            min_order_limit = data.min_order_limit;
              //window.location.href = "/checkout";
          },
          error: function() {
              console.log('Cart Check Delivery Inventory api error');
          }
      });
  }
  
  $.ajax({
      type: "GET",
      url: window.Shopify.routes.root + "cart.js",
      dataType: "json",
      success: function (response) {
        removePastDateProducts(response);

          let items = response.items;

          $.ajax({
              type: "POST",
              url: "https://app.sushi.catering/api/checkOrderInventory",
              async: false,
              cache: false,
              data: {
                  items: JSON.stringify(response.items)
              },
              dataType: "json",
              success: function(response) {
                  //window.location.href = "/checkout";
                  if(response.sameday_preorder_time_expired == 1){
                      alert('Du kannst nur noch eine Sofortbestellung tätigen.');
                      sessionStorage.clear();
                      $(".location_bar").remove();
                  
                      $.ajax({
                        type: "POST",
                        url: window.Shopify.routes.root + "cart/clear.js",
                        dataType: "json",
                        success: function (response) {
                          window.location.href = "/pages/bestellen";
                         },
                        error: function (xhr, status, error) {
                          alert("Cart clear error:");
                          console.log("Cart clear error:", error);
                        },
                      });
                  }
              },
              error: function() {
                  console.log('Cart Check order Inventory api error');
              }
          });
      },
      error:function(){        
      }
  });
    function removeProductFromCart(productId) {
      return new Promise((resolve, reject) => {
        var changeUrl = '/cart/change.js';
        var payload = {
          id: productId,
          quantity: 0 // Setting quantity to 0 will remove the item
        };
    
        $.ajax({
          url: changeUrl,
          type: 'POST',
          dataType: 'json',
          data: payload,
          success: function(response) {
            console.log('Product removed from cart:', productId);
            resolve(productId); // Resolve the promise when the product is successfully removed
          },
          error: function(xhr, status, error) {
            console.error('Failed to remove product from cart:', productId, status, error);
            reject(error); // Reject the promise if an error occurs
          }
        });
      });
    }

    function removePastDateProducts(response) {
      var removalPromises = [];
      var bReload = false;
          
      $.each(response.items, function(index, product) {
        var dateParts = product.properties.date.split('-');
        var productDate = new Date(dateParts[2], dateParts[1] - 1, dateParts[0]);
    
        var currentDate = new Date(new Date().toLocaleString("en-US", {timeZone: "Europe/Berlin"}));
        currentDate.setHours(0, 0, 0, 0); // Remove time portion for comparison
    
        if (productDate < currentDate) {
          bReload = true;
          removalPromises.push(removeProductFromCart(product.id));
        }
      });
    
      Promise.all(removalPromises).then(function(results) {
        console.log('All removable items have been removed:', results);
        if(bReload === true)
          window.location.reload(); // Reload the page
      }).catch(function(error) {
        console.error('An error occurred while removing items:', error);
      });
    }


}
else {
   if (window.location.pathname === "/pages/order-menue" || (window.location.pathname === "/pages/datum" && sessionStorage.getItem("location") == null && localStorage.getItem("location") == null)) {
     
    // Parse the query string
    const queryParams = new URLSearchParams(window.location.search);
  
    // Check if 'location' and 'date' parameters are missing in the URL
    //if (!queryParams.has('location') && !queryParams.has('date')) {
      if (
        sessionStorage.getItem("location") == null &&
        sessionStorage.getItem("date") == null
      ) {
        alert('location & date empty');
        window.location.href = "/pages/bestellen";
      } else if (sessionStorage.getItem("location") == null) {
        alert('location empty');
        window.location.href = "/pages/bestellen";
      } else if (sessionStorage.getItem("date") == null) {
        window.location.replace("/pages/datum");
      } else {
        //window.location.href = "/pages/order-menue?location=" + sessionStorage.getItem("location") + "&date=" + sessionStorage.getItem("date");
      }
    //}
  }
  else if(window.location.pathname === "/pages/datum" && sessionStorage.getItem("date") != null){
    sessionStorage.clear();
    window.location.replace("/pages/bestellen");
  }

}



  var strLocation =
    sessionStorage.getItem("location") != null
      ? sessionStorage.getItem("location")
      : "";
  var strDate =
    sessionStorage.getItem("date") != null
      ? sessionStorage.getItem("date")
      : "";

  if (
    sessionStorage.getItem("location") == null &&
    sessionStorage.getItem("date") == null
  )
    $(".location_bar").remove();
  else {
    $(".location_bar_text").html("&nbsp;" + strLocation + "&nbsp;" + strDate);
  }

  $(document).on("click", ".location_bar_closer", function () {
    sessionStorage.clear();
    $(".location_bar").remove();

    $.ajax({
      type: "POST",
      url: window.Shopify.routes.root + "cart/clear.js",
      dataType: "json",
      success: function (response) {
        window.location.href = "/";
       },
      error: function (xhr, status, error) {
        alert("Cart clear error:");
        console.log("Cart clear error:", error);
      },
    });

  });

  $(document).on("click", ".station", function (e) {
    e.preventDefault();

    var href = $(this).attr("href");

    var strLocation = $(this).html();
    $("div.shopify-section.shopify-section-group-header-group")
      .not(".section-header")
      .find("p")
      .html(" " + strLocation + " " + strDate);

    if (strLocation != localStorage.getItem("location") && confirm("Möchten Sie diesen Standort für die zukünftige Verwendung speichern?") == true) {
      localStorage.setItem("location", strLocation);
      sessionStorage.setItem("location", strLocation);
    } else {
      sessionStorage.setItem("location", strLocation);
    }

    //window.location.href = href + "?location=" + strLocation;
    //window.location.replace(href + "?location=" + strLocation);
    location.replace(href + "?location=" + strLocation);
  });

  $(document).on("click", "#next_button", function (e) {
    e.preventDefault();

    var href = $(this).attr("href");

    // var strLocation = $(this).html();
    // $("div.shopify-section.shopify-section-group-header-group")
    //   .not(".section-header")
    //   .find("p")
    //   .html(" " + strLocation + " " + strDate);

    //window.location.href = href + "?location=" + strLocation;
    //window.location.replace(href + "?location=" + strLocation);
    sessionStorage.setItem("location", localStorage.getItem("location"));
    location.replace(href + "?location=" + localStorage.getItem("location"));
  });

  $(document).on("click", "#home_delivery_btn", function (e) {
    e.preventDefault();

    var href = $(this).attr("href");
    location.replace(href);
  });

  // Shopify.onCartUpdate = function(cart) {
  //   alert('There are now ' + cart.item_count + ' items in the cart.');
  // };  

  function comparePrices(minOrderLimit, currentTotal) {
      // Remove currency symbols and whitespace, replace comma with dot
      const minOrder = parseFloat(minOrderLimit.replace(',', '.'));
      const total = parseFloat(currentTotal.match(/\d+,\d+/)[0].replace(',', '.'));
  
      console.log('Minimum order:', minOrder);
      console.log('Current total:', total);

      if(minOrder > 0 && total < minOrder)
        return true;
      else 
        return false;
    
      // return {
      //     isValid: total >= minOrder,
      //     difference: (minOrder - total).toFixed(2)
      // };
  }

    $(document).on("click", "#checkout", function (e) {
        e.preventDefault();
        var el = $(this);
        var b_allowed = true;
        let order_price = 0;
    
        $.ajax({
          type: "GET",
          url: window.Shopify.routes.root + "cart.js",
          dataType: "json",
          success: function (response) {

            if(sessionStorage.getItem("location") == "Delivery"){
                // const minOrderLimit = "4,96";
                const currentTotal = $(".totals__total-value").html();
    
                //on home delivery the order amount must be bigger than x otherwise error must be thrown. i want to be able to choose x in the admin unter location delivery. can you please add this feature? you must check in cart if orderamount is big enough to enter checkout please.
                const result = comparePrices(min_order_limit, currentTotal);
                if (result == true) {
                    alert('Die Mindestlieferbestellmenge sollte betragen: €' + min_order_limit + ' EUR');
                    return false;
                }
            }
            
            var dateArray = [];
            // console.log(response);
            $.each(response.items, function (index, product) {
                dateArray.push(product.properties.date);
                var stored_qty = parseInt(product.properties.max_quantity, 10);

                if(sessionStorage.getItem("location") == "Delivery"){
                  stored_qty = 99;
                  // order_qty += product.quantity;
                }
              
                if (product.quantity >= stored_qty) {
                    $( 'input.quantity__input[data-quantity-variant-id="' + product.id + '"]' ) .closest('button[name="plus"]').attr('disabled', true);
                    $( 'input.quantity__input[data-quantity-variant-id="' + product.id + '"]' ) .closest('button[name="plus"]').prop('disabled', true);
                }
                if (product.quantity > stored_qty) {
                  b_allowed = false;
                  if (!$( 'input.quantity__input[data-quantity-variant-id="' + product.id + '"]' ).closest(".cart-item__quantity").find("small.lowstock").length) {
                    $( 'input.quantity__input[data-quantity-variant-id="' + product.id + '"]' ) .closest(".cart-item__quantity") .append( '<small class="lowstock" style="color:red;">Es sind nur ' + stored_qty + " Artikel verfügbar</small>" );

                    var elementTop = $( 'input.quantity__input[data-quantity-variant-id="' + product.id + '"]' ).closest(".cart-item__quantity").offset().top;
                    window.scrollTo({
                        top: elementTop - 150,
                        behavior: "smooth"
                    });
                  }
                }
            });
    
            // Checking for all items having the same date
            var allSameDate = dateArray.every((val, i, arr) => val === arr[0]);
    
            //console.log(dateArray);
    
            if (!allSameDate) {
              alert('Sie können nur Artikel hinzufügen, die das gleiche Vorbestellungsdatum haben.');
              b_allowed = false;
            }
    
            // if (!$('#incorrent_item_agree').is(':checked')) {
            //   alert("Um zur Kasse zu gehen und fortzufahren, müssen Sie zustimmen, dass Sie keine Artikel aus Bestellungen Dritter annehmen, und dass bei Entnahme eines falschen Artikels eine 20€-Gebühr pro Artikel fällig wird.");
            //   b_allowed = false;
            // }

            if(sessionStorage.getItem("location") != "Delivery"){
                // $(".incorrent_item_agree_cb_portion").hide();
                if (!$('#incorrent_item_agree').is(':checked')) {
                  alert("Um zur Kasse zu gehen und fortzufahren, müssen Sie zustimmen, dass Sie keine Artikel aus Bestellungen Dritter annehmen, und dass bei Entnahme eines falschen Artikels eine 20€-Gebühr pro Artikel fällig wird.");
                  b_allowed = false;
                }
            }
            // else{
            //    if(min_order_limit > 0 && order_qty < min_order_limit){
            //      alert('Die Mindestlieferbestellmenge sollte betragen: ' + min_order_limit);
            //      b_allowed = false;
            //      return false;
            //    }
            // }
    
            if (!$('#agree').is(':checked')) {
              alert("Um zur Kasse gehen zu können, müssen Sie den Allgemeinen Geschäftsbedingungen zustimmen.");
              b_allowed = false;
              return false;
            }
    
            // if (!$('#third_party').is(':checked')) {
            //   alert("Um zur Kasse zu gehen, müssen Sie zustimmen, dass Sie keine Artikel aus Bestellungen Dritter annehmen.");
            //   b_allowed = false;
            // }

            if(sessionStorage.getItem("location") == "Delivery"){
              b_allowed = false;
              window.location.href = "/checkout";              
            }

            

    // console.log(b_allowed);
              if (b_allowed){
                  let items = response.items;
                  //check product available quantity
                  $.ajax({
                        type: "POST",
                        url: "https://app.sushi.catering/api/checkCartProductsQty",
                        async: false,
                        cache: false,
                        data: {
                            items: JSON.stringify(response.items)
                        },
                        dataType: "json",
                        success: function(response) {                    
                            // Check if any product quantity is zero
                            var products = response;
                            var allProductsAvailable = true;
                            for (var i = 0; i < products.length; i++) {
                                if (products[i].qty == 0) {
                                    alert(products[i].name + ' ist Ausverkauft');
                                    // Check if the message has already been appended
                                    if (!$( 'input.quantity__input[data-quantity-variant-id="' + products[i].variant_id + '"]' ).closest(".cart-item__quantity").find("small.soldout").length) {
                                        $( 'input.quantity__input[data-quantity-variant-id="' + products[i].variant_id + '"]' ) .closest(".cart-item__quantity") .append( '<small class="soldout" style="color:red;">Ausverkauft</small>' );
                                    }
                                    allProductsAvailable = false;

                                    var elementTop = $( 'input.quantity__input[data-quantity-variant-id="' + products[i].variant_id + '"]' ).closest(".cart-item__quantity").offset().top;
                                    window.scrollTo({
                                        top: elementTop - 150,
                                        behavior: "smooth"
                                    });
                                }
                                else if (products[i].qty < items[i].quantity) {
                                    alert('Für ' + products[i].name + ' sind nur noch ' + products[i].qty + ' Artikel übrig');
                                    if (!$( 'input.quantity__input[data-quantity-variant-id="' + products[i].variant_id + '"]' ).closest(".cart-item__quantity").find("small.soldout").length) {
                                        $( 'input.quantity__input[data-quantity-variant-id="' + products[i].variant_id + '"]' ) .closest(".cart-item__quantity") .append( '<small class="soldout" style="color:red;">' + products[i].qty + ' Produkte verfügbar</small>' );
                                    }
                                    allProductsAvailable = false;

                                    var elementTop = $( 'input.quantity__input[data-quantity-variant-id="' + products[i].variant_id + '"]' ).closest(".cart-item__quantity").offset().top;
                                    window.scrollTo({
                                        top: elementTop - 150,
                                        behavior: "smooth"
                                    });
                                }
                            }
                    
                            // Block further execution if any product quantity is zero
                            if (!allProductsAvailable) {
                                console.log("Some product quantities are zero. Further execution blocked.");
                                return;
                            }

                            console.log(allProductsAvailable);
                    
                            window.location.href = "/checkout";
                        },
                        error: function() {
                            console.log('Cart quantity fetch error');
                        }
                    });
                    

              } 
          }
        });
    });
    

}

function getFocusableElements(container) {
  return Array.from(
    container.querySelectorAll(
      "summary, a[href], button:enabled, [tabindex]:not([tabindex^='-']), [draggable], area, input:not([type=hidden]):enabled, select:enabled, textarea:enabled, object, iframe"
    )
  );
}

document.querySelectorAll('[id^="Details-"] summary').forEach((summary) => {
  summary.setAttribute("role", "button");
  summary.setAttribute(
    "aria-expanded",
    summary.parentNode.hasAttribute("open")
  );

  if (summary.nextElementSibling.getAttribute("id")) {
    summary.setAttribute("aria-controls", summary.nextElementSibling.id);
  }

  summary.addEventListener("click", (event) => {
    event.currentTarget.setAttribute(
      "aria-expanded",
      !event.currentTarget.closest("details").hasAttribute("open")
    );
  });

  if (summary.closest("header-drawer, menu-drawer")) return;
  summary.parentElement.addEventListener("keyup", onKeyUpEscape);
});

const trapFocusHandlers = {};

function trapFocus(container, elementToFocus = container) {
  var elements = getFocusableElements(container);
  var first = elements[0];
  var last = elements[elements.length - 1];

  removeTrapFocus();

  trapFocusHandlers.focusin = (event) => {
    if (
      event.target !== container &&
      event.target !== last &&
      event.target !== first
    )
      return;

    document.addEventListener("keydown", trapFocusHandlers.keydown);
  };

  trapFocusHandlers.focusout = function () {
    document.removeEventListener("keydown", trapFocusHandlers.keydown);
  };

  trapFocusHandlers.keydown = function (event) {
    if (event.code.toUpperCase() !== "TAB") return; // If not TAB key
    // On the last focusable element and tab forward, focus the first element.
    if (event.target === last && !event.shiftKey) {
      event.preventDefault();
      first.focus();
    }

    //  On the first focusable element and tab backward, focus the last element.
    if (
      (event.target === container || event.target === first) &&
      event.shiftKey
    ) {
      event.preventDefault();
      last.focus();
    }
  };

  document.addEventListener("focusout", trapFocusHandlers.focusout);
  document.addEventListener("focusin", trapFocusHandlers.focusin);

  elementToFocus.focus();

  if (
    elementToFocus.tagName === "INPUT" &&
    ["search", "text", "email", "url"].includes(elementToFocus.type) &&
    elementToFocus.value
  ) {
    elementToFocus.setSelectionRange(0, elementToFocus.value.length);
  }
}

// Here run the querySelector to figure out if the browser supports :focus-visible or not and run code based on it.
try {
  document.querySelector(":focus-visible");
} catch (e) {
  focusVisiblePolyfill();
}

function focusVisiblePolyfill() {
  const navKeys = [
    "ARROWUP",
    "ARROWDOWN",
    "ARROWLEFT",
    "ARROWRIGHT",
    "TAB",
    "ENTER",
    "SPACE",
    "ESCAPE",
    "HOME",
    "END",
    "PAGEUP",
    "PAGEDOWN",
  ];
  let currentFocusedElement = null;
  let mouseClick = null;

  window.addEventListener("keydown", (event) => {
    if (navKeys.includes(event.code.toUpperCase())) {
      mouseClick = false;
    }
  });

  window.addEventListener("mousedown", (event) => {
    mouseClick = true;
  });

  window.addEventListener(
    "focus",
    () => {
      if (currentFocusedElement)
        currentFocusedElement.classList.remove("focused");

      if (mouseClick) return;

      currentFocusedElement = document.activeElement;
      currentFocusedElement.classList.add("focused");
    },
    true
  );
}

function pauseAllMedia() {
  document.querySelectorAll(".js-youtube").forEach((video) => {
    video.contentWindow.postMessage(
      '{"event":"command","func":"' + "pauseVideo" + '","args":""}',
      "*"
    );
  });
  document.querySelectorAll(".js-vimeo").forEach((video) => {
    video.contentWindow.postMessage('{"method":"pause"}', "*");
  });
  document.querySelectorAll("video").forEach((video) => video.pause());
  document.querySelectorAll("product-model").forEach((model) => {
    if (model.modelViewerUI) model.modelViewerUI.pause();
  });
}

function removeTrapFocus(elementToFocus = null) {
  document.removeEventListener("focusin", trapFocusHandlers.focusin);
  document.removeEventListener("focusout", trapFocusHandlers.focusout);
  document.removeEventListener("keydown", trapFocusHandlers.keydown);

  if (elementToFocus) elementToFocus.focus();
}

function onKeyUpEscape(event) {
  if (event.code.toUpperCase() !== "ESCAPE") return;

  const openDetailsElement = event.target.closest("details[open]");
  if (!openDetailsElement) return;

  const summaryElement = openDetailsElement.querySelector("summary");
  openDetailsElement.removeAttribute("open");
  summaryElement.setAttribute("aria-expanded", false);
  summaryElement.focus();
}

class QuantityInput extends HTMLElement {
  constructor() {
    super();
    this.input = this.querySelector("input");
    this.changeEvent = new Event("change", { bubbles: true });
    this.input.addEventListener("change", this.onInputChange.bind(this));
    this.querySelectorAll("button").forEach((button) =>
      button.addEventListener("click", this.onButtonClick.bind(this))
    );
  }

  quantityUpdateUnsubscriber = undefined;

  connectedCallback() {
    this.validateQtyRules();
    this.quantityUpdateUnsubscriber = subscribe(
      PUB_SUB_EVENTS.quantityUpdate,
      this.validateQtyRules.bind(this)
    );
  }

  disconnectedCallback() {
    if (this.quantityUpdateUnsubscriber) {
      this.quantityUpdateUnsubscriber();
    }
  }

  onInputChange(event) {
    this.validateQtyRules();
  }

  onButtonClick(event) {
    event.preventDefault();
    const previousValue = this.input.value;

    event.target.name === "plus" ? this.input.stepUp() : this.input.stepDown();
    if (previousValue !== this.input.value)
      this.input.dispatchEvent(this.changeEvent);
  }

  validateQtyRules() {
    const value = parseInt(this.input.value);
    if (this.input.min) {
      const min = parseInt(this.input.min);
      const buttonMinus = this.querySelector(".quantity__button[name='minus']");
      buttonMinus.classList.toggle("disabled", value <= min);
    }
    if (this.input.max) {
      const max = parseInt(this.input.max);
      const buttonPlus = this.querySelector(".quantity__button[name='plus']");
      buttonPlus.classList.toggle("disabled", value >= max);
    }
  }
}

customElements.define("quantity-input", QuantityInput);

function debounce(fn, wait) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn.apply(this, args), wait);
  };
}

function throttle(fn, delay) {
  let lastCall = 0;
  return function (...args) {
    const now = new Date().getTime();
    if (now - lastCall < delay) {
      return;
    }
    lastCall = now;
    return fn(...args);
  };
}

function fetchConfig(type = "json") {
  return {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: `application/${type}`,
    },
  };
}

/*
 * Shopify Common JS
 *
 */
if (typeof window.Shopify == "undefined") {
  window.Shopify = {};
}

Shopify.bind = function (fn, scope) {
  return function () {
    return fn.apply(scope, arguments);
  };
};

Shopify.setSelectorByValue = function (selector, value) {
  for (var i = 0, count = selector.options.length; i < count; i++) {
    var option = selector.options[i];
    if (value == option.value || value == option.innerHTML) {
      selector.selectedIndex = i;
      return i;
    }
  }
};

Shopify.addListener = function (target, eventName, callback) {
  target.addEventListener
    ? target.addEventListener(eventName, callback, false)
    : target.attachEvent("on" + eventName, callback);
};

Shopify.postLink = function (path, options) {
  options = options || {};
  var method = options["method"] || "post";
  var params = options["parameters"] || {};

  var form = document.createElement("form");
  form.setAttribute("method", method);
  form.setAttribute("action", path);

  for (var key in params) {
    var hiddenField = document.createElement("input");
    hiddenField.setAttribute("type", "hidden");
    hiddenField.setAttribute("name", key);
    hiddenField.setAttribute("value", params[key]);
    form.appendChild(hiddenField);
  }
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
};

Shopify.CountryProvinceSelector = function (
  country_domid,
  province_domid,
  options
) {
  this.countryEl = document.getElementById(country_domid);
  this.provinceEl = document.getElementById(province_domid);
  this.provinceContainer = document.getElementById(
    options["hideElement"] || province_domid
  );

  Shopify.addListener(
    this.countryEl,
    "change",
    Shopify.bind(this.countryHandler, this)
  );

  this.initCountry();
  this.initProvince();
};

Shopify.CountryProvinceSelector.prototype = {
  initCountry: function () {
    var value = this.countryEl.getAttribute("data-default");
    Shopify.setSelectorByValue(this.countryEl, value);
    this.countryHandler();
  },

  initProvince: function () {
    var value = this.provinceEl.getAttribute("data-default");
    if (value && this.provinceEl.options.length > 0) {
      Shopify.setSelectorByValue(this.provinceEl, value);
    }
  },

  countryHandler: function (e) {
    var opt = this.countryEl.options[this.countryEl.selectedIndex];
    var raw = opt.getAttribute("data-provinces");
    var provinces = JSON.parse(raw);

    this.clearOptions(this.provinceEl);
    if (provinces && provinces.length == 0) {
      this.provinceContainer.style.display = "none";
    } else {
      for (var i = 0; i < provinces.length; i++) {
        var opt = document.createElement("option");
        opt.value = provinces[i][0];
        opt.innerHTML = provinces[i][1];
        this.provinceEl.appendChild(opt);
      }

      this.provinceContainer.style.display = "";
    }
  },

  clearOptions: function (selector) {
    while (selector.firstChild) {
      selector.removeChild(selector.firstChild);
    }
  },

  setOptions: function (selector, values) {
    for (var i = 0, count = values.length; i < values.length; i++) {
      var opt = document.createElement("option");
      opt.value = values[i];
      opt.innerHTML = values[i];
      selector.appendChild(opt);
    }
  },
};

class MenuDrawer extends HTMLElement {
  constructor() {
    super();

    this.mainDetailsToggle = this.querySelector("details");

    this.addEventListener("keyup", this.onKeyUp.bind(this));
    this.addEventListener("focusout", this.onFocusOut.bind(this));
    this.bindEvents();
  }

  bindEvents() {
    this.querySelectorAll("summary").forEach((summary) =>
      summary.addEventListener("click", this.onSummaryClick.bind(this))
    );
    this.querySelectorAll("button:not(.localization-selector)").forEach(
      (button) =>
        button.addEventListener("click", this.onCloseButtonClick.bind(this))
    );
  }

  onKeyUp(event) {
    if (event.code.toUpperCase() !== "ESCAPE") return;

    const openDetailsElement = event.target.closest("details[open]");
    if (!openDetailsElement) return;

    openDetailsElement === this.mainDetailsToggle
      ? this.closeMenuDrawer(
          event,
          this.mainDetailsToggle.querySelector("summary")
        )
      : this.closeSubmenu(openDetailsElement);
  }

  onSummaryClick(event) {
    const summaryElement = event.currentTarget;
    const detailsElement = summaryElement.parentNode;
    const parentMenuElement = detailsElement.closest(".has-submenu");
    const isOpen = detailsElement.hasAttribute("open");
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    function addTrapFocus() {
      trapFocus(
        summaryElement.nextElementSibling,
        detailsElement.querySelector("button")
      );
      summaryElement.nextElementSibling.removeEventListener(
        "transitionend",
        addTrapFocus
      );
    }

    if (detailsElement === this.mainDetailsToggle) {
      if (isOpen) event.preventDefault();
      isOpen
        ? this.closeMenuDrawer(event, summaryElement)
        : this.openMenuDrawer(summaryElement);

      if (window.matchMedia("(max-width: 990px)")) {
        document.documentElement.style.setProperty(
          "--viewport-height",
          `${window.innerHeight}px`
        );
      }
    } else {
      setTimeout(() => {
        detailsElement.classList.add("menu-opening");
        summaryElement.setAttribute("aria-expanded", true);
        parentMenuElement && parentMenuElement.classList.add("submenu-open");
        !reducedMotion || reducedMotion.matches
          ? addTrapFocus()
          : summaryElement.nextElementSibling.addEventListener(
              "transitionend",
              addTrapFocus
            );
      }, 100);
    }
  }

  openMenuDrawer(summaryElement) {
    setTimeout(() => {
      this.mainDetailsToggle.classList.add("menu-opening");
    });
    summaryElement.setAttribute("aria-expanded", true);
    trapFocus(this.mainDetailsToggle, summaryElement);
    document.body.classList.add(`overflow-hidden-${this.dataset.breakpoint}`);
  }

  closeMenuDrawer(event, elementToFocus = false) {
    if (event === undefined) return;

    this.mainDetailsToggle.classList.remove("menu-opening");
    this.mainDetailsToggle.querySelectorAll("details").forEach((details) => {
      details.removeAttribute("open");
      details.classList.remove("menu-opening");
    });
    this.mainDetailsToggle
      .querySelectorAll(".submenu-open")
      .forEach((submenu) => {
        submenu.classList.remove("submenu-open");
      });
    document.body.classList.remove(
      `overflow-hidden-${this.dataset.breakpoint}`
    );
    removeTrapFocus(elementToFocus);
    this.closeAnimation(this.mainDetailsToggle);

    if (event instanceof KeyboardEvent)
      elementToFocus?.setAttribute("aria-expanded", false);
  }

  onFocusOut() {
    setTimeout(() => {
      if (
        this.mainDetailsToggle.hasAttribute("open") &&
        !this.mainDetailsToggle.contains(document.activeElement)
      )
        this.closeMenuDrawer();
    });
  }

  onCloseButtonClick(event) {
    const detailsElement = event.currentTarget.closest("details");
    this.closeSubmenu(detailsElement);
  }

  closeSubmenu(detailsElement) {
    const parentMenuElement = detailsElement.closest(".submenu-open");
    parentMenuElement && parentMenuElement.classList.remove("submenu-open");
    detailsElement.classList.remove("menu-opening");
    detailsElement
      .querySelector("summary")
      .setAttribute("aria-expanded", false);
    removeTrapFocus(detailsElement.querySelector("summary"));
    this.closeAnimation(detailsElement);
  }

  closeAnimation(detailsElement) {
    let animationStart;

    const handleAnimation = (time) => {
      if (animationStart === undefined) {
        animationStart = time;
      }

      const elapsedTime = time - animationStart;

      if (elapsedTime < 400) {
        window.requestAnimationFrame(handleAnimation);
      } else {
        detailsElement.removeAttribute("open");
        if (detailsElement.closest("details[open]")) {
          trapFocus(
            detailsElement.closest("details[open]"),
            detailsElement.querySelector("summary")
          );
        }
      }
    };

    window.requestAnimationFrame(handleAnimation);
  }
}

customElements.define("menu-drawer", MenuDrawer);

class HeaderDrawer extends MenuDrawer {
  constructor() {
    super();
  }

  openMenuDrawer(summaryElement) {
    this.header = this.header || document.querySelector(".section-header");
    this.borderOffset =
      this.borderOffset ||
      this.closest(".header-wrapper").classList.contains(
        "header-wrapper--border-bottom"
      )
        ? 1
        : 0;
    document.documentElement.style.setProperty(
      "--header-bottom-position",
      `${parseInt(
        this.header.getBoundingClientRect().bottom - this.borderOffset
      )}px`
    );
    this.header.classList.add("menu-open");

    setTimeout(() => {
      this.mainDetailsToggle.classList.add("menu-opening");
    });

    summaryElement.setAttribute("aria-expanded", true);
    window.addEventListener("resize", this.onResize);
    trapFocus(this.mainDetailsToggle, summaryElement);
    document.body.classList.add(`overflow-hidden-${this.dataset.breakpoint}`);
  }

  closeMenuDrawer(event, elementToFocus) {
    if (!elementToFocus) return;
    super.closeMenuDrawer(event, elementToFocus);
    this.header.classList.remove("menu-open");
    window.removeEventListener("resize", this.onResize);
  }

  onResize = () => {
    this.header &&
      document.documentElement.style.setProperty(
        "--header-bottom-position",
        `${parseInt(
          this.header.getBoundingClientRect().bottom - this.borderOffset
        )}px`
      );
    document.documentElement.style.setProperty(
      "--viewport-height",
      `${window.innerHeight}px`
    );
  };
}

customElements.define("header-drawer", HeaderDrawer);

class ModalDialog extends HTMLElement {
  constructor() {
    super();
    this.querySelector('[id^="ModalClose-"]').addEventListener(
      "click",
      this.hide.bind(this, false)
    );
    this.addEventListener("keyup", (event) => {
      if (event.code.toUpperCase() === "ESCAPE") this.hide();
    });
    if (this.classList.contains("media-modal")) {
      this.addEventListener("pointerup", (event) => {
        if (
          event.pointerType === "mouse" &&
          !event.target.closest("deferred-media, product-model")
        )
          this.hide();
      });
    } else {
      this.addEventListener("click", (event) => {
        if (event.target === this) this.hide();
      });
    }
  }

  connectedCallback() {
    if (this.moved) return;
    this.moved = true;
    document.body.appendChild(this);
  }

  show(opener) {
    this.openedBy = opener;
    const popup = this.querySelector(".template-popup");
    document.body.classList.add("overflow-hidden");
    this.setAttribute("open", "");
    if (popup) popup.loadContent();
    trapFocus(this, this.querySelector('[role="dialog"]'));
    window.pauseAllMedia();
  }

  hide() {
    document.body.classList.remove("overflow-hidden");
    document.body.dispatchEvent(new CustomEvent("modalClosed"));
    this.removeAttribute("open");
    removeTrapFocus(this.openedBy);
    window.pauseAllMedia();
  }
}
customElements.define("modal-dialog", ModalDialog);

class ModalOpener extends HTMLElement {
  constructor() {
    super();

    const button = this.querySelector("button");

    if (!button) return;
    button.addEventListener("click", () => {
      const modal = document.querySelector(this.getAttribute("data-modal"));
      if (modal) modal.show(button);
    });
  }
}
customElements.define("modal-opener", ModalOpener);

class DeferredMedia extends HTMLElement {
  constructor() {
    super();
    const poster = this.querySelector('[id^="Deferred-Poster-"]');
    if (!poster) return;
    poster.addEventListener("click", this.loadContent.bind(this));
  }

  loadContent(focus = true) {
    window.pauseAllMedia();
    if (!this.getAttribute("loaded")) {
      const content = document.createElement("div");
      content.appendChild(
        this.querySelector("template").content.firstElementChild.cloneNode(true)
      );

      this.setAttribute("loaded", true);
      const deferredElement = this.appendChild(
        content.querySelector("video, model-viewer, iframe")
      );
      if (focus) deferredElement.focus();
      if (
        deferredElement.nodeName == "VIDEO" &&
        deferredElement.getAttribute("autoplay")
      ) {
        // force autoplay for safari
        deferredElement.play();
      }
    }
  }
}

customElements.define("deferred-media", DeferredMedia);

class SliderComponent extends HTMLElement {
  constructor() {
    super();
    this.slider = this.querySelector('[id^="Slider-"]');
    this.sliderItems = this.querySelectorAll('[id^="Slide-"]');
    this.enableSliderLooping = false;
    this.currentPageElement = this.querySelector(".slider-counter--current");
    this.pageTotalElement = this.querySelector(".slider-counter--total");
    this.prevButton = this.querySelector('button[name="previous"]');
    this.nextButton = this.querySelector('button[name="next"]');

    if (!this.slider || !this.nextButton) return;

    this.initPages();
    const resizeObserver = new ResizeObserver((entries) => this.initPages());
    resizeObserver.observe(this.slider);

    this.slider.addEventListener("scroll", this.update.bind(this));
    this.prevButton.addEventListener("click", this.onButtonClick.bind(this));
    this.nextButton.addEventListener("click", this.onButtonClick.bind(this));
  }

  initPages() {
    this.sliderItemsToShow = Array.from(this.sliderItems).filter(
      (element) => element.clientWidth > 0
    );
    if (this.sliderItemsToShow.length < 2) return;
    this.sliderItemOffset =
      this.sliderItemsToShow[1].offsetLeft -
      this.sliderItemsToShow[0].offsetLeft;
    this.slidesPerPage = Math.floor(
      (this.slider.clientWidth - this.sliderItemsToShow[0].offsetLeft) /
        this.sliderItemOffset
    );
    this.totalPages = this.sliderItemsToShow.length - this.slidesPerPage + 1;
    this.update();
  }

  resetPages() {
    this.sliderItems = this.querySelectorAll('[id^="Slide-"]');
    this.initPages();
  }

  update() {
    // Temporarily prevents unneeded updates resulting from variant changes
    // This should be refactored as part of https://github.com/Shopify/dawn/issues/2057
    if (!this.slider || !this.nextButton) return;

    const previousPage = this.currentPage;
    this.currentPage =
      Math.round(this.slider.scrollLeft / this.sliderItemOffset) + 1;

    if (this.currentPageElement && this.pageTotalElement) {
      this.currentPageElement.textContent = this.currentPage;
      this.pageTotalElement.textContent = this.totalPages;
    }

    if (this.currentPage != previousPage) {
      this.dispatchEvent(
        new CustomEvent("slideChanged", {
          detail: {
            currentPage: this.currentPage,
            currentElement: this.sliderItemsToShow[this.currentPage - 1],
          },
        })
      );
    }

    if (this.enableSliderLooping) return;

    if (
      this.isSlideVisible(this.sliderItemsToShow[0]) &&
      this.slider.scrollLeft === 0
    ) {
      this.prevButton.setAttribute("disabled", "disabled");
    } else {
      this.prevButton.removeAttribute("disabled");
    }

    if (
      this.isSlideVisible(
        this.sliderItemsToShow[this.sliderItemsToShow.length - 1]
      )
    ) {
      this.nextButton.setAttribute("disabled", "disabled");
    } else {
      this.nextButton.removeAttribute("disabled");
    }
  }

  isSlideVisible(element, offset = 0) {
    const lastVisibleSlide =
      this.slider.clientWidth + this.slider.scrollLeft - offset;
    return (
      element.offsetLeft + element.clientWidth <= lastVisibleSlide &&
      element.offsetLeft >= this.slider.scrollLeft
    );
  }

  onButtonClick(event) {
    event.preventDefault();
    const step = event.currentTarget.dataset.step || 1;
    this.slideScrollPosition =
      event.currentTarget.name === "next"
        ? this.slider.scrollLeft + step * this.sliderItemOffset
        : this.slider.scrollLeft - step * this.sliderItemOffset;
    this.setSlidePosition(this.slideScrollPosition);
  }

  setSlidePosition(position) {
    this.slider.scrollTo({
      left: position,
    });
  }
}

customElements.define("slider-component", SliderComponent);

class SlideshowComponent extends SliderComponent {
  constructor() {
    super();
    this.sliderControlWrapper = this.querySelector(".slider-buttons");
    this.enableSliderLooping = true;

    if (!this.sliderControlWrapper) return;

    this.sliderFirstItemNode = this.slider.querySelector(".slideshow__slide");
    if (this.sliderItemsToShow.length > 0) this.currentPage = 1;

    this.announcementBarSlider = this.querySelector(".announcement-bar-slider");
    // Value below should match --duration-announcement-bar CSS value
    this.announcerBarAnimationDelay = this.announcementBarSlider ? 250 : 0;

    this.sliderControlLinksArray = Array.from(
      this.sliderControlWrapper.querySelectorAll(".slider-counter__link")
    );
    this.sliderControlLinksArray.forEach((link) =>
      link.addEventListener("click", this.linkToSlide.bind(this))
    );
    this.slider.addEventListener("scroll", this.setSlideVisibility.bind(this));
    this.setSlideVisibility();

    if (this.announcementBarSlider) {
      this.announcementBarArrowButtonWasClicked = false;

      this.reducedMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
      );
      this.reducedMotion.addEventListener("change", () => {
        if (this.slider.getAttribute("data-autoplay") === "true")
          this.setAutoPlay();
      });

      [this.prevButton, this.nextButton].forEach((button) => {
        button.addEventListener(
          "click",
          () => {
            this.announcementBarArrowButtonWasClicked = true;
          },
          { once: true }
        );
      });
    }

    if (this.slider.getAttribute("data-autoplay") === "true")
      this.setAutoPlay();
  }

  setAutoPlay() {
    this.autoplaySpeed = this.slider.dataset.speed * 1000;
    this.addEventListener("mouseover", this.focusInHandling.bind(this));
    this.addEventListener("mouseleave", this.focusOutHandling.bind(this));
    this.addEventListener("focusin", this.focusInHandling.bind(this));
    this.addEventListener("focusout", this.focusOutHandling.bind(this));

    if (this.querySelector(".slideshow__autoplay")) {
      this.sliderAutoplayButton = this.querySelector(".slideshow__autoplay");
      this.sliderAutoplayButton.addEventListener(
        "click",
        this.autoPlayToggle.bind(this)
      );
      this.autoplayButtonIsSetToPlay = true;
      this.play();
    } else {
      this.reducedMotion.matches || this.announcementBarArrowButtonWasClicked
        ? this.pause()
        : this.play();
    }
  }

  onButtonClick(event) {
    super.onButtonClick(event);
    this.wasClicked = true;

    const isFirstSlide = this.currentPage === 1;
    const isLastSlide = this.currentPage === this.sliderItemsToShow.length;

    if (!isFirstSlide && !isLastSlide) {
      this.applyAnimationToAnnouncementBar(event.currentTarget.name);
      return;
    }

    if (isFirstSlide && event.currentTarget.name === "previous") {
      this.slideScrollPosition =
        this.slider.scrollLeft +
        this.sliderFirstItemNode.clientWidth * this.sliderItemsToShow.length;
    } else if (isLastSlide && event.currentTarget.name === "next") {
      this.slideScrollPosition = 0;
    }

    this.setSlidePosition(this.slideScrollPosition);

    this.applyAnimationToAnnouncementBar(event.currentTarget.name);
  }

  setSlidePosition(position) {
    if (this.setPositionTimeout) clearTimeout(this.setPositionTimeout);
    this.setPositionTimeout = setTimeout(() => {
      this.slider.scrollTo({
        left: position,
      });
    }, this.announcerBarAnimationDelay);
  }

  update() {
    super.update();
    this.sliderControlButtons = this.querySelectorAll(".slider-counter__link");
    this.prevButton.removeAttribute("disabled");

    if (!this.sliderControlButtons.length) return;

    this.sliderControlButtons.forEach((link) => {
      link.classList.remove("slider-counter__link--active");
      link.removeAttribute("aria-current");
    });
    this.sliderControlButtons[this.currentPage - 1].classList.add(
      "slider-counter__link--active"
    );
    this.sliderControlButtons[this.currentPage - 1].setAttribute(
      "aria-current",
      true
    );
  }

  autoPlayToggle() {
    this.togglePlayButtonState(this.autoplayButtonIsSetToPlay);
    this.autoplayButtonIsSetToPlay ? this.pause() : this.play();
    this.autoplayButtonIsSetToPlay = !this.autoplayButtonIsSetToPlay;
  }

  focusOutHandling(event) {
    if (this.sliderAutoplayButton) {
      const focusedOnAutoplayButton =
        event.target === this.sliderAutoplayButton ||
        this.sliderAutoplayButton.contains(event.target);
      if (!this.autoplayButtonIsSetToPlay || focusedOnAutoplayButton) return;
      this.play();
    } else if (
      !this.reducedMotion.matches &&
      !this.announcementBarArrowButtonWasClicked
    ) {
      this.play();
    }
  }

  focusInHandling(event) {
    if (this.sliderAutoplayButton) {
      const focusedOnAutoplayButton =
        event.target === this.sliderAutoplayButton ||
        this.sliderAutoplayButton.contains(event.target);
      if (focusedOnAutoplayButton && this.autoplayButtonIsSetToPlay) {
        this.play();
      } else if (this.autoplayButtonIsSetToPlay) {
        this.pause();
      }
    } else if (this.announcementBarSlider.contains(event.target)) {
      this.pause();
    }
  }

  play() {
    this.slider.setAttribute("aria-live", "off");
    clearInterval(this.autoplay);
    this.autoplay = setInterval(
      this.autoRotateSlides.bind(this),
      this.autoplaySpeed
    );
  }

  pause() {
    this.slider.setAttribute("aria-live", "polite");
    clearInterval(this.autoplay);
  }

  togglePlayButtonState(pauseAutoplay) {
    if (pauseAutoplay) {
      this.sliderAutoplayButton.classList.add("slideshow__autoplay--paused");
      this.sliderAutoplayButton.setAttribute(
        "aria-label",
        window.accessibilityStrings.playSlideshow
      );
    } else {
      this.sliderAutoplayButton.classList.remove("slideshow__autoplay--paused");
      this.sliderAutoplayButton.setAttribute(
        "aria-label",
        window.accessibilityStrings.pauseSlideshow
      );
    }
  }

  autoRotateSlides() {
    const slideScrollPosition =
      this.currentPage === this.sliderItems.length
        ? 0
        : this.slider.scrollLeft + this.sliderItemOffset;

    this.setSlidePosition(slideScrollPosition);
    this.applyAnimationToAnnouncementBar();
  }

  setSlideVisibility(event) {
    this.sliderItemsToShow.forEach((item, index) => {
      const linkElements = item.querySelectorAll("a");
      if (index === this.currentPage - 1) {
        if (linkElements.length)
          linkElements.forEach((button) => {
            button.removeAttribute("tabindex");
          });
        item.setAttribute("aria-hidden", "false");
        item.removeAttribute("tabindex");
      } else {
        if (linkElements.length)
          linkElements.forEach((button) => {
            button.setAttribute("tabindex", "-1");
          });
        item.setAttribute("aria-hidden", "true");
        item.setAttribute("tabindex", "-1");
      }
    });
    this.wasClicked = false;
  }

  applyAnimationToAnnouncementBar(button = "next") {
    if (!this.announcementBarSlider) return;

    const itemsCount = this.sliderItems.length;
    const increment = button === "next" ? 1 : -1;

    const currentIndex = this.currentPage - 1;
    let nextIndex = (currentIndex + increment) % itemsCount;
    nextIndex = nextIndex === -1 ? itemsCount - 1 : nextIndex;

    const nextSlide = this.sliderItems[nextIndex];
    const currentSlide = this.sliderItems[currentIndex];

    const animationClassIn = "announcement-bar-slider--fade-in";
    const animationClassOut = "announcement-bar-slider--fade-out";

    const isFirstSlide = currentIndex === 0;
    const isLastSlide = currentIndex === itemsCount - 1;

    const shouldMoveNext =
      (button === "next" && !isLastSlide) ||
      (button === "previous" && isFirstSlide);
    const direction = shouldMoveNext ? "next" : "previous";

    currentSlide.classList.add(`${animationClassOut}-${direction}`);
    nextSlide.classList.add(`${animationClassIn}-${direction}`);

    setTimeout(() => {
      currentSlide.classList.remove(`${animationClassOut}-${direction}`);
      nextSlide.classList.remove(`${animationClassIn}-${direction}`);
    }, this.announcerBarAnimationDelay * 2);
  }

  linkToSlide(event) {
    event.preventDefault();
    const slideScrollPosition =
      this.slider.scrollLeft +
      this.sliderFirstItemNode.clientWidth *
        (this.sliderControlLinksArray.indexOf(event.currentTarget) +
          1 -
          this.currentPage);
    this.slider.scrollTo({
      left: slideScrollPosition,
    });
  }
}

customElements.define("slideshow-component", SlideshowComponent);

class VariantSelects extends HTMLElement {
  constructor() {
    super();
    this.addEventListener("change", this.onVariantChange);
  }

  onVariantChange() {
    this.updateOptions();
    this.updateMasterId();
    this.toggleAddButton(true, "", false);
    this.updatePickupAvailability();
    this.removeErrorMessage();
    this.updateVariantStatuses();

    if (!this.currentVariant) {
      this.toggleAddButton(true, "", true);
      this.setUnavailable();
    } else {
      this.updateMedia();
      this.updateURL();
      this.updateVariantInput();
      this.renderProductInfo();
      this.updateShareUrl();
    }
  }

  updateOptions() {
    this.options = Array.from(
      this.querySelectorAll("select"),
      (select) => select.value
    );
  }

  updateMasterId() {
    this.currentVariant = this.getVariantData().find((variant) => {
      return !variant.options
        .map((option, index) => {
          return this.options[index] === option;
        })
        .includes(false);
    });
  }

  updateMedia() {
    if (!this.currentVariant) return;
    if (!this.currentVariant.featured_media) return;

    const mediaGalleries = document.querySelectorAll(
      `[id^="MediaGallery-${this.dataset.section}"]`
    );
    mediaGalleries.forEach((mediaGallery) =>
      mediaGallery.setActiveMedia(
        `${this.dataset.section}-${this.currentVariant.featured_media.id}`,
        true
      )
    );

    const modalContent = document.querySelector(
      `#ProductModal-${this.dataset.section} .product-media-modal__content`
    );
    if (!modalContent) return;
    const newMediaModal = modalContent.querySelector(
      `[data-media-id="${this.currentVariant.featured_media.id}"]`
    );
    modalContent.prepend(newMediaModal);
  }

  updateURL() {
    if (!this.currentVariant || this.dataset.updateUrl === "false") return;
    window.history.replaceState(
      {},
      "",
      `${this.dataset.url}?variant=${this.currentVariant.id}`
    );
  }

  updateShareUrl() {
    const shareButton = document.getElementById(
      `Share-${this.dataset.section}`
    );
    if (!shareButton || !shareButton.updateUrl) return;
    shareButton.updateUrl(
      `${window.shopUrl}${this.dataset.url}?variant=${this.currentVariant.id}`
    );
  }

  updateVariantInput() {
    const productForms = document.querySelectorAll(
      `#product-form-${this.dataset.section}, #product-form-installment-${this.dataset.section}`
    );
    productForms.forEach((productForm) => {
      const input = productForm.querySelector('input[name="id"]');
      input.value = this.currentVariant.id;
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  updateVariantStatuses() {
    const selectedOptionOneVariants = this.variantData.filter(
      (variant) => this.querySelector(":checked").value === variant.option1
    );
    const inputWrappers = [...this.querySelectorAll(".product-form__input")];
    inputWrappers.forEach((option, index) => {
      if (index === 0) return;
      const optionInputs = [
        ...option.querySelectorAll('input[type="radio"], option'),
      ];
      const previousOptionSelected =
        inputWrappers[index - 1].querySelector(":checked").value;
      const availableOptionInputsValue = selectedOptionOneVariants
        .filter(
          (variant) =>
            variant.available &&
            variant[`option${index}`] === previousOptionSelected
        )
        .map((variantOption) => variantOption[`option${index + 1}`]);
      this.setInputAvailability(optionInputs, availableOptionInputsValue);
    });
  }

  setInputAvailability(listOfOptions, listOfAvailableOptions) {
    listOfOptions.forEach((input) => {
      if (listOfAvailableOptions.includes(input.getAttribute("value"))) {
        input.innerText = input.getAttribute("value");
      } else {
        input.innerText = window.variantStrings.unavailable_with_option.replace(
          "[value]",
          input.getAttribute("value")
        );
      }
    });
  }

  updatePickupAvailability() {
    const pickUpAvailability = document.querySelector("pickup-availability");
    if (!pickUpAvailability) return;

    if (this.currentVariant && this.currentVariant.available) {
      pickUpAvailability.fetchAvailability(this.currentVariant.id);
    } else {
      pickUpAvailability.removeAttribute("available");
      pickUpAvailability.innerHTML = "";
    }
  }

  removeErrorMessage() {
    const section = this.closest("section");
    if (!section) return;

    const productForm = section.querySelector("product-form");
    if (productForm) productForm.handleErrorMessage();
  }

  renderProductInfo() {
    const requestedVariantId = this.currentVariant.id;
    const sectionId = this.dataset.originalSection
      ? this.dataset.originalSection
      : this.dataset.section;

    fetch(
      `${this.dataset.url}?variant=${requestedVariantId}&section_id=${
        this.dataset.originalSection
          ? this.dataset.originalSection
          : this.dataset.section
      }`
    )
      .then((response) => response.text())
      .then((responseText) => {
        // prevent unnecessary ui changes from abandoned selections
        if (this.currentVariant.id !== requestedVariantId) return;

        const html = new DOMParser().parseFromString(responseText, "text/html");
        const destination = document.getElementById(
          `price-${this.dataset.section}`
        );
        const source = html.getElementById(
          `price-${
            this.dataset.originalSection
              ? this.dataset.originalSection
              : this.dataset.section
          }`
        );
        const skuSource = html.getElementById(
          `Sku-${
            this.dataset.originalSection
              ? this.dataset.originalSection
              : this.dataset.section
          }`
        );
        const skuDestination = document.getElementById(
          `Sku-${this.dataset.section}`
        );
        const inventorySource = html.getElementById(
          `Inventory-${
            this.dataset.originalSection
              ? this.dataset.originalSection
              : this.dataset.section
          }`
        );
        const inventoryDestination = document.getElementById(
          `Inventory-${this.dataset.section}`
        );

        const volumePricingSource = html.getElementById(
          `Volume-${
            this.dataset.originalSection
              ? this.dataset.originalSection
              : this.dataset.section
          }`
        );

        const pricePerItemDestination = document.getElementById(
          `Price-Per-Item-${this.dataset.section}`
        );
        const pricePerItemSource = html.getElementById(
          `Price-Per-Item-${
            this.dataset.originalSection
              ? this.dataset.originalSection
              : this.dataset.section
          }`
        );

        const volumePricingDestination = document.getElementById(
          `Volume-${this.dataset.section}`
        );

        if (source && destination) destination.innerHTML = source.innerHTML;
        if (inventorySource && inventoryDestination)
          inventoryDestination.innerHTML = inventorySource.innerHTML;
        if (skuSource && skuDestination) {
          skuDestination.innerHTML = skuSource.innerHTML;
          skuDestination.classList.toggle(
            "visibility-hidden",
            skuSource.classList.contains("visibility-hidden")
          );
        }

        if (volumePricingSource && volumePricingDestination) {
          volumePricingDestination.innerHTML = volumePricingSource.innerHTML;
        }

        if (pricePerItemSource && pricePerItemDestination) {
          pricePerItemDestination.innerHTML = pricePerItemSource.innerHTML;
          pricePerItemDestination.classList.toggle(
            "visibility-hidden",
            pricePerItemSource.classList.contains("visibility-hidden")
          );
        }

        const price = document.getElementById(`price-${this.dataset.section}`);

        if (price) price.classList.remove("visibility-hidden");

        if (inventoryDestination)
          inventoryDestination.classList.toggle(
            "visibility-hidden",
            inventorySource.innerText === ""
          );

        const addButtonUpdated = html.getElementById(
          `ProductSubmitButton-${sectionId}`
        );
        this.toggleAddButton(
          addButtonUpdated ? addButtonUpdated.hasAttribute("disabled") : true,
          window.variantStrings.soldOut
        );

        publish(PUB_SUB_EVENTS.variantChange, {
          data: {
            sectionId,
            html,
            variant: this.currentVariant,
          },
        });
      });
  }

  toggleAddButton(disable = true, text, modifyClass = true) {
    const productForm = document.getElementById(
      `product-form-${this.dataset.section}`
    );
    if (!productForm) return;
    const addButton = productForm.querySelector('[name="add"]');
    const addButtonText = productForm.querySelector('[name="add"] > span');
    if (!addButton) return;

    if (disable) {
      addButton.setAttribute("disabled", "disabled");
      if (text) addButtonText.textContent = text;
    } else {
      addButton.removeAttribute("disabled");
      addButtonText.textContent = window.variantStrings.addToCart;
    }

    if (!modifyClass) return;
  }

  setUnavailable() {
    const button = document.getElementById(
      `product-form-${this.dataset.section}`
    );
    const addButton = button.querySelector('[name="add"]');
    const addButtonText = button.querySelector('[name="add"] > span');
    const price = document.getElementById(`price-${this.dataset.section}`);
    const inventory = document.getElementById(
      `Inventory-${this.dataset.section}`
    );
    const sku = document.getElementById(`Sku-${this.dataset.section}`);
    const pricePerItem = document.getElementById(
      `Price-Per-Item-${this.dataset.section}`
    );

    if (!addButton) return;
    addButtonText.textContent = window.variantStrings.unavailable;
    if (price) price.classList.add("visibility-hidden");
    if (inventory) inventory.classList.add("visibility-hidden");
    if (sku) sku.classList.add("visibility-hidden");
    if (pricePerItem) pricePerItem.classList.add("visibility-hidden");
  }

  getVariantData() {
    this.variantData =
      this.variantData ||
      JSON.parse(this.querySelector('[type="application/json"]').textContent);
    return this.variantData;
  }
}

customElements.define("variant-selects", VariantSelects);

class VariantRadios extends VariantSelects {
  constructor() {
    super();
  }

  setInputAvailability(listOfOptions, listOfAvailableOptions) {
    listOfOptions.forEach((input) => {
      if (listOfAvailableOptions.includes(input.getAttribute("value"))) {
        input.classList.remove("disabled");
      } else {
        input.classList.add("disabled");
      }
    });
  }

  updateOptions() {
    const fieldsets = Array.from(this.querySelectorAll("fieldset"));
    this.options = fieldsets.map((fieldset) => {
      return Array.from(fieldset.querySelectorAll("input")).find(
        (radio) => radio.checked
      ).value;
    });
  }
}

customElements.define("variant-radios", VariantRadios);

class ProductRecommendations extends HTMLElement {
  constructor() {
    super();
  }

  connectedCallback() {
    const handleIntersection = (entries, observer) => {
      if (!entries[0].isIntersecting) return;
      observer.unobserve(this);

      fetch(this.dataset.url)
        .then((response) => response.text())
        .then((text) => {
          const html = document.createElement("div");
          html.innerHTML = text;
          const recommendations = html.querySelector("product-recommendations");

          if (recommendations && recommendations.innerHTML.trim().length) {
            this.innerHTML = recommendations.innerHTML;
          }

          if (
            !this.querySelector("slideshow-component") &&
            this.classList.contains("complementary-products")
          ) {
            this.remove();
          }

          if (html.querySelector(".grid__item")) {
            this.classList.add("product-recommendations--loaded");
          }
        })
        .catch((e) => {
          console.error(e);
        });
    };

    new IntersectionObserver(handleIntersection.bind(this), {
      rootMargin: "0px 0px 400px 0px",
    }).observe(this);
  }
}

customElements.define("product-recommendations", ProductRecommendations);


