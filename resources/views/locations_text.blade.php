@extends('shopify-app::layouts.default')

@section('styles')
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/css/bootstrap-timepicker.min.css" rel="stylesheet">
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
            <form id="locations_text_form">
                <div class="form-group">
                    <label class="label fw-bold font-bold" for="strFilterLocation">Filter Location</label>
                    <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                        <option value="" selected>--- Select Location ---</option>
                        @foreach($arrLocations as $location)
                        <option value="{{ $location->name }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <br>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                            </tr>
                        </thead>
                        <tbody id="rows">
                        </tbody>
                    </table>
                </div>
                <div class="form-group">
                    <label class="label fw-bold font-bold" for="strFilterLocation">Note</label>
                    <textarea id='note' name='note' rows='3' cols='5' class='form-control'></textarea>
                </div>
                <br>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="Y" id="location_toggle" name="location_toggle">
                    <label class="form-check-label" for="location_toggle">
                        Location Active
                    </label>
                </div>
                <br>
                <button type="button" class="btn btn-primary" id="save_btn">Save</button>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
    @parent
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/js/bootstrap-timepicker.min.js"></script>


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

        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Location Time',
            buttons: {
                primary: productsButton,
                secondary: [ordersButton, operationdays, location_products, locations_revenue]
            },
        });
    </script>

    <script type="text/javascript">
    	$(function(){
    	    //   window.table = jQuery('.js-dataTable-full').DataTable({
    	    //       pageLength: 10,
    	    //       lengthMenu: [[5, 10, 20], [5, 10, 20]],
    	    //       order:[[0, 'desc']],
            //       columnDefs: [{ orderable: false, targets: 0 }, { orderable: false, targets: 1 }],
    	    //       autoWidth: false
    	    //   });

              $(document).on('change', '#strFilterLocation', function(e){
                LoadList();
              });

              $(document).on('click', '#save_btn', function(e){
                $.ajax({
                    url:"{{ route('locations_text.store') }}/" + $("#strFilterLocation").val(),
                    type:"PUT",
                    // data: {
                    //     "_token": "{{ csrf_token() }}",
                    //     "strFilterLocation": $("#strFilterLocation").val(),
                    //     "strFilterDate": $("#strFilterDate").val(),
                    //     "locations_text_form": $("#locations_text_form").serialize()
                    // },
                    data: "_token={{ csrf_token() }}&"+$("#locations_text_form").serialize(),
                    cache:false,
                    dataType:"json",
                    success:function(data){
                        alert('Locations Text Data Saved Successfully');
                    },
                    error: function (request, status, error) {
                        alert('error saving Locations Text Data');
                    }
                });
              });

    		//LoadList();
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getLocationsTextList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val()
            	},
            	cache:false,
            	dataType:"json",
            	success:function(data){
                    if(data || data.length){
                        $("#note").val(data.note);
                        $("#rows").html(data.html);
                        $("#location_toggle").prop('checked', data.location_toggle === 'Y');
                    }
                    else{
                        $("#note").val('');
                        $("#rows").html('');
                        $("#location_toggle").prop('checked', false);
                    }
            		// table.clear();
            		// table.rows.add($(data.html)).draw(true);
//                 	$(".table tbody").html(data);
                },
                error: function (request, status, error) {
                    table.clear();
                    console.log('error fetching Locations Text Data');
                }
            });
        }
    </script>
@endsection
