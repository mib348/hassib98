if (window.jQuery) {
    let $ = window.jQuery;
  
    $('.order_qty').each(function () {
      if (parseInt($(this).find('input').val(), 10) > parseInt($(this).find('input').attr('max'), 10)) {
        $(this).siblings('.add_to_cart').prop('disabled', true);
        $(this).siblings('.add_to_cart').attr('disabled', true);
        $(this).siblings('.add_to_cart').html($(this).siblings('.add_to_cart').data('soldout'));
        $(this).remove();
      }
    });
  }
  
  window.onload = function () {
    if (window.jQuery) {
      let $ = window.jQuery;
  
      $('.order_qty').each(function () {
        if (parseInt($(this).find('input').val(), 10) > parseInt($(this).find('input').attr('max'), 10)) {
          $(this).siblings('.add_to_cart').prop('disabled', true);
          $(this).siblings('.add_to_cart').attr('disabled', true);
          $(this).siblings('.add_to_cart').html($(this).siblings('.add_to_cart').data('soldout'));
          $(this).remove();
        }
      });
  
      $(".add_to_cart").click(function (e) {
        // e.preventDefault();
        // e.stopPropagation();
        var btn = $(this);
        var btnthis = this;
  
        var qty = parseInt(btn.siblings('.order_qty').find('input').val(), 10);
        var max_qty = parseInt(btn.siblings('.order_qty').find('input').attr('max'), 10);
  
        if (qty >= max_qty) {
  
          var newElement = $("<span></span>");
  
          $.each(btnthis.attributes, function () {
            if (this.specified) {
              newElement.attr(this.name, this.value);
            }
          });
  
          newElement.html(btn.data('soldout'));
          newElement.attr('disabled', true);
          newElement.prop('disabled', true);
  
          btn.siblings('.order_qty').remove();
          btn.replaceWith(newElement);
        }
      });
  
      $('button[data-quantity-action]').click(function (e) {
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
      $('input[name="quantity"]').on('keyup paste', function (e) {
        e.preventDefault();
        var $input = $(this);
        setTimeout(function () { // Timeout for paste event to get the pasted value
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
      }).change(function () {
        updateButtonStates($(this)); // Also update button states when input changes
      });
  
      // Initial update for button states
      $('input[name="quantity"]').each(function () {
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