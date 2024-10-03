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
            <form id="locations_revenue_form">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th style="width:33%;">
                                Location
                                <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                                    <option value="" selected>--- Select Location ---</option>
                                    @foreach($arrLocations as $location)
                                    <option value="{{ $location->name }}">{{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </th>
                            <th style="width:33%;">
                                Month
                                <select id="strFilterDate" name="strFilterDate" class="form-select">
                                    <option value="" selected>--- Select Month ---</option>
                                    @foreach($years_months as $key => $value)
                                    <option value="{{ $key }}">{{ $value }}</option>
                                    @endforeach
                                </select>
                            </th>
                            <th style="width:33%;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            {{-- <button type="button" class="btn btn-primary" id="save_btn">Save</button> --}}
            </form>
            <br>
            @php
                $variables = include resource_path('views/includes/notepad.php');
                extract($variables);
            @endphp

            <p>{!! nl2br(e($location_revenue_text)) !!}</p>
            {{-- <hr>
            <form id="personal_notepad_form">
                <input type="hidden" name="personal_notepad_key" value="LOCATION_REVENUE">
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

        // Create a button for 'Orders'
        var ordersButton = Button.create(app, { label: 'Location Order Overview' });
        ordersButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/orders');
            // Add your logic for when the 'Orders' button is clicked
        });

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

        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Locations Revenue',
            buttons: {
                primary: ordersButton,
                secondary: [location_products, locations_text, kitchen]
            },
        });
    </script>

    <script type="text/javascript">
    	$(function(){
            window.table = jQuery('.js-dataTable-full').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 20], [5, 10, 20]],
                order:[[0, 'desc']],
                columnDefs: [{ orderable: false, targets: 0 }, { orderable: false, targets: 1 }],
                autoWidth: false
            });

            $(document).on('change', '#strFilterLocation, #strFilterDate', function(e){
                LoadList();
            });

            $(document).on('click', '#save_btn', function(e){
                $.ajax({
                    url:"{{ route('locations_revenue.store') }}",
                    type:"POST",
                    // data: {
                    //     "_token": "{{ csrf_token() }}",
                    //     "strFilterLocation": $("#strFilterLocation").val(),
                    //     "strFilterDate": $("#strFilterDate").val(),
                    //     "locations_revenue_form": $("#locations_revenue_form").serialize()
                    // },
                    data: "_token={{ csrf_token() }}&"+$("#locations_revenue_form").serialize(),
                    cache:false,
                    dataType:"json",
                    success:function(data){
                        alert('Locations Revenue Data Saved Successfully');
                    },
                    error: function (request, status, error) {
                        alert('error saving Locations Revenue Data');
                    }
                });
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
            	url:"{{ route('getLocationsRevenueList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val(),
                    "strFilterDate": $("#strFilterDate").val()
            	},
            	cache:false,
            	dataType:"html",
            	success:function(data){
            		table.clear();
            		table.rows.add($(data)).draw(true);
//                 	$(".table tbody").html(data);
                },
                error: function (request, status, error) {
                    table.clear();
                    console.log('error fetching Locations Revenue Data');
                }
            });
        }
    </script>
@endsection
