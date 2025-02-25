@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .order_counter, .items_counter{cursor: pointer;}
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
            redirect.dispatch(Redirect.Action.APP, '/kitchen');
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
                ]
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
