@extends('shopify-app::layouts.default')

@section('styles')
<style>
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
            <form id="operation_days_form">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th>

                            </th>
                            <th>Monday</th>
                            <th>Tuesday</th>
                            <th>Wednesday</th>
                            <th>Thursday</th>
                            <th>Friday</th>
                            <th>Saturday</th>
                            <th>Sunday</th>
                        </tr>
                    </thead>
                    <tbody>
                        {!! $html !!}
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-primary" id="save_btn">Save</button>
            </form>
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

        // Create a button for 'Products'
        var productsButton = Button.create(app, { label: 'Products' });
        productsButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/products');
            // Add your logic for when the 'Products' button is clicked
        });

        // Create a button for 'Orders'
        var ordersButton = Button.create(app, { label: 'Location Order Overview' });
        ordersButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/orders');
            // Add your logic for when the 'Orders' button is clicked
        });

        // Create a button for 'Amount Products Location Weekday'
        var amountproductslocationweekday = Button.create(app, { label: 'Amount Products Location Weekday' });
        amountproductslocationweekday.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/amountproductslocationweekday');
            // Add your logic for when the 'Operation' button is clicked
        });

        // Create a button for 'Locations Revenue'
        var locations_revenue = Button.create(app, { label: 'Locations Revenue' });
        locations_revenue.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/locations_revenue');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Operation Days',
            buttons: {
                primary: productsButton,
                secondary: [ordersButton, amountproductslocationweekday, locations_revenue]
            },
        });
    </script>

    <script type="text/javascript">
    	$(function(){
    	      window.table = jQuery('.js-dataTable-full').DataTable({
    	          pageLength: 10,
    	          lengthMenu: [[5, 10, 20], [5, 10, 20]],
    	          order:[[0, 'desc']],
    	          autoWidth: false
    	      });

              $(document).on('change', '#strFilterLocation', function(e){
                LoadList();
              });

              $(document).on('click', '#save_btn', function(e){
                $.ajax({
                    url:"{{ route('operationdays.store') }}",
                    type:"POST",
                    data: {
                        "_token": "{{ csrf_token() }}",
                        "strFilterLocation": $("#strFilterLocation").val(),
                        "operation_days_form": $("#operation_days_form").serialize()
                    },
                    cache:false,
                    dataType:"json",
                    success:function(data){
                        alert('Operation Days Saved Successfully');
                    },
                    error: function (request, status, error) {
                        alert('error saving operation days');
                    }
                });
              });

    		//LoadList();
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getOperationDaysList') }}",
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
