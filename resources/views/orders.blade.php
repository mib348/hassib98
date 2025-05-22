@extends('shopify-app::layouts.default')

@section('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/fontawesome.min.css" integrity="sha512-v8QQ0YQ3H4K6Ic3PJkym91KoeNT5S3PnDKvqnwqFD1oiqIl653crGZplPdU5KKtHjO0QKcQ2aUlQZYjHczkmGw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/solid.min.css" integrity="sha512-DzC7h7+bDlpXPDQsX/0fShhf1dLxXlHuhPBkBo/5wJWRoTU6YL7moeiNoej6q3wh5ti78C57Tu1JwTNlcgHSjg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    .order_counter, .items_counter, .view_images{cursor: pointer;}
    /* Lightbox styles */
    .lightbox {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.9);
        touch-action: none;
    }
    .lightbox-container {
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        height: 100%;
        width: 100%;
        padding: 20px 0;
    }
    .lightbox-content {
        display: block;
        width: 95%;
        max-height: 80vh;
        object-fit: contain;
        margin: 0 auto;
    }
    .lightbox-caption {
        color: white;
        padding: 10px;
        text-align: center;
        width: 90%;
        position: relative;
        margin-top: 15px;
        background-color: rgba(0,0,0,0.7);
        border-radius: 5px;
    }
    .lightbox-close {
        position: absolute;
        top: 15px;
        right: 15px;
        color: white;
        font-size: 30px;
        font-weight: bold;
        cursor: pointer;
        z-index: 10000;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .lightbox-content {
            width: 98%;
            max-height: 70vh;
        }
        .lightbox-caption {
            font-size: 14px;
            padding: 8px;
            width: 95%;
            margin-top: 10px;
        }
        .lightbox-close {
            top: 10px;
            right: 10px;
            font-size: 24px;
        }
        .lightbox-container {
            padding: 10px 0;
        }
    }

    /* Shopify admin embedded app adjustments */
    @media (min-height: 600px) and (max-height: 900px) {
        .lightbox-container {
            padding: 15px 0;
        }
        .lightbox-content {
            max-height: 80vh;
            width: 98%;
        }
        .lightbox-caption {
            margin-top: 10px;
        }
    }

    .no-images {
        color: #888;
        font-style: italic;
    }
</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    {{-- <h5>Orders <div class="loader spinning_status"></div></h5> --}}
    {{-- <div class="row">
        <div class="col-6">
            <h5>Orders</h5>
        </div>
        <div class="col-6 d-flex flex-row flex-wrap align-items-center justify-content-end mb-3">
            <div class="d-grid gap-2 d-md-block">
                <a href="https://admin.shopify.com/store/dc9ef9/apps/sushi-catering-1/products" class="btn btn-primary">Products</a>
              </div>
        </div>
    </div> --}}
    <div class="row">
        <div class="col-md-12">
            Location
            <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                <option value="" selected>--- Select Location ---</option>
                @foreach($locations as $location)
                @if($location == "Additional Inventory")
                    @continue;
                @endif
                <option value="{{ $location }}">{{ $location }}</option>
                @endforeach
            </select>
            <br>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th>
                                Date
                            </th>
                            <th>Orders</th>
                            <th>Fulfilled</th>
                            <th>Took-Zero</th>
                            <th>Took-Less</th>
                            <th>Wrong-Item</th>
                            <th>No Status</th>
                            <th>Cancelled</th>
                            <th>Refunded</th>
                            <th>Items Sold</th>
                            <th>Driver<br>Images</th>
                        </tr>
                    </thead>
                    <tbody>
                        {!! $html !!}
                    </tbody>
                </table>
            </div>
            <br>
            @php
                $variables = include resource_path('views/includes/notepad.php');
                extract($variables);
            @endphp

            <p>{!! nl2br(e($location_order_overview_text)) !!}</p>
            {{-- <hr>
            <form id="personal_notepad_form">
                <input type="hidden" name="personal_notepad_key" value="LOCATION_ORDER_OVERVIEW">
                <div class="row">
                    <div class="col-12">
                        <label class="label fw-bold font-bold" for="personal_notepad">Personal Notepad
                            <button type="button" class="btn btn-info btn-sm mb-3" id="personal_notepad_save_btn">Save</button>
                        </label>
                        <textarea name="personal_notepad" id="personal_notepad" cols="5" rows="3" class="form-control">{{ $personal_notepad }}</textarea>
                    </div>
                </div>
            </form> --}}
        </div>
    </div>
</div>

<div class="modal" tabindex="-1" id="orders_list_modal">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><span id="order_type"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="orders_list">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          {{-- <button type="button" class="btn btn-primary">Save changes</button> --}}
        </div>
      </div>
    </div>
  </div>

<!-- Lightbox Modal for Images -->
<div id="images-lightbox" class="lightbox">
    <span class="lightbox-close">&times;</span>
    <div class="lightbox-container">
        <img class="lightbox-content" id="lightbox-img">
        <div class="lightbox-caption" id="lightbox-caption"></div>
    </div>
</div>
@endsection

@section('scripts')
    @parent


    <script>
        // Assuming 'app' is already initialized and available
        // var actions = window['app-bridge'].actions;
        var Button = actions.Button;
        var TitleBar = actions.TitleBar;
        var Redirect = actions.Redirect; // Ensure Redirect is defined

        // // Create a button for 'Products'
        // var productsButton = Button.create(app, { label: 'Products' });
        // productsButton.subscribe(Button.Action.CLICK, function() {
        //     var redirect = Redirect.create(app);
        //     redirect.dispatch(Redirect.Action.APP, '/products');
        //     // Add your logic for when the 'Products' button is clicked
        // });

        // // Create a button for 'Operation'
        // var operationdays = Button.create(app, { label: 'Operation Days' });
        // operationdays.subscribe(Button.Action.CLICK, function() {
        //     var redirect = Redirect.create(app);
        //     redirect.dispatch(Redirect.Action.APP, '/operationdays');
        //     // Add your logic for when the 'Operation' button is clicked
        // });

        // Create a button for 'Location Products'
        var location_products = Button.create(app, { label: 'Location Products' });
        location_products.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/location_products');
            // Add your logic for when the 'Operation' button is clicked
        });


        // Create a button for 'Locations Revenue'
        var locations_revenue = Button.create(app, { label: 'Locations Revenue' });
        locations_revenue.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_revenue');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Locations Text'
        var locations_text = Button.create(app, { label: 'Location Settings' });
        locations_text.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_text');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Kitchen'
        var kitchen = Button.create(app, { label: 'Kitchen' });
        kitchen.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/kitchen_admin');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Home Delivery Overview'
        var homedeliveryButton = Button.create(app, { label: 'Home Delivery Overview' });
        homedeliveryButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/home_delivery');
            // Add your logic for when the 'Orders' button is clicked
        });


        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Location Order Overview',
            buttons: {
                primary: location_products,
                secondary: [locations_revenue, locations_text, kitchen, homedeliveryButton]
            },
        });
    </script>

    <script type="text/javascript">
    	$(function(){

            // Replace your current date sorting plugin with this more robust version
            $.extend($.fn.dataTable.ext.type.order, {
                "date-de-pre": function(data) {
                    if (!data) return 0;

                    // Extract just the date part (ignore the day of week text)
                    var dateOnly = data.match(/\d{2}\.\d{2}\.\d{4}/)[0];

                    // Split by period
                    var parts = dateOnly.split('.');

                    // Create a proper date format for sorting: YYYYMMDD
                    var year = parts[2];
                    var month = parts[1];
                    var day = parts[0];

                    return year + month + day;
                }
            });

            // Then update your DataTable initialization
            window.table = jQuery('.js-dataTable-full').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 20], [5, 10, 20]],
                order: [[0, 'desc']], // Sort by date column descending
                autoWidth: false,
                columnDefs: [
                    { type: 'date-de', targets: 0 }  // Apply our new sorting method
                ],
                unsortable: [10]
            });




              $(document).on('change', '#strFilterLocation', function(e){
                LoadList();
              });

              $(document).on('click', '.order_counter', function(e){
                    var arrOrders = $(this).attr('data-orders').split(',');
                    $("#order_type").html($(this).attr('data-type'));
                    const arrayOrders = JSON.parse(arrOrders);
                    console.log(arrayOrders);
                    var html = '<ul>';
                    $.each(arrayOrders, function(key, value){
                        html += '<li><a href="https://admin.shopify.com/store/dc9ef9/orders/' + key + '" target="_blank">' + value + '</a></li>';
                    });
                    $("#orders_list").html(html); // display the sorted list
                    $("#orders_list_modal").modal('show');
                });

                $(document).on('click', '.items_counter', function(e) {
                    var arrOrders = $(this).attr('data-items');
                    $("#order_type").html($(this).attr('data-type'));
                    const arrayOrders = JSON.parse(arrOrders);
                    console.log(arrayOrders);
                    var html = '<ul>';
                    $.each(arrayOrders, function(key, value) {
                        html += '<li><a href="https://admin.shopify.com/store/dc9ef9/products/' + key + '" target="_blank">' + value + '</a></li>';
                    });
                    html += '</ul>';
                    $("#orders_list").html(html); // display the sorted list
                    $("#orders_list_modal").modal('show');
                });

                // Modified to directly open lightbox instead of modal
                $(document).on('click', '.view_images', function(e) {
                    // Check if a location is selected
                    var selectedLocation = $("#strFilterLocation").val();
                    if (!selectedLocation) {
                        alert('Please select a location first to view driver images.');
                        return;
                    }

                    var images = $(this).attr('data-images');
                    // Parse the images data
                    const imagesArray = JSON.parse(images);
                    console.log(imagesArray);

                    // Check if there are any images
                    if (imagesArray.length === 0) {
                        alert('No driver images available for this date and location.');
                        return;
                    }

                    // Get the first image (should be the only one per location/date)
                    var image = imagesArray[0];

                    // Preload the image to get its natural dimensions
                    var preloadImg = new Image();
                    preloadImg.onload = function() {
                        console.log('Image natural dimensions:', this.naturalWidth, 'x', this.naturalHeight);

                        // Show image directly in lightbox
                        $("#lightbox-img").attr('src', image.image_url);
                        $("#lightbox-caption").html('Location: ' + image.location + ' - ' + image.date + '<br>Uploaded on: ' + image.created_at);
                        $("#images-lightbox").fadeIn(300);
                        $('body').css('overflow', 'hidden'); // Prevent scrolling when lightbox is open
                    };
                    preloadImg.src = image.image_url;
                });

                // Close lightbox
                $(".lightbox-close").on('click', function() {
                    $("#images-lightbox").fadeOut();
                    $('body').css('overflow', 'auto'); // Re-enable scrolling
                });

                // Close on ESC key
                $(document).on('keydown', function(e) {
                    if (e.keyCode === 27) { // ESC key
                        $(".lightbox-close").trigger('click');
                    }
                });

                // Add tap/touch support for mobile - single tap to close
                $("#images-lightbox").on('click touchend', function(e) {
                    // Only close if clicking on the background (not the image or caption)
                    if (e.target === this) {
                        $(".lightbox-close").trigger('click');
                    }
                });

                $(document).on('click', '#personal_notepad_save_btn', function(e){
                    $.ajax({
                        url:"{{ route('personal_notepad.store') }}",
                        type:"POST",
                        data: "_token={{ csrf_token() }}&"+$("#personal_notepad_form").serialize(),
                        cache:false,
                        dataType:"json",
                        success:function(data){
                            alert('Personal Notepad Saved Successfully');
                        },
                        error: function (request, status, error) {
                            alert('error saving Personal Notepad');
                        }
                    });
                });

    		//LoadList();
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getOrdersList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val()
            	},
            	cache:false,
            	dataType:"html",
            	success:function(data){
            		table.clear();
            		table.rows.add($(data)).draw(true);
//                 	$(".table tbody").html(data);
                },
                error: function(request, status, error) {
                    // var errorMessage = "Error: " + request.status + " " + request.statusText;
                    alert(request.responseText);
                    console.log('orders fetching error');
                }
            });
        }
    </script>
@endsection
