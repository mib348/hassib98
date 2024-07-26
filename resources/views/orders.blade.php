@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .order_counter{cursor: pointer;}
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
                        </tr>
                    </thead>
                    <tbody>
                        {!! $html !!}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal" tabindex="-1" id="orders_list_modal">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Orders of type: <span id="order_type"></span></h5>
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

        // Create a button for 'Operation'
        var operationdays = Button.create(app, { label: 'Operation Days' });
        operationdays.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/operationdays');
            // Add your logic for when the 'Operation' button is clicked
        });

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
        var locations_text = Button.create(app, { label: 'Location Time' });
        locations_text.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_text');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });


        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Location Order Overview',
            buttons: {
                primary: operationdays,
                secondary: [location_products, locations_revenue, locations_text]
            },
        });
    </script>

    <script type="text/javascript">
    	$(function(){

            // Custom sorting plugin for date format d.m.Y
            jQuery.fn.dataTable.ext.type.order['dmy-pre'] = function(d) {
                // Convert the date format d.m.Y to Ymd for sorting
                var parts = d.split('.');
                return parts[2] + parts[1] + parts[0];
            };


            window.table = jQuery('.js-dataTable-full').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 20], [5, 10, 20]],
                order: [[0, 'desc']], // Adjust the default sorting column if necessary
                autoWidth: false,
                columnDefs: [
                    // { orderable: false, targets: 0 }, // Example to make the first column non-orderable
                    { type: 'dmy', targets: 0 }  // Change 1 to the index of your date column
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
                error: function (request, status, error) {
                    console.log('orders fetching error');
                }
            });
        }
    </script>
@endsection
