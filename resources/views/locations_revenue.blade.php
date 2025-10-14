@extends('shopify-app::layouts.default')

@section('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css" />
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css" />
<style>
    /* Right align numeric columns in the table (columns 4-10) */
    .js-dataTable-full th:nth-child(4),
    .js-dataTable-full th:nth-child(5),
    .js-dataTable-full th:nth-child(6),
    .js-dataTable-full th:nth-child(7),
    .js-dataTable-full th:nth-child(8),
    .js-dataTable-full th:nth-child(9),
    .js-dataTable-full th:nth-child(10),
    .js-dataTable-full td:nth-child(4),
    .js-dataTable-full td:nth-child(5),
    .js-dataTable-full td:nth-child(6),
    .js-dataTable-full td:nth-child(7),
    .js-dataTable-full td:nth-child(8),
    .js-dataTable-full td:nth-child(9),
    .js-dataTable-full td:nth-child(10) {
        text-align: right !important;
    }


    /* Ensure detail rows maintain their styling */
    .detail-row td {
        text-align: left !important;
        background-color: #f9f9f9 !important;
        padding: 15px !important;
        border-top: 0 !important;
    }
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Locations Revenue">
    {{-- Primary action button - navigates to Location Order Overview --}}
    <s-button slot="primary-action" onclick="navigateToPage('/orders')">Location Order Overview</s-button>

    {{-- Secondary action buttons for navigation --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/stores')">Stores</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/location_products')">Location Products</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_text')">Location Settings</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/kitchen/ADMIN?menu=1')">Kitchen</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/drivers/ADMIN?menu=1')">Drivers</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/home_delivery')">Home Delivery Overview</s-button>
</s-page>

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
        <div class="col-3">
            Location
            <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                <option value="" selected>--- Select Location ---</option>
                @foreach($arrLocations as $location)
                <option value="{{ $location->name }}">{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-3">
            Date Range
            <input type="text" name="strFilterDate" id="strFilterDate" class="form-control" placeholder="Select Date Range">
            <input type="hidden" name="strFilterFromDate" id="strFilterFromDate">
            <input type="hidden" name="strFilterToDate" id="strFilterToDate">
        </div>
        <div class="col-3">
            Store
            <select id="strFilterStore" name="strFilterStore" class="form-select">
                <option value="" selected>--- Select Store ---</option>
                @foreach($arrStores as $store)
                <option value="{{ $store->name }}">{{ $store->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-3">
            <br>
            <button type="button" class="btn btn-primary" id="export_pdf_btn">Export PDF</button>
        </div>
    </div>
    <br>
    <div class="row">
        <div class="col-md-12">
            <form id="locations_revenue_form">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
                    <thead>
                        <tr>
                            <th style="width:11%;">
                                Location
                                
                            </th>
                            <th style="width:11%;">
                                Date Range
                            </th>
                            <th style="width:11%;">
                                Store
                            </th>
                            <th style="width:11%;">Revenue</th>
                            <th style="width:11%;">No Status</th>
                            <th style="width:11%;">Cancelled</th>
                            <th style="width:11%;">Refunded</th>
                            <th style="width:11%;">Items Sold Preorders</th>
                            <th style="width:11%;">Items Created Immediate Inventory</th>
                            <th style="width:11%;">Items Sold Immediate Inventory</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                    <tfoot>
                        <tr id="grand-total-row" style="display: none;">
                            <th colspan="3" style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;">Grand Total</th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                            <th style="text-align: right; font-weight: bold; background-color: #f8f9f9; border-top: 2px solid #dee2e6;"></th>
                        </tr>
                    </tfoot>
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
    @include('partials.app_navigation')

    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

    <script type="text/javascript">
    	$(function(){
            // Initialize DataTable without adding data initially - only with headers
            window.table = jQuery('.js-dataTable-full').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 20], [5, 10, 20]],
                order:[[0, 'asc']], // Changed from 'desc' to 'asc' for better default sorting
                columnDefs: [
                    { orderable: true, targets: 0 }, // Make location column sortable
                    { orderable: true, targets: 1 }  // Make date column sortable
                ],
                autoWidth: false,
                dom: 'frtip', // Remove buttons container since we're using external button
                drawCallback: function(settings) {
                    // Re-add detail rows after each draw operation (pagination, sorting, etc.)
                    addDetailRows();
                    updateGrandTotals();
                }
            });

            // Logo URL for PDF export
            const logoUrl = "{{ asset('logo-black.png') }}";

            // Initialize Bootstrap Date Range Picker
            $('#strFilterDate').daterangepicker({
                opens: 'left',
                autoUpdateInput: false,
                locale: {
                    format: 'DD.MM.YYYY',
                    cancelLabel: 'Clear'
                },
                ranges: {
                    'Today': [moment(), moment()],
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                }
            });

            $('#strFilterDate').on('apply.daterangepicker', function(ev, picker) {
                var start = picker.startDate.format('DD.MM.YYYY');
                var end = picker.endDate.format('DD.MM.YYYY');
                $(this).val(start + ' - ' + end);
                $('#strFilterFromDate').val(start);
                $('#strFilterToDate').val(end);
                $(this).trigger('change');
            });

            $('#strFilterDate').on('cancel.daterangepicker', function(ev, picker) {
                $(this).val('');
                $('#strFilterFromDate').val('');
                $('#strFilterToDate').val('');
                $(this).trigger('change');
            });

            // Handle location dropdown change
            $(document).on('change', '#strFilterLocation', function(e){
                if ($(this).val() !== '') {
                    // Clear store selection when location is selected
                    $('#strFilterStore').val('');
                }
                LoadList();
            });
            
            // Handle store dropdown change
            $(document).on('change', '#strFilterStore', function(e){
                if ($(this).val() !== '') {
                    // Clear location selection when store is selected
                    $('#strFilterLocation').val('');
                }
                LoadList();
            });
            
            // Handle date range change
            $(document).on('change', '#strFilterDate', function(e){
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
            
            // Handle PDF export button click
            $(document).on('click', '#export_pdf_btn', function(e){
                // Generate title based on current filters
                var location = $('#strFilterLocation option:selected').text();
                var store = $('#strFilterStore option:selected').text();
                var fromDate = $('#strFilterFromDate').val();
                var toDate = $('#strFilterToDate').val();
                var dateRange = $('#strFilterDate').val();
                
                // Create subheading
                var subHeading = '';
                var formattedDateRange = '';
                
                if (fromDate && toDate) {
                    // Parse and format dates with 3-letter months
                    var startMoment = moment(fromDate, 'DD.MM.YYYY');
                    var endMoment = moment(toDate, 'DD.MM.YYYY');
                    formattedDateRange = startMoment.format('DD MMM YYYY') + ' - ' + endMoment.format('DD MMM YYYY');
                }
                
                if (store && store !== '--- Select Store ---') {
                    subHeading = store;
                    if (formattedDateRange) {
                        subHeading += ' - ' + formattedDateRange;
                    }
                } else if (formattedDateRange) {
                    subHeading = formattedDateRange;
                } else {
                    subHeading = 'All Data';
                }
                
                // Logo URL with full absolute path for print window
                var fullLogoUrl = logoUrl;
                
                // Compute grand totals if store is selected
                var storeFilter = $('#strFilterStore').val();
                var showFooter = storeFilter !== '';
                var formattedRevenue = '';
                var totalNoStatus = 0;
                var totalCancelled = 0;
                var totalRefunded = 0;
                var totalItemsSold = 0;
                var totalImmediateInventory = 0;
                var totalImmediateInventoryItemsSold = 0;
                var totalOrders = 0;
                
                if (showFooter) {
                    var totalRevenueCalc = 0;
                    $('.revenue-data').each(function() {
                        var text = $(this).text().replace('€ ', '').replace(/\./g, '').replace(',', '.');
                        totalRevenueCalc += parseFloat(text) || 0;
                    });
                    formattedRevenue = '&euro; ' + totalRevenueCalc.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    
                    $('.no-status-data').each(function() {
                        totalNoStatus += parseInt($(this).text()) || 0;
                    });
                    
                    $('.cancelled-data').each(function() {
                        totalCancelled += parseInt($(this).text()) || 0;
                    });
                    
                    $('.refunded-data').each(function() {
                        totalRefunded += parseInt($(this).text()) || 0;
                    });

                    $('.preorders-data').each(function() {
                        totalItemsSold += parseInt($(this).text()) || 0;
                    });

                    $('.immediate-inventory-data').each(function() {
                        totalImmediateInventory += parseInt($(this).text()) || 0;
                    });

                    $('.immediate-inventory-items-sold-data').each(function() {
                        totalImmediateInventoryItemsSold += parseInt($(this).text()) || 0;
                        totalOrders += parseInt($(this).data('total-orders')) || 0;
                    });
                }
                
                // Create a new window for PDF generation
                var printWindow = window.open('', '_blank');
                var printContent = '<!DOCTYPE html><html><head>';
                printContent += '<title>Locations Revenue Report</title>';
                printContent += '<style>';
                printContent += 'body { font-family: "Segoe UI", Arial, sans-serif; margin: 0; padding: 0px 10px; background-color: #f8f9fa; }';
                printContent += '.report-container { background-color: white; padding: 10px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 1200px; margin: 0 auto; }';
                printContent += '.report-header { text-align: center; position: relative;  }';
                printContent += '.company-logo { max-width: 120px; height: auto; float:left; margin-top: 3%;  margin-right: -10%; }';
                printContent += '.headings-container { display: inline-block; margin: 0 auto; }';
                printContent += '.locations-heading { font-size: 26px; font-weight: 600; color: #2c3e50; }';
                printContent += '.sub-heading { font-size: 24px; color: #2c3e50; margin: 0 0 10px 0; font-weight: 400; }';
                printContent += 'table { width: 100%; border-collapse: collapse; margin-top: 25px; background-color: white; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }';
                printContent += 'th, td { border: 1px solid #dee2e6; padding: 12px 8px; text-align: left; font-size: 13px; background-color: white; }';
                printContent += 'th { background-color: #f2f2f2; color: #333; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }';
                printContent += 'tbody tr { background-color: white; }';
                printContent += 'tbody tr:hover { background-color: #f8f9fa; }';
                printContent += '.text-right { text-align: right; font-weight: 500; }';
                printContent += '.text-center { text-align: center; }';
                printContent += '.detail-row { background-color: #f1f3f4 !important; }';
                printContent += '.detail-row td { border-top: 0; padding: 15px; font-size: 12px; color: #495057; background-color: #f1f3f4 !important; }';
                printContent += '.detail-row td div { margin-bottom: 5px; }';
                printContent += '.detail-row td div:last-child { margin-bottom: 0; }';
                printContent += 'tfoot th { background-color: #f8f9fa; border-top: 2px solid #dee2e6; font-weight: bold; font-size: 14px; }';
                printContent += '.tfoot .text-right { text-align: right; }';
                printContent += '@media print { body { background-color: white; } .report-container { box-shadow: none; } .company-logo { position: static; transform: none; left: 0; top: auto; display: inline-block; margin-right: 20px; vertical-align: middle; } .headings-container { display: inline-block; vertical-align: middle; } }';
                printContent += '</style></head><body>';
                printContent += '<div class="report-container">';
                printContent += '<div class="report-header">';
                printContent += '<img src="' + fullLogoUrl + '" alt="Sushi.Catering Logo" class="company-logo"  >';
                printContent += '<div class="headings-container">';
                printContent += '<h1 class="locations-heading">Locations Revenue</h1>';
                printContent += '<p class="sub-heading">' + subHeading + '</p>';
                printContent += '</div>';
                printContent += '</div>';
                printContent += '<table>';
                
                // Add table headers (full 9 columns matching view)
                printContent += '<thead><tr>';
                printContent += '<th>Location</th>';
                printContent += '<th>Date Range</th>';
                printContent += '<th>Store</th>';
                printContent += '<th class="text-right">Revenue</th>';
                printContent += '<th class="text-right">No Status</th>';
                printContent += '<th class="text-right">Cancelled</th>';
                printContent += '<th class="text-right">Refunded</th>';
                printContent += '<th class="text-right">Items Sold Preorders</th>';
                printContent += '<th class="text-right">Items Created Immediate Inventory</th>';
                printContent += '<th class="text-right">Items Sold Immediate Inventory</th>';
                printContent += '</tr></thead>';
                
                // Add table body
                printContent += '<tbody>';
                
                // Add all visible rows including detail rows (include all columns)
                $('#locations_revenue_form tbody tr').each(function() {
                    var $row = $(this);
                    var isDetailRow = $row.hasClass('detail-row');

                    if (isDetailRow) {
                        printContent += '<tr class="detail-row">';
                        printContent += '<td colspan="10">';
                        printContent += $row.find('td').html();
                        printContent += '</td></tr>';
                    } else {
                        printContent += '<tr>';
                        $row.find('td').each(function(index) {
                            var cellContent = $(this).text();
                            var cellClass = '';
                            
                            // Center align date range (index 1)
                            if (index === 1) {
                                cellClass = 'text-center';
                            }
                            // Right-align numeric columns (indices 3-9 for all 10 columns)
                            else if (index >= 3 && index <= 9) {
                                cellClass = 'text-right';
                            }
                            
                            printContent += '<td class="' + cellClass + '">' + cellContent + '</td>';
                        });
                        printContent += '</tr>';
                    }
                });
                
                printContent += '</tbody>';
                
                // Add footer if store is selected
                if (showFooter) {
                    printContent += '<tfoot>';
                    printContent += '<tr>';
                    printContent += '<th colspan="3" class="text-right">Grand Total<br><small>Total Orders: ' + totalOrders.toLocaleString('de-DE') + '</small></th>';
                    printContent += '<th class="text-right">' + formattedRevenue + '</th>';
                    printContent += '<th class="text-right">' + totalNoStatus.toLocaleString('de-DE') + '</th>';
                    printContent += '<th class="text-right">' + totalCancelled.toLocaleString('de-DE') + '</th>';
                    printContent += '<th class="text-right">' + totalRefunded.toLocaleString('de-DE') + '</th>';
                    printContent += '<th class="text-right">' + totalItemsSold.toLocaleString('de-DE') + '</th>';
                    printContent += '<th class="text-right">' + totalImmediateInventory.toLocaleString('de-DE') + '</th>';
                    printContent += '<th class="text-right">' + totalImmediateInventoryItemsSold.toLocaleString('de-DE') + '</th>';
                    printContent += '</tr>';
                    printContent += '</tfoot>';
                }
                
                printContent += '</table>';
                printContent += '</div></body></html>';
                
                // Write content to new window and trigger print
                printWindow.document.write(printContent);
                printWindow.document.close();
                
                // Wait for content to load then trigger print dialog
                setTimeout(function() {
                    printWindow.print();
                    printWindow.close();
                }, 500);
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
    		
        });

        function LoadList(){
        	$.ajax({
            	url:"{{ route('getLocationsRevenueList') }}",
            	type:"GET",
            	data: {
                    "_token": "{{ csrf_token() }}",
                    "strFilterLocation": $("#strFilterLocation").val(),
                    // "strFilterDate": $("#strFilterDate").val(),
                    "strFilterFromDate": $("#strFilterFromDate").val(),
                    "strFilterToDate": $("#strFilterToDate").val(),
                    "strFilterStore": $("#strFilterStore").val()
            	},
            	cache:false,
            	dataType:"html",
            	success:function(data){
                    // Clear the existing table data
                    table.clear();
                    
                    // Add the main rows to DataTables
                    table.rows.add($(data)).draw(false);
                    
                    // Now add the detail rows after DataTables initialization
                    addDetailRows();
                    updateGrandTotals();
                },
                error: function (request, status, error) {
                    table.clear();
                    console.log('error fetching Locations Revenue Data');
                }
            });
        }
        
        function addDetailRows() {
            // Remove any existing detail rows first
            $('#locations_revenue_form tbody tr.detail-row').remove();

            // For each main row, add a detail row after it
            // Data attributes are now stored in the immediate-inventory-items-sold-data cell
            $('#locations_revenue_form tbody tr:not(.detail-row)').each(function() {
                var dataCell = $(this).find('.immediate-inventory-items-sold-data');

                if (dataCell.length > 0) {
                    var location = dataCell.data('location');
                    var startDate = dataCell.data('start-date');
                    var endDate = dataCell.data('end-date');
                    var store = dataCell.data('store');
                    var totalOrders = dataCell.data('total-orders');
                    var noStatusOrders = dataCell.data('no-status-orders');
                    var cancelledOrders = dataCell.data('cancelled-orders');
                    var refundedOrders = dataCell.data('refunded-orders');
                    var preorders = dataCell.data('preorders');
                    var immediateInventory = dataCell.data('immediate-inventory');
                    var immediateInventoryItemsSold = dataCell.data('immediate-inventory-items-sold');

                    var detailHtml = '<tr class="detail-row">' +
                        '<td colspan="10" style="background-color: #f9f9f9; padding: 15px; border-top: 0;">' +
                        '<div><strong>Total Orders:</strong> ' + totalOrders + '</div>' +
                        '<div><strong>Orders (No Status):</strong> ' + noStatusOrders + '</div>' +
                        '<div><strong>Orders (Cancelled):</strong> ' + cancelledOrders + '</div>' +
                        '<div><strong>Orders (Refunded):</strong> ' + refundedOrders + '</div>' +
                        '<div><strong>Orders (Preorders):</strong> ' + preorders + '</div>' +
                        '<div><strong>Orders (Immediate Inventory):</strong> ' + immediateInventoryItemsSold + '</div>' +
                        '</td>' +
                        '</tr>';
                    
                    // Insert the detail row after the current main row
                    $(this).after(detailHtml);
                }
            });
        }

        function updateGrandTotals() {
            var storeFilter = $('#strFilterStore').val();
            var $footerRow = $('#grand-total-row');
            if (storeFilter === '') {
                $footerRow.hide();
                return;
            }

            var totalRevenue = 0;
            $('.revenue-data').each(function() {
                var text = $(this).text().replace('€ ', '').replace(/\./g, '').replace(',', '.');
                totalRevenue += parseFloat(text) || 0;
            });

            var totalNoStatus = 0;
            $('.no-status-data').each(function() {
                totalNoStatus += parseInt($(this).text()) || 0;
            });

            var totalCancelled = 0;
            $('.cancelled-data').each(function() {
                totalCancelled += parseInt($(this).text()) || 0;
            });

            var totalRefunded = 0;
            $('.refunded-data').each(function() {
                totalRefunded += parseInt($(this).text()) || 0;
            });

            var totalItemsSold = 0;
            $('.preorders-data').each(function() {
                totalItemsSold += parseInt($(this).text()) || 0;
            });

            var totalImmediateInventory = 0;
            $('.immediate-inventory-data').each(function() {
                totalImmediateInventory += parseInt($(this).text()) || 0;
            });

            var totalImmediateInventoryItemsSold = 0;
            $('.immediate-inventory-items-sold-data').each(function() {
                totalImmediateInventoryItemsSold += parseInt($(this).text()) || 0;
            });

            var totalOrders = 0;
            $('.immediate-inventory-items-sold-data').each(function() {
                totalOrders += parseInt($(this).data('total-orders')) || 0;
            });

            $footerRow.find('th').eq(0).html('Grand Total<br><small>Total Orders: ' + totalOrders.toLocaleString('de-DE') + '</small>');
            $footerRow.find('th').eq(1).html('&euro; ' + totalRevenue.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            $footerRow.find('th').eq(2).html(totalNoStatus.toLocaleString('de-DE'));
            $footerRow.find('th').eq(3).html(totalCancelled.toLocaleString('de-DE'));
            $footerRow.find('th').eq(4).html(totalRefunded.toLocaleString('de-DE'));
            $footerRow.find('th').eq(5).html(totalItemsSold.toLocaleString('de-DE'));
            $footerRow.find('th').eq(6).html(totalImmediateInventory.toLocaleString('de-DE'));
            $footerRow.find('th').eq(7).html(totalImmediateInventoryItemsSold.toLocaleString('de-DE'));

            $footerRow.show();
        }
    </script>
@endsection
