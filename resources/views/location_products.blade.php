@extends('shopify-app::layouts.default')

@section('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/fontawesome.min.css" integrity="sha512-B46MVOJpI6RBsdcU307elYeStF2JKT87SsHZfRSkjVi4/iZ3912zXi45X5/CBr/GbCyLx6M1GQtTKYRd52Jxgw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/solid.min.css" integrity="sha512-/r+0SvLvMMSIf41xiuy19aNkXxI+3zb/BN8K9lnDDWI09VM0dwgTMzK7Qi5vv5macJ3VH4XZXr60ip7v13QnmQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    .save-day{float:right !important;}
    .loading-icon {
        display: none;
        margin-left: 5px; /* Add some spacing */
    }
    .loading-icon.show {
        display: inline-block !important;
    }
    .day-column{width:146px !important;}
    .remove-product-btn{cursor:pointer;}

    .preorder_table .table-responsive {
        overflow-x: auto;
        position: relative; /* Ensure that sticky elements are positioned correctly */
    }

    .preorder_table .table-wrapper {
        position: relative; /* Create a positioning context for sticky elements */
    }

    .preorder_table table {
        width: auto; /* Allow the table to expand horizontally */
        table-layout: fixed; /* Use fixed table layout algorithm */
    }

    .preorder_table thead th, tbody td {
        border: 1px solid #dee2e6;
    }

    .preorder_table thead th {
        background-color: #f9f9f9;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .preorder_table .fixed-column {
        position: sticky;
        left: 0;
        background-color: #fff;
    }
    
    .preorder_table .fixed-column.day-column {
        min-width: 150px;
        max-width: 150px;
        width: 150px;
        z-index: 2; /* Ensure the fixed column appears above other elements */
    }

    .preorder_table .product-cell {
        min-width: 221px;
        max-width: 250px;
        width: 221px;
        white-space: nowrap; /* Prevent text wrapping */
    }
    .preorder_table .quantity-cell {
        min-width: 94px;
        max-width: 100px;
        width: 94px;
        white-space: nowrap; /* Prevent text wrapping */
    }

    .preorder_table .day-column {
        min-width: 150px;
        max-width: 150px;
        width: 150px;
    }

    .preorder_table .action-column {
        min-width: 50px;
        max-width: 50px;
        width: 50px;
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
                <div class="table-responsive preorder_table">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full" data-inventory-type="preorder">
                            @include('partials.location_products_table', ['inventoryType' => 'preorder', 'arrProducts' => $arrProducts])
                        </table>
                    </div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/fontawesome.min.js" integrity="sha512-NeFv3hB6XGV+0y96NVxoWIkhrs1eC3KXBJ9OJiTFktvbzJ/0Kk7Rmm9hJ2/c2wJjy6wG0a0lIgehHjCTDLRwWw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/solid.min.js" integrity="sha512-L2znesU64H/rvdnaD4WBaRAmEcGvhBsVLXygPkhpgpUwcgjymD4amy68shdgZgLiIvyvV/vHRXAM4mTV8xqp+Q==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
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

            // Handle product addition
            $(document).on('click', '.add_product_btn', function () {
                let $currentButtonTd = $(this).closest('td');
                let $currentRow = $currentButtonTd.closest('tr');
                let $table = $currentRow.closest('table');
                let $theadRow = $table.find('thead tr#product-header');

                // Clone existing product and quantity cells
                let $lastProductTd = $currentRow.find('td.product-cell').last();
                let $lastQuantityTd = $currentRow.find('td.quantity-cell').last();

                let newProductTd = $lastProductTd.clone(true, true);
                let newQuantityTd = $lastQuantityTd.clone(true, true);

                // Reset the cloned select values
                newProductTd.find('select').val('');
                newQuantityTd.find('select.nQuantity').val(8);

                // Update the name attributes to include the new index
                let day = $currentRow.attr('class');
                newProductTd.find('select').attr('name', 'nProductId[' + day + '][]');
                newQuantityTd.find('select.nQuantity').attr('name', 'nQuantity[' + day + '][]');

                // Insert the cloned cells before the 'Add Product' button cell
                newProductTd.insertBefore($currentButtonTd);
                newQuantityTd.insertBefore($currentButtonTd);

                // Update headers
                updateHeaders($table);

                // Scroll the new column into view
                scrollToCell($table, newProductTd);
            });

            // Handle product removal
            $(document).on('click', '.remove-product-btn', function () {
                let $quantityTd = $(this).closest('td.quantity-cell');
                let $productTd = $quantityTd.prev('.product-cell');
                let $currentRow = $quantityTd.closest('tr');
                let $table = $currentRow.closest('table');

                // Remove the product and quantity cells
                $productTd.remove();
                $quantityTd.remove();

                // Update headers
                updateHeaders($table);

                // Scroll to the next visible cell or fixed column
                let $nextCell = $currentRow.find('.product-cell, .quantity-cell').first();
                if ($nextCell.length === 0) {
                    // If no product cells left, scroll to the fixed "Day" column
                    $nextCell = $currentRow.find('.fixed-column');
                }
                scrollToCell($table, $nextCell);
            });

            function scrollToCell($table, $cell) {
                let $tableWrapper = $table.closest('.table-responsive');
                let cellOffset = $cell.position().left;
                let wrapperScrollLeft = $tableWrapper.scrollLeft();
                let newScrollLeft = wrapperScrollLeft + cellOffset - $tableWrapper.position().left - 20; // Adjust as needed

                $tableWrapper.animate({
                    scrollLeft: newScrollLeft
                }, 500); // Adjust the duration as needed
            }


            // Helper function to update headers and keep everything in sync
            function updateHeaders($table) {
                let $theadRow = $table.find('thead tr#product-header');
                let $tbodyRows = $table.find('tbody tr');

                // Remove all product th elements (excluding fixed columns like 'Day' and 'Action')
                $theadRow.find('th').not('.fixed-column').remove();

                // Determine the maximum number of product columns across all rows
                let maxProductCellCount = 0;
                $tbodyRows.each(function() {
                    let cellCount = $(this).find('td.product-cell').length;
                    if (cellCount > maxProductCellCount) {
                        maxProductCellCount = cellCount;
                    }
                });

                // Insert product th elements after the 'Day' th and before 'Action' th (if present)
                let $dayTh = $theadRow.find('th.fixed-column').first();
                let $actionTh = $theadRow.find('th.fixed-column').last();
                let $referenceTh = $actionTh.length > 0 && $actionTh !== $dayTh ? $actionTh : null;

                for (let i = 1; i <= maxProductCellCount; i++) {
                    let $newTh = $('<th>', { colspan: 2, text: 'Product ' + i });
                    if ($referenceTh) {
                        $newTh.insertBefore($referenceTh);
                    } else {
                        $newTh.appendTo($theadRow);
                    }
                }
            }






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
