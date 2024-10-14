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
            <!-- Location Dropdown -->
            <div class="form-group">
                <h5 for="strFilterLocation">Location</h5>
                <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                    <option value="" selected>--- Select Location ---</option>
                    @foreach($arrLocations as $location)
                    <option value="{{ $location->name }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>

            <br>
            <!-- Preorders Table -->
            <h5>PreOrder Inventory</h5>
            <form id="location_products_form_preorder">
                <input type="hidden" name="inventory_type" value="preorder">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full" data-inventory-type="preorder">
                        @include('partials.location_products_table', ['inventoryType' => 'preorder', 'arrProducts' => $arrProducts])
                    </table>
                </div>
            </form>


            <!-- Immediate Orders Table -->
            <h5>Immediate Inventory</h5>
            <form id="location_products_form_immediate">
                <input type="hidden" name="inventory_type" value="immediate">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full" data-inventory-type="immediate">
                        @include('partials.location_products_table', ['inventoryType' => 'immediate', 'arrProducts' => $arrProducts])
                    </table>
                </div>
            </form>


            <br>
            @php
                $variables = include resource_path('views/includes/notepad.php');
                extract($variables);
            @endphp

            <p>{!! nl2br(e($location_products_text)) !!}</p>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @parent

    <script type="text/javascript">
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

        $(function(){
            $(document).on('change', '#strFilterLocation', function(e){
                LoadList(); // Load data for both inventory types
            });

            // Save button handler
            $(document).on('click', '.save-day', function() {
                const day = $(this).data('day');
                const location = $('#strFilterLocation').val();
                const inventoryType = $(this).closest('table').data('inventory-type');

                if (location === '') {
                    alert('Please Select Location');
                    return;
                }

                $(`.loading-icon[data-day="${day}"][data-inventory-type="${inventoryType}"]`).addClass('show');

                // Disable the save button while loading
                $(this).prop('disabled', true);

                const formData = {
                    _token: '{{ csrf_token() }}',
                    strFilterLocation: location,
                    day: [day],
                    inventory_type: inventoryType,
                    nProductId: {},
                    nQuantity: {}
                };

                // Get product IDs and quantities for the specific day
                $(`table[data-inventory-type="${inventoryType}"] tr.${day} .nProductId`).each(function(index) {
                    const productId = $(this).val();
                    formData.nProductId[day] = formData.nProductId[day] || [];
                    formData.nProductId[day].push(productId);
                });

                $(`table[data-inventory-type="${inventoryType}"] tr.${day} .nQuantity`).each(function(index) {
                    const quantity = $(this).val();
                    formData.nQuantity[day] = formData.nQuantity[day] || [];
                    formData.nQuantity[day].push(quantity);
                });

                $.ajax({
                    url: "{{ route('location_products.store') }}",
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    success: function(data) {
                        alert(`${inventoryType.charAt(0).toUpperCase() + inventoryType.slice(1)} Data for ${day} Saved Successfully`);

                        // Hide the loading icon
                        $(`.loading-icon[data-day="${day}"][data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button
                        $(`.save-day[data-day="${day}"][data-inventory-type="${inventoryType}"]`).prop('disabled', false);
                    },
                    error: function(error) {
                        console.error(`Error saving ${inventoryType} Data for ${day}:`, error);
                        alert(`Error saving ${inventoryType} Data for ${day}`);

                        // Hide the loading icon on error as well
                        $(`.loading-icon[data-day="${day}"][data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button on error
                        $(`.save-day[data-day="${day}"][data-inventory-type="${inventoryType}"]`).prop('disabled', false);
                    }
                });
            });

            function LoadList(){
                const location = $('#strFilterLocation').val();

                $.ajax({
                    url: "{{ route('getLocationsProductsJSON') }}",
                    type: "GET",
                    data: {
                        "_token": "{{ csrf_token() }}",
                        "strFilterLocation": location,
                        "inventory_type": "both" // Request data for both inventory types
                    },
                    cache:false,
                    dataType:"json",
                    success:function(response){
                        // Clear existing selections in both tables
                        $(`table[data-inventory-type="immediate"] select.nProductId`).val('');
                        $(`table[data-inventory-type="immediate"] select.nQuantity`).val(8);
                        $(`table[data-inventory-type="preorder"] select.nProductId`).val('');
                        $(`table[data-inventory-type="preorder"] select.nQuantity`).val(8);

                        // Process data for both inventory types
                        if (response.data) {
                            if (response.data.immediate) {
                                populateTable(response.data.immediate, 'immediate');
                            }
                            if (response.data.preorder) {
                                populateTable(response.data.preorder, 'preorder');
                            }
                        }
                    },
                    error: function (request, status, error) {
                        console.error('Error fetching data:', error);
                        alert("An error occurred while loading data.");
                    }
                });
            }

            function populateTable(dataArray, inventoryType){
                // Group data by day
                const dataByDay = dataArray.reduce((acc, item) => {
                    acc[item.day] = acc[item.day] || [];
                    acc[item.day].push(item);
                    return acc;
                }, {});

                for (const day in dataByDay) {
                    const dayData = dataByDay[day];
                    let productIndex = 0;

                    dayData.forEach(item => {
                        // Find the correct product dropdown
                        const productSelect = $(`table[data-inventory-type="${inventoryType}"] tr.${day} .nProductId`).eq(productIndex);
                        const quantitySelect = $(`table[data-inventory-type="${inventoryType}"] tr.${day} .nQuantity`).eq(productIndex);

                        // Set selected values
                        productSelect.val(item.product_id);
                        quantitySelect.val(item.quantity);

                        productIndex++;
                    });
                }
            }

            // Load data for both inventory types initially
            LoadList();
        });
    </script>
@endsection
