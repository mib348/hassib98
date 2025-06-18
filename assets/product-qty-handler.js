(function(){
  // Prevent double inclusion
  if(window.__PF_QTY_HANDLER__){ console.log('[PF Qty] already initialized'); return; }
  window.__PF_QTY_HANDLER__ = true;

  if(!window.jQuery){ console.warn('[PF Qty] jQuery not found'); return; }
  var $ = window.jQuery;

  console.log('[PF Qty] handler ready');

  /* ----------------- helper ----------------- */
  function updateState($wrap){
    var $input = $wrap.find('input[type="number"]');
    var min = parseInt($input.attr('min'),10) || 1;
    var max = parseInt($input.attr('max'),10) || min;
    var val = parseInt($input.val(),10) || min;

    // Check if product is sold out (max = 0 or disabled input)
    var isSoldOut = max <= 0;

    // clamp value inside bounds
    if(val < min){ val = min; }
    if(val > max){ val = max; }
    $input.val(val);

    // Enable / disable +/- buttons
    $wrap.find('button[data-quantity-action="decrease"]').prop('disabled', val <= min || isSoldOut);
    $wrap.find('button[data-quantity-action="increase"]').prop('disabled', val >= max || isSoldOut);

    // Disable ATC when sold out or exceeding max
    var $btn = $wrap.siblings('.add_to_cart');
    if($btn.length){
      var shouldDisable = isSoldOut || val > max;
      $btn.prop('disabled', shouldDisable);
      
      // Update button text and class for sold out products
      if(isSoldOut) {
        $btn.addClass('sold-out-btn');
        if($btn.attr('data-soldout')) {
          $btn.text($btn.attr('data-soldout'));
        }
        // Also mark the parent product as sold out
        $btn.closest('.product_details').addClass('sold-out');
      } else {
        $btn.removeClass('sold-out-btn');
        // Restore original button text if not sold out
        if(!$btn.text().includes('...') && !$btn.text().includes('Danke')) {
          $btn.text($btn.hasClass('sold-out-btn') ? 'Ausverkauft' : 'In den Warenkorb');
        }
      }
    }

    // Hide quantity controls if sold out
    if(isSoldOut) {
      $wrap.hide();
    } else {
      $wrap.show();
    }
  }

  /* ----------------- core bind ----------------- */
  function attach(){
    console.log('[PF Qty] (re)binding logic');

    // Initial state for any present order_qty blocks
    $('.order_qty').each(function(){ updateState($(this)); });

    // Handle any existing sold-out buttons that might not have quantity controls
    $('.add_to_cart.sold-out-btn, .add_to_cart[disabled]').each(function(){
      var $btn = $(this);
      $btn.addClass('sold-out-btn').prop('disabled', true);
      if($btn.attr('data-soldout')) {
        $btn.text($btn.attr('data-soldout'));
      }
      $btn.closest('.product_details').addClass('sold-out');
    });

    // Delegated click handlers for + / - buttons
    $(document)
      .off('click.pfQty')
      .on('click.pfQty', '.order_qty button[data-quantity-action]', function(e){
        e.preventDefault();
        var $btn = $(this);
        var action = $btn.data('quantity-action');
        var $wrap = $btn.closest('.order_qty');
        var $input = $wrap.find('input[type="number"]');
        var current = parseInt($input.val(),10) || 1;
        var min = parseInt($input.attr('min'),10) || 1;
        var max = parseInt($input.attr('max'),10) || current;

        // Don't allow changes if sold out
        if(max <= 0) return;

        if(action === 'increase' && current < max){
          $input.val(current + 1).trigger('change');
        }
        if(action === 'decrease' && current > min){
          $input.val(current - 1).trigger('change');
        }
      })
      // Input field manual edits
      .off('input.pfQty change.pfQty')
      .on('input.pfQty change.pfQty', '.order_qty input[type="number"]', function(){
        var $input = $(this);
        var $wrap = $input.closest('.order_qty');
        updateState($wrap);
      });
  }

  // Bind on DOM ready
  $(document).ready(function(){ attach(); });
  // Re-bind whenever the dynamic loader finishes replacement
  document.addEventListener('pf:products:replaced', function(){ attach(); });
})(); 