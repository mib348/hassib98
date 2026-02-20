@extends('shopify-app::layouts.default')

@section('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/fontawesome.min.css" integrity="sha512-B46MVOJpI6RBsdcU307elYeStF2JKT87SsHZfRSkjVi4/iZ3912zXi45X5/CBr/GbCyLx6M1GQtTKYRd52Jxgw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/solid.min.css" integrity="sha512-/r+0SvLvMMSIf41xiuy19aNkXxI+3zb/BN8K9lnDDWI09VM0dwgTMzK7Qi5vv5macJ3VH4XZXr60ip7v13QnmQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    .save-day{display:none !important; float:right !important;}
    .loading-icon {
        display: none;
        margin-left: 5px; /* Add some spacing */
    }
    .loading-icon.show {
        display: inline-block !important;
    }
    
    /* Base Column Styles */
    .day-column {
        width: 146px;
        min-width: 146px;
    }
    .product-cell {
        min-width: 200px;
    }
    .quantity-cell {
        min-width: 80px;
    }

    .remove-product-btn{cursor:pointer;}

    /* Preorder Table Specifics */
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
        z-index: 10;
        box-shadow: 0 1px 1px rgba(0,0,0,0.05);
    }

    .preorder_table .fixed-column {
        position: sticky;
        left: 0;
        background-color: #fff;
        z-index: 5;
        box-shadow: 1px 0 1px rgba(0,0,0,0.05);
    }
    
    /* Corner cell (Top Left) needs highest Z-Index */
    .preorder_table thead th.fixed-column {
        z-index: 20;
    }

    .preorder_table .fixed-column.day-column {
        min-width: 150px;
        max-width: 150px;
        width: 150px;
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

    /* Responsive Adjustments */
    @media (max-width: 768px) {
        .day-column, 
        .preorder_table .fixed-column.day-column,
        .preorder_table .day-column {
            width: 110px !important;
            min-width: 110px !important;
            max-width: 110px !important;
        }

        .product-cell,
        .preorder_table .product-cell {
            width: 160px !important;
            min-width: 160px !important;
        }

        .quantity-cell,
        .preorder_table .quantity-cell {
            width: 70px !important;
            min-width: 70px !important;
        }
        
        .save-day {
            padding: 2px 4px;
            font-size: 10px;
            margin-top: 2px;
            float: none !important;
            display: block;
            width: 100%;
        }

        .form-select, .form-control {
            font-size: 12px;
            padding: 4px;
        }
        
        /* Adjust Immediate Inventory Table */
        .table-responsive .table {
            min-width: 800px; /* Force scroll triggered on small screens */
        }
    }
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Location Products">
    {{-- Primary action button - navigates to Location Order Overview --}}
    <s-button slot="primary-action" onclick="navigateToPage('/orders')">Location Order Overview</s-button>

    {{-- Secondary action buttons for navigation --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/stores')">Stores</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_revenue')">Locations Revenue</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_text')">Location Settings</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/kitchen/ADMIN?menu=1')">Kitchen</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/drivers/ADMIN?menu=1')">Drivers</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/home_delivery')">Home Delivery Overview</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/order_details_for_rpi/ADMIN?menu=1')">RPI Order Details</s-button>
</s-page>

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
            <div class="d-flex flex-wrap align-items-center mb-2" style="gap: 10px;">
                <h5 class="mb-0">PreOrder Inventory</h5>
                <button type="button" class="btn btn-primary btn-sm save-all-days_btn" data-inventory-type="preorder">
                    Save All
                    <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-inventory-type="preorder"></div>
                </button>
                <button type="button" class="btn btn-info btn-sm import_default_menu_btn text-white" data-inventory-type="preorder">
                    Import Default Menu
                    <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-inventory-type="preorder"></div>
                </button>
            </div>
            <form id="location_products_form_preorder" class="clearfix">
                <input type="hidden" name="inventory_type" value="preorder">
                <div class="table-responsive preorder_table">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full" data-inventory-type="preorder">
                            @include('partials.location_products_table', ['inventoryType' => 'preorder', 'arrProducts' => $arrProducts])
                        </table>
                    </div>
                </div>
            </form>
            <br>

            <!-- Immediate Orders Table -->
            <div class="d-flex flex-wrap align-items-center mb-2" style="gap: 10px;">
                <h5 class="mb-0">Immediate Inventory</h5>
                <button type="button" class="btn btn-primary btn-sm save-all-days_btn" data-inventory-type="immediate">
                    Save All
                    <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-inventory-type="immediate"></div>
                </button>
                <button type="button" class="btn btn-info btn-sm import_default_menu_btn text-white" data-inventory-type="immediate">
                    Import Default Menu
                    <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-inventory-type="immediate"></div>
                </button>
            </div>
            <form id="location_products_form_immediate">
                <input type="hidden" name="inventory_type" value="immediate">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full" data-inventory-type="immediate">
                        @include('partials.location_products_table', ['inventoryType' => 'immediate', 'arrProducts' => $arrProducts])
                    </table>
                </div>
            </form>

            {{-- <br>
            <!-- Snacks and Drinks Inventory Table -->
            <!-- This section manages inventory for snacks and drinks products which are tracked separately -->
            <!-- Data is stored in custom.snacks_and_drinks metafield in Shopify -->
            <h5 class="float-start">Snacks & Drinks Inventory</h5>
            <button type="button" class="float-start ms-3 btn btn-primary btn-sm save-all-days_btn float-right" data-inventory-type="snacks_and_drinks">
                Save All
                <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-inventory-type="snacks_and_drinks"></div>
            </button>
            <button type="button" class="float-start ms-3 btn btn-info btn-sm import_default_menu_btn float-right text-white" data-inventory-type="snacks_and_drinks">
                Import Default Menu
                <div class="spinner-border spinner-border-sm text-danger loading-icon" role="status" data-inventory-type="snacks_and_drinks"></div>
            </button>
            <br class="clearfix">
            <br class="clearfix">
            <form id="location_products_form_snacks_and_drinks">
                <input type="hidden" name="inventory_type" value="snacks_and_drinks">
                <div class="table-responsive preorder_table">
                    <div class="table-wrapper">
                        <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full" data-inventory-type="snacks_and_drinks">
                            @include('partials.location_products_table', ['inventoryType' => 'snacks_and_drinks', 'arrProducts' => $arrProducts])
                        </table>
                    </div>
                </div>
            </form> --}}


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
    @include('partials.app_navigation')

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/fontawesome.min.js" integrity="sha512-NeFv3hB6XGV+0y96NVxoWIkhrs1eC3KXBJ9OJiTFktvbzJ/0Kk7Rmm9hJ2/c2wJjy6wG0a0lIgehHjCTDLRwWw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/solid.min.js" integrity="sha512-L2znesU64H/rvdnaD4WBaRAmEcGvhBsVLXygPkhpgpUwcgjymD4amy68shdgZgLiIvyvV/vHRXAM4mTV8xqp+Q==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script type="text/javascript">
        $(function(){
            const resolveAjaxErrorMessage = window.getAjaxErrorMessage || function(xhr, fallbackMessage) {
                const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

                if (response && response.message) {
                    return response.message;
                }

                if (response && response.error) {
                    return response.error;
                }

                if (xhr && xhr.statusText) {
                    return xhr.statusText;
                }

                return fallbackMessage;
            };

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
                $(".remove-product-btn").prop('disabled', true);
                $(".remove-product-btn").attr('disabled', true);

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

                        $(".remove-product-btn").prop('disabled', false);
                        $(".remove-product-btn").attr('disabled', false);
                    },
                    error: function(error) {
                        const errorMessage = resolveAjaxErrorMessage(error, `Unable to save ${inventoryType} data for ${day}.`);
                        console.error(`Error saving ${inventoryType} Data for ${day}:`, errorMessage, error);
                        alert(`Error saving ${inventoryType} Data for ${day}: ` + errorMessage);

                        // Hide the loading icon on error as well
                        $(`.loading-icon[data-day="${day}"][data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button on error
                        $(`.save-day[data-day="${day}"][data-inventory-type="${inventoryType}"]`).prop('disabled', false);

                        $(".remove-product-btn").prop('disabled', false);
                        $(".remove-product-btn").attr('disabled', false);
                    }
                });
            });

            // Save button handler
            $(document).on('click', '.save-all-days_btn', function() {
                const location = $('#strFilterLocation').val();
                const inventoryType = $(this).data('inventory-type');
                $(".remove-product-btn").prop('disabled', true);
                $(".remove-product-btn").attr('disabled', true);

                if (location === '') {
                    alert('Please Select Location');
                    return;
                }

                $(`.loading-icon[data-inventory-type="${inventoryType}"]`).addClass('show');

                // Disable the save button while loading
                $(this).prop('disabled', true);
                $(`.save-day[data-inventory-type="${inventoryType}"]`).prop('disabled', true);

                const arrDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                const formData = {
                    _token: '{{ csrf_token() }}',
                    strFilterLocation: location,
                    "save-all-days": true,
                    inventory_type: inventoryType,
                    day: arrDays,
                    nProductId: {},
                    nQuantity: {}
                };

                // Get product IDs and quantities for the specific day
                arrDays.forEach(function(day) {
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
                });


                $.ajax({
                    url: "{{ route('location_products.store') }}",
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    success: function(data) {
                        alert(`${inventoryType.charAt(0).toUpperCase() + inventoryType.slice(1)} Data for all days Saved Successfully`);

                        // Hide the loading icon
                        $(`.loading-icon[data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button
                        $(`.save-day[data-inventory-type="${inventoryType}"]`).prop('disabled', false);
                        $(`.save-all-days_btn[data-inventory-type="${inventoryType}"]`).prop('disabled', false);

                        $(".remove-product-btn").prop('disabled', false);
                        $(".remove-product-btn").attr('disabled', false);
                    },
                    error: function(error) {
                        const errorMessage = resolveAjaxErrorMessage(error, `Unable to save ${inventoryType} data for all days.`);
                        console.error(`Error saving ${inventoryType} Data for all days:`, errorMessage, error);
                        alert(`Error saving ${inventoryType} Data for all days: ` + errorMessage);

                        // Hide the loading icon on error as well

                        $(`.loading-icon[data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button on error
                        $(`.save-day[data-inventory-type="${inventoryType}"]`).prop('disabled', false);
                        $(`.save-all-days_btn[data-inventory-type="${inventoryType}"]`).prop('disabled', false);

                        $(".remove-product-btn").prop('disabled', false);
                        $(".remove-product-btn").attr('disabled', false);
                    }
                });
            });

            $(document).on('click', '.import_default_menu_btn', function() {
                const location = $('#strFilterLocation').val();
                const inventoryType = $(this).data('inventory-type');
                $(".remove-product-btn").prop('disabled', true);
                $(".remove-product-btn").attr('disabled', true);

                if (location === '') {
                    alert('Please Select Location');
                    return;
                }

                $(`.loading-icon[data-inventory-type="${inventoryType}"]`).addClass('show');

                // Disable the save button while loading
                $(this).prop('disabled', true);
                $(`.save-day[data-inventory-type="${inventoryType}"]`).prop('disabled', true);

                const arrDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                const formData = {
                    _token: '{{ csrf_token() }}',
                    strFilterLocation: location,
                    "import_default_menu": true,
                    inventory_type: inventoryType,
                    day: arrDays,
                    nProductId: {},
                    nQuantity: {}
                };

                // Get product IDs and quantities for the specific day
                arrDays.forEach(function(day) {
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
                });


                $.ajax({
                    url: "{{ route('ImportDefaultMenu') }}",
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    success: function(data) {
                        LoadList();

                        // Hide the loading icon
                        $(`.loading-icon[data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button
                        $(`.save-day[data-inventory-type="${inventoryType}"]`).prop('disabled', false);
                        $(`.save-all-days_btn[data-inventory-type="${inventoryType}"]`).prop('disabled', false);

                        $(".remove-product-btn").prop('disabled', false);
                        $(".remove-product-btn").attr('disabled', false);
                        $(".import_default_menu_btn").attr('disabled', false);

                    },
                    error: function(error) {
                        const errorMessage = resolveAjaxErrorMessage(error, `Unable to import ${inventoryType} data for all days.`);
                        console.error(`Error importing ${inventoryType} Data for all days:`, errorMessage, error);
                        alert(`Error importing ${inventoryType} Data for all days: ` + errorMessage);

                        // Hide the loading icon on error as well
                        $(`.loading-icon[data-inventory-type="${inventoryType}"]`).removeClass('show');

                        // Re-enable the save button on error
                        $(`.save-day[data-inventory-type="${inventoryType}"]`).prop('disabled', false);
                        $(`.save-all-days_btn[data-inventory-type="${inventoryType}"]`).prop('disabled', false);

                        $(".remove-product-btn").prop('disabled', false);
                        $(".remove-product-btn").attr('disabled', false);
                        $(".import_default_menu_btn").attr('disabled', false);
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
                        "inventory_type": "all" // Request data for all three inventory types
                    },
                    cache:false,
                    dataType:"json",
                    success:function(response){
                        // Clear existing selections in all three tables
                        // Reset product selections to empty and quantities to default value of 8
                        $(`table[data-inventory-type="immediate"] select.nProductId`).val('');
                        $(`table[data-inventory-type="immediate"] select.nQuantity`).val(8);
                        $(`table[data-inventory-type="preorder"] select.nProductId`).val('');
                        $(`table[data-inventory-type="preorder"] select.nQuantity`).val(8);
                        $(`table[data-inventory-type="snacks_and_drinks"] select.nProductId`).val('');
                        $(`table[data-inventory-type="snacks_and_drinks"] select.nQuantity`).val(8);

                        // Process data for all three inventory types
                        // Each inventory type has its own metafield in Shopify
                        if (response.data) {
                            if (response.data.immediate) {
                                populateTable(response.data.immediate, 'immediate');
                            }
                            if (response.data.preorder) {
                                populateTable(response.data.preorder, 'preorder');
                            }
                            if (response.data.snacks_and_drinks) {
                                populateTable(response.data.snacks_and_drinks, 'snacks_and_drinks');
                            }
                        }
                    },
                    error: function (request, status, error) {
                        const errorMessage = resolveAjaxErrorMessage(request, "An error occurred while loading data.");
                        console.error('Error fetching data:', errorMessage, request);
                        alert(errorMessage);
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

                    // Get the row for the current day
                    const $row = $(`table[data-inventory-type="${inventoryType}"] tr.${day}`);
                    const $currentButtonTd = $row.find('td.action-column');

                    // For 'preorder' and 'snacks_and_drinks' inventory types, dynamically add cells if needed
                    // These inventory types allow unlimited products per day unlike 'immediate' which is limited to 4
                    if (inventoryType === 'preorder' || inventoryType === 'snacks_and_drinks') {
                        let currentProductCells = $row.find('td.product-cell').length;
                        const productCount = dayData.length;

                        // Add cells if necessary
                        while (currentProductCells < productCount) {
                            // Clone existing product and quantity cells
                            let $lastProductTd = $row.find('td.product-cell').last();
                            let $lastQuantityTd = $row.find('td.quantity-cell').last();

                            let newProductTd = $lastProductTd.clone(true, true);
                            let newQuantityTd = $lastQuantityTd.clone(true, true);

                            // Reset the cloned select values
                            newProductTd.find('select').val('');
                            newQuantityTd.find('select.nQuantity').val(8);

                            // Update the name attributes
                            newProductTd.find('select').attr('name', 'nProductId[' + day + '][]');
                            newQuantityTd.find('select.nQuantity').attr('name', 'nQuantity[' + day + '][]');

                            // Insert the cloned cells before the 'Add Product' button cell
                            newProductTd.insertBefore($currentButtonTd);
                            newQuantityTd.insertBefore($currentButtonTd);

                            currentProductCells++;
                        }
                    }

                    // Now populate the cells with data
                    dayData.forEach(item => {
                        // For 'immediate' inventory type, limit to 4 products
                        if (inventoryType === 'immediate' && productIndex >= 4) {
                            // Skip extra products
                            return;
                        }

                        // Find the correct product and quantity selects
                        const productSelect = $row.find('.nProductId').eq(productIndex);
                        const quantitySelect = $row.find('.nQuantity').eq(productIndex);

                        // Set selected values
                        productSelect.val(item.product_id);
                        quantitySelect.val(item.quantity);

                        productIndex++;
                    });
                }

                // For 'preorder' and 'snacks_and_drinks' inventory types, update headers to reflect dynamic product columns
                if (inventoryType === 'preorder' || inventoryType === 'snacks_and_drinks') {
                    updateHeaders($(`table[data-inventory-type="${inventoryType}"]`));
                }
            }


            // Load data for both inventory types initially
            if (typeof window.waitForShopifySessionToken === 'function') {
                window.waitForShopifySessionToken(function() {
                    LoadList();
                });
            } else {
                // Fallback for safety if shared helper is unavailable for any reason.
                LoadList();
            }
        });
    </script>
@endsection
