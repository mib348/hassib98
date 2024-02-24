window.onload = function () {
  if (window.jQuery) {
    let $ = window.jQuery;

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

        // $input.val(newVal).trigger('change'); // Update and trigger change to handle states
        updateButtonStates2($input, newVal);
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



    function updateButtonStates($input) {
        var min = parseInt($input.attr('min'), 10) || 1; // Default minimum to 1 if not set
        var max = parseInt($input.attr('max'), 10); // Max can be undefined
        var value = parseInt($input.val(), 10);

        console.log(value);
        $input.siblings('button[data-quantity-action="decrease"]').prop('disabled', value <= min);
        $input.siblings('button[data-quantity-action="increase"]').prop('disabled', value >= max);
    }
    function updateButtonStates2($input, value) {
        var min = parseInt($input.attr('min'), 10) || 1; // Default minimum to 1 if not set
        var max = parseInt($input.attr('max'), 10); // Max can be undefined
        $input.siblings('button[data-quantity-action="decrease"]').prop('disabled', value <= min);
        $input.siblings('button[data-quantity-action="increase"]').prop('disabled', value >= max);
    }

  }
}