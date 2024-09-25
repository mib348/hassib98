@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .save-day{float:right !important;}
    .loading-icon {
        display: none;
        margin-left: 5px; /* Add some spacing */
    }
    .loading-icon.show {
        display: inline-block !important;
    }
</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    <div class="row">
        <div class="col-md-12">
            <form id="location_products_form">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                        <thead>
                            <tr>
                                <th>
                                    Location
                                    <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                                        <option value="" selected>--- Select Location ---</option>
                                        @foreach($arrLocations as $location)
                                        <option value="{{ $location->name }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </th>
                                <th colspan="2">Product 1</th>
                                <th colspan="2">Product 2</th>
                                <th colspan="2">Product 3</th>
                                <th colspan="2">Product 4</th>
                            </tr>
                        </thead>
                        <tbody id="table">
                            @foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
                            <tr class="{{ $day }}">
                                <td>
                                    {{ $day }}
                                    <input type="hidden" name="day[]" class="day" value="{{ $day }}" />
                                    <button type="button" class="btn btn-primary btn-sm save-day float-right" data-day="{{ $day }}">
                                        Save
                                        <div class="spinner-border  spinner-border-sm text-danger loading-icon" role="status" data-day="{{ $day }}">
                                        </div>
                                    </button>
                                </td>
                                @for ($i = 1; $i <= 4; $i++)
                                <td>
                                    <select name="nProductId[{{ $day }}][]" class="form-select nProductId" data-product="">
                                        <option value="" selected>--- Select Product ---</option>
                                        @foreach($arrProducts as $arrProduct)
                                        <option value="{{ $arrProduct['id'] }}">{{ $arrProduct['title'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="nQuantity[{{ $day }}][]" class="form-select nQuantity" data-quantity="">
                                        @for ($j = 0; $j <= 8; $j++)
                                        <option value="{{ $j }}" {{ $j == 8 ? 'selected' : '' }}>{{ $j }}</option>
                                        @endfor
                                    </select>
                                </td>
                                @endfor
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                </form>
                <hr>
                <form id="personal_notepad_form">
                    <input type="hidden" name="personal_notepad_key" value="LOCATION_PRODUCTS">
                    <div class="row">
                        <div class="col-12">
                            <label class="label fw-bold font-bold" for="personal_notepad">Personal Notepad
                                <button type="button" class="btn btn-info btn-sm mb-3" id="personal_notepad_save_btn">Save</button>
                            </label>
                            <textarea name="personal_notepad" id="personal_notepad" cols="5" rows="3" class="form-control">{{ $personal_notepad }}</textarea>
                        </div>
                    </div>
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
            // Add your logic for when the 'Location Products' button is clicked
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
            title: 'Location Products',
            buttons: {
                primary: ordersButton,
                secondary: [locations_revenue, locations_text, kitchen]
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

              $('.save-day').on('click', function() {
                const day = $(this).data('day');
                const location = $('#strFilterLocation').val();

                if (location === '') {
                    alert('Please Select Location');
                    return;
                }

                $(`.loading-icon[data-day="${day}"]`).addClass('show');

                // Disable the save button while loading
                $(this).prop('disabled', true);

                const formData = {
                    _token: '{{ csrf_token() }}',
                    strFilterLocation: location,
                    day: [day], // Pass the day as an array
                    nProductId: {}, // Initialize empty object for product IDs
                    nQuantity: {}   // Initialize empty object for quantities
                };

                // Get product IDs and quantities for the specific day
                $(`#table tr.${day} .nProductId`).each(function(index) {
                    const productId = $(this).val();
                    formData.nProductId[day] = formData.nProductId[day] || []; // Initialize array if it doesn't exist
                    formData.nProductId[day].push(productId);
                });

                $(`#table tr.${day} .nQuantity`).each(function(index) {
                    const quantity = $(this).val();
                    formData.nQuantity[day] = formData.nQuantity[day] || []; // Initialize array if it doesn't exist
                    formData.nQuantity[day].push(quantity);
                });

                $.ajax({
                    url: "{{ route('location_products.store') }}",
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    success: function(data) {
                        alert(`Location Products Data for ${day} Saved Successfully`);

                        // Hide the loading icon
                        $(`.loading-icon[data-day="${day}"]`).removeClass('show');

                        // Re-enable the save button
                        $(`.save-day[data-day="${day}"]`).prop('disabled', false);
                    },
                    error: function(error) {
                        console.error(`Error saving Location Products Data for ${day}:`, error);
                        alert(`Error saving Location Products Data for ${day}`);

                        // Hide the loading icon on error as well
                        $(`.loading-icon[data-day="${day}"]`).removeClass('show');

                        // Re-enable the save button on error
                        $(`.save-day[data-day="${day}"]`).prop('disabled', false);
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
                url:"{{ route('getLocationsProductsJSON') }}",
                type:"GET",
                data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val(),
                },
                cache:false,
                dataType:"json",
                success:function(data){
                    // Clear existing selections
                    $("#table select.nProductId").val('');
                    $("#table select.nQuantity").val(8);

                    if(data && data.length > 0){
                        // Group data by day for easier access
                        const dataByDay = data.reduce((acc, item) => {
                            acc[item.day] = acc[item.day] || [];
                            acc[item.day].push(item);
                            return acc;
                        }, {});

                        for (const day in dataByDay) {
                            const dayData = dataByDay[day];
                            let productIndex = 0;

                            dayData.forEach(item => {
                                // Find the correct product dropdown
                                const productSelect = $("#table tr." + day + " .nProductId").eq(productIndex);
                                const quantitySelect = $("#table tr." + day + " .nQuantity").eq(productIndex);

                                // Set selected values
                                productSelect.val(item.product_id);
                                quantitySelect.val(item.quantity);

                                productIndex++;
                            });
                        }
                    } else {
                        // Handle case where no data is returned
                        // You might want to display a message to the user.
                    }
                },
                error: function (request, status, error) {
                    console.error('Error fetching Location Products Data:', error);
                    // Display a user-friendly error message
                    alert("An error occurred while loading product data.");
                }
            });
        }
    </script>
@endsection
