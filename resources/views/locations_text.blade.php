@extends('shopify-app::layouts.default')

@section('styles')
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/css/bootstrap-timepicker.min.css" rel="stylesheet">
<style>
    #save_btn {display: none;}
    
    /* Responsive Table Layout */
    #locations_text_form table td:first-child, #locations_text_form table th:first-child{width:50%;}
    #locations_text_form table td:nth-child(2), #locations_text_form table th:nth-child(2){width:25%;}
    #locations_text_form table td:nth-child(3), #locations_text_form table th:nth-child(3){width:25%;}

    .responsive-input {
        width: 100%;
        max-width: 150px;
    }

    @media (max-width: 768px) {
        #locations_text_form table td:first-child, #locations_text_form table th:first-child{width:40%;}
        #locations_text_form table td:nth-child(2), #locations_text_form table th:nth-child(2){width:30%;}
        #locations_text_form table td:nth-child(3), #locations_text_form table th:nth-child(3){width:30%;}
        
        .form-control, .form-select {
            font-size: 14px; /* Prevent zoom on iOS */
        }
        
        .responsive-input {
            max-width: 100%; /* Allow full width on mobile if needed, or keep 150px if that's enough */
        }
    }

    /* Keep the immediate inventory quantity/time inputs in the DOM for backend submissions,
       but force them hidden at all times to satisfy the requirement without altering JS logic. */
    .immediate_inventory_quantity_check_time_portion,
    .immediate_inventory_order_quantity_limit_portion {display: none !important;}
</style>
@endsection

@section('content')
{{-- AppBridge v4 App Nav - Global Navigation Menu --}}
@include('partials.app_nav')

{{-- AppBridge v4 Title Bar using s-page web component --}}
<s-page heading="Location Settings">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Order Overview', 'path' => '/orders']])
</s-page>

<div class="container-fluid p-2">
    <div class="row">
        <div class="col-md-12">
            <form id="locations_text_form">
                <div class="admin-help-row">
                    <span class="fw-semibold">Page help</span>
                    @include('partials.admin_help_tooltip', ['text' => 'Maintain each location\'s operational settings, public-facing notes, delivery behavior, coordinates, and schedule fields from this screen.'])
                </div>
                <div class="row align-items-center mb-2">
                    <div class="col-12 col-md-6 mb-2 mb-md-0">
                        <label class="label fw-bold font-bold admin-help-label" for="strFilterLocation">Filter Location
                            @include('partials.admin_help_tooltip', ['text' => 'Load one location record into the editable form below. All textareas, toggles, and timing fields update when you switch the selection.'])
                        </label>
                    </div>
                    {{-- On mobile: buttons stack full-width (d-grid). On md+: buttons sit inline right-aligned (d-md-flex). --}}
                    <div class="col-12 col-md-6 d-grid d-md-flex justify-content-md-end gap-2">
                        <button type="button" class="btn btn-primary" id="exportExcelBtn">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                        @include('partials.admin_help_tooltip', ['text' => 'Export the current location settings dataset to Excel for review, backup, or offline edits.'])
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                            <i class="fas fa-plus"></i> Add Location
                        </button>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <select id="strFilterLocation" name="strFilterLocation" class="form-select">
                        <option value="" selected>--- Select Location ---</option>
                        @foreach($arrLocations as $loc)
                        <option value="{{ $loc->name }}">{{ $loc->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="table-responsive">
                    <div class="admin-help-row">
                        <span class="fw-semibold">Operating hours table</span>
                        @include('partials.admin_help_tooltip', ['text' => 'Edit the visible start and end times for the selected location. These rows define the core time windows used by related admin and storefront workflows.'])
                    </div>
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
                <div class="row">
                    <div class="col-12 col-md-6 mb-3">
                        <div class="form-group h-100">
                            <label class="label fw-bold font-bold" for="address">Address</label>
                            <textarea id='address' name='address' rows='6' class='form-control h-100'></textarea>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-3">
                            <label class="label fw-bold font-bold" for="maps_directions">Maps Directions</label>
                            <textarea id='maps_directions' name='maps_directions' rows='3' class='form-control'></textarea>
                        </div>
                        <div class="row">
                            <div class="col-12 col-md-6 mb-3 mb-md-0">
                                <div class="form-group">
                                    <label class="label fw-bold font-bold" for="latitude">Latitude</label>
                                    <input type="text" id='latitude' name='latitude' class='form-control'></input>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="form-group">
                                    <label class="label fw-bold font-bold" for="longitude">Longitude</label>
                                    <input type="text" id='longitude' name='longitude' class='form-control'></input>
                                </div>
                            </div>
                        </div>
                        <br>
                    </div>
                </div>
                <br>
                <div class="form-group">
                    <label class="label fw-bold font-bold" for="note">Note (For Store Frontend)</label>
                    <textarea id='note' name='note' rows='3' class='form-control'></textarea>
                </div>
                <br>
                <div class="form-group checkout_note_portion">
                    <label class="label fw-bold font-bold" for="checkout_note">Checkout Note (For Delivery)</label>
                    <textarea id='checkout_note' name='checkout_note' rows='3' class='form-control'></textarea>
                </div>
                <br>
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="Y" id="location_toggle" name="location_toggle">
                            <label class="form-check-label admin-help-label" for="location_toggle">
                                Location Active
                                @include('partials.admin_help_tooltip', ['text' => 'Turn the selected location on or off for operational use. Inactive locations stay in the data but should not be treated as live.'])
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2 accept_only_preorders_portion">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="N" id="accept_only_preorders" name="accept_only_preorders">
                            <label class="form-check-label admin-help-label" for="accept_only_preorders">
                                Accept only PreOrders
                                @include('partials.admin_help_tooltip', ['text' => 'Allow the location to accept preorder sales only, without normal immediate-order availability.'])
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2 no_station_portion">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="N" id="no_station" name="no_station">
                            <label class="form-check-label admin-help-label" for="no_station">
                                No Station
                                @include('partials.admin_help_tooltip', ['text' => 'Mark this location as not operating as a standard catering station in flows that distinguish station-based locations.'])
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2 additional_inventory_portion">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="N" id="additional_inventory" name="additional_inventory">
                            <label class="form-check-label admin-help-label" for="additional_inventory">
                                Additional Inventory
                                @include('partials.admin_help_tooltip', ['text' => 'Flag the location for additional inventory handling instead of the regular standard inventory path.'])
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2 immediate_inventory_portion">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="N" id="immediate_inventory" name="immediate_inventory">
                            <label class="form-check-label admin-help-label" for="immediate_inventory">
                                Immediate Inventory
                                @include('partials.admin_help_tooltip', ['text' => 'Enable the immediate-inventory workflow for this location so same-day stock can be managed and sold.'])
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2 location_public_private_portion">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="PUBLIC" id="location_public_private" name="location_public_private">
                            <label class="form-check-label admin-help-label" for="location_public_private">
                                Public Location
                                @include('partials.admin_help_tooltip', ['text' => 'Mark the location as public-facing instead of keeping it restricted to private or internal workflows.'])
                            </label>
                        </div>
                    </div>
                </div>
                <br>
                <div class="row g-3 immediate_inventory_48h_portion" style="display:none;">
                    <div class="col-12 col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="Y" id="immediate_inventory_48h" name="immediate_inventory_48h">
                            <label class="form-check-label admin-help-label" for="immediate_inventory_48h">
                                Yesterdays Items
                                @include('partials.admin_help_tooltip', ['text' => 'Keep yesterday\'s immediate-inventory items available under the 48-hour handling rule for this location.'])
                            </label>
                        </div>
                    </div>
                    {{-- Keep these inputs in the DOM so the form always submits values required by the DB, while visibility is still toggled via JS. --}}
                    <div class="col-12 col-md-4 immediate_inventory_quantity_check_time_portion" style="display:none;">
                        <label class="label" for="immediate_inventory_quantity_check_time">Immediate Inventory Quantity Check Time</label>
                        <input class="form-control" type="time" value="00:00" id="immediate_inventory_quantity_check_time" name="immediate_inventory_quantity_check_time">
                    </div>
                    <div class="col-12 col-md-5 immediate_inventory_48h_portion immediate_inventory_order_quantity_limit_portion" style="display:none;">
                        <label class="label" for="immediate_inventory_order_quantity_limit">Immediate Inventory Order Quantity Limit</label>
                        <input class="form-control" type="number" value="2" id="immediate_inventory_order_quantity_limit" name="immediate_inventory_order_quantity_limit">
                    </div>
                </div>
                <br>
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="Y" id="snacks_and_drinks" name="snacks_and_drinks">
                            <label class="form-check-label admin-help-label" for="snacks_and_drinks">
                                Snacks & Drinks
                                @include('partials.admin_help_tooltip', ['text' => 'Enable snacks and drinks behavior for this location so related items can be managed and reported separately.'])
                            </label>
                        </div>
                    </div>
                </div>
                <div class="row location_order_portion mt-3">
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="label fw-bold font-bold admin-help-label mb-1" for="location_order">Order
                            @include('partials.admin_help_tooltip', ['text' => 'Set the selected location\'s display order in location lists. Lower numbers appear earlier where this ordering is used.'])
                        </label>
                        <select name="location_order" id="location_order" class="form-control">
                            <option value="">--- Select ---</option>
                            @foreach($arrLocations as $loc)
                            <option value="{{ ($loop->index + 1) }}">{{ ($loop->index + 1) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <br>
                <button type="button" class="btn btn-primary w-100 w-md-auto" id="save_btn">Save</button>
                @include('partials.admin_help_tooltip', ['text' => 'Save all changes for the selected location, including text fields, checkboxes, coordinates, time windows, and order settings shown on this page.'])
            </form>
            <br>
            @php
                $variables = include resource_path('views/includes/notepad.php');
                extract($variables);
            @endphp

            <p>{!! nl2br(e($location_settings_text)) !!}</p>

            {{-- <hr>
            <form id="personal_notepad_form">
                <input type="hidden" name="personal_notepad_key" value="LOCATION_TEXT">
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

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1" aria-labelledby="addLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addLocationModalLabel">Add New Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addLocationForm">
                    <div class="form-group">
                        <label for="newLocationName" class="form-label">Location Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="newLocationName" name="location_name" required placeholder="Enter location name">
                        <div class="invalid-feedback" id="locationNameError"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveLocationBtn">
                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                    Save Location
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
    @parent
    @include('partials.app_navigation')

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/js/bootstrap-timepicker.min.js"></script>

    <script type="text/javascript">
    	$(function(){
              // Handle Export Excel button click — fetches the file in the background
              // so the page stays put (Shopify embedded apps break if you navigate away).
              // The response blob is turned into a temporary download link and auto-clicked.
              $(document).on('click', '#exportExcelBtn', function(e){
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Exporting...');

                fetch("{{ route('locations_text.exportExcel') }}", {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) {
                    if (!response.ok) throw new Error('Export failed');
                    return response.blob();
                })
                .then(function(blob) {
                    // Create a hidden <a> element, assign the blob as its href, click it, then remove it
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'Location_Settings.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    a.remove();
                })
                .catch(function(err) {
                    alert('Error exporting Excel: ' + err.message);
                })
                .finally(function() {
                    $btn.prop('disabled', false).html('<i class="fas fa-file-excel"></i> Export Excel');
                });
              });

              // Handle filter location change
              $(document).on('change', '#strFilterLocation', function(e){
                if($(this).val() == ""){
                    $("#save_btn").hide();
                    $(".accept_only_preorders_portion").hide();
                    $(".no_station_portion").hide();
                    $(".additional_inventory_portion").hide();
                    $(".immediate_inventory_portion").hide();
                    $(".immediate_inventory_48h_portion").hide();
                    $(".location_order_portion").hide();
                    $(".checkout_note_portion").hide();
                    $(".location_public_private_portion").hide();
                    $(".snacks_and_drinks_portion").hide();
                }
                else if($(this).val() == "Delivery"){
                    $("#save_btn").show();
                    $(".accept_only_preorders_portion").hide();
                    $(".no_station_portion").hide();
                    $(".additional_inventory_portion").hide();
                    $(".immediate_inventory_portion").hide();
                    $(".immediate_inventory_48h_portion").hide();
                    $(".location_order_portion").hide();
                    $(".checkout_note_portion").show();
                    $(".location_public_private_portion").hide();
                    $(".snacks_and_drinks_portion").hide();
                }
                else{
                    $("#save_btn").show();
                    $(".accept_only_preorders_portion").show();
                    $(".no_station_portion").show();
                    $(".additional_inventory_portion").show();
                    $(".immediate_inventory_portion").show();
                    $(".immediate_inventory_48h_portion").show();
                    $(".location_order_portion").show();
                    $(".checkout_note_portion").hide();
                    $(".location_public_private_portion").show();
                    $(".snacks_and_drinks_portion").show();
                }

                LoadList();
              });

              $(document).on('change', '#immediate_inventory', function(e){
                if($(this).is(":checked"))
                    $(".immediate_inventory_48h_portion").show();
                else
                    $(".immediate_inventory_48h_portion").hide();
              });

              $(document).on('change', '#immediate_inventory_48h', function(e){
                if($(this).is(":checked")){
                    $(".immediate_inventory_order_quantity_limit_portion").show();
                    $(".immediate_inventory_quantity_check_time_portion").show();
                }
                else{
                    $(".immediate_inventory_order_quantity_limit_portion").hide();
                    $(".immediate_inventory_quantity_check_time_portion").hide();
                }
              });
              
              // Handle save location text data
              $(document).on('click', '#save_btn', function(e){
                $.ajax({
                    url:"{{ route('locations_text.store') }}/" + $("#strFilterLocation").val(),
                    type:"PUT",
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

              // Handle add new location
              $(document).on('click', '#saveLocationBtn', function(e){
                e.preventDefault();

                var locationName = $('#newLocationName').val().trim();

                // Validate location name
                if(!locationName) {
                    $('#newLocationName').addClass('is-invalid');
                    $('#locationNameError').text('Location name is required');
                    return;
                }

                // Check if location already exists
                var existingLocations = [];
                $('#strFilterLocation option').each(function() {
                    if($(this).val() !== '') {
                        existingLocations.push($(this).val().toLowerCase());
                    }
                });

                if(existingLocations.includes(locationName.toLowerCase())) {
                    $('#newLocationName').addClass('is-invalid');
                    $('#locationNameError').text('Location already exists');
                    return;
                }

                // Remove validation classes
                $('#newLocationName').removeClass('is-invalid');
                $('#locationNameError').text('');

                // Show loading state
                var $btn = $(this);
                var $spinner = $btn.find('.spinner-border');
                $btn.prop('disabled', true);
                $spinner.removeClass('d-none');

                // Make AJAX request to add location
                $.ajax({
                    url: "{{ route('locations_text.addLocation') }}",
                    type: "POST",
                    data: {
                        "_token": "{{ csrf_token() }}",
                        "location_name": locationName
                    },
                    cache: false,
                    dataType: "json",
                    success: function(response) {
                        if(response.success) {
                            // Clear existing dropdown options (except the first one)
                            $('#strFilterLocation option:not(:first)').remove();

                            // Add updated locations in alphabetical order
                            if(response.updated_locations && response.updated_locations.length > 0) {
                                response.updated_locations.forEach(function(location) {
                                    $('#strFilterLocation').append('<option value="' + location + '">' + location + '</option>');
                                });
                            }

                            // Reset form and close modal properly
                            $('#addLocationForm')[0].reset();

                            // Hide modal and remove backdrop
                            var modal = bootstrap.Modal.getInstance(document.getElementById('addLocationModal'));
                            if (modal) {
                                modal.hide();
                            } else {
                                $('#addLocationModal').modal('hide');
                            }

                            // Force remove backdrop if it persists
                            setTimeout(function() {
                                $('.modal-backdrop').remove();
                                $('body').removeClass('modal-open');
                                $('body').css('padding-right', '');
                            }, 500);

                            // Show success message
                            alert('Location "' + locationName + '" added and imported successfully!');

                            window.location.reload();
                        } else {
                            alert('Error: ' + (response.message || 'Failed to add location'));
                        }
                    },
                    error: function(xhr, status, error) {
                        var errorMessage = 'Error adding location';
                        try {
                            var response = JSON.parse(xhr.responseText);
                            errorMessage = response.message || errorMessage;
                        } catch(e) {
                            // Use default error message if JSON parsing fails
                        }
                        alert(errorMessage);
                    },
                    complete: function() {
                        // Hide loading state
                        $btn.prop('disabled', false);
                        $spinner.addClass('d-none');
                    }
                });
              });

              // Clear validation when modal is hidden
              $('#addLocationModal').on('hidden.bs.modal', function() {
                  $('#addLocationForm')[0].reset();
                  $('#newLocationName').removeClass('is-invalid');
                  $('#locationNameError').text('');
              });

              // Clear validation when typing
              $('#newLocationName').on('input', function() {
                  $(this).removeClass('is-invalid');
                  $('#locationNameError').text('');
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

        // Load location data based on selection
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
                        $("#address").val(data.address);
                        $("#maps_directions").val(data.maps_directions);
                        $("#longitude").val(data.longitude);
                        $("#latitude").val(data.latitude);
                        $("#note").val(data.note);
                        $("#checkout_note").val(data.checkout_note);
                        $("#location_order").val(data.location_order);
                        $("#rows").html(data.html);
                        $("#location_toggle").prop('checked', data.location_toggle === 'Y');
                        $("#accept_only_preorders").prop('checked', data.accept_only_preorders === 'Y');
                        $("#no_station").prop('checked', data.no_station === 'Y');
                        $("#additional_inventory").prop('checked', data.additional_inventory === 'Y');
                        $("#immediate_inventory").prop('checked', data.immediate_inventory === 'Y');
                        $("#immediate_inventory_48h").prop('checked', data.immediate_inventory_48h === 'Y');
                        $("#immediate_inventory_order_quantity_limit").val(data.immediate_inventory_order_quantity_limit);
                        $("#immediate_inventory_quantity_check_time").val(data.immediate_inventory_quantity_check_time);

                        if(data.immediate_inventory == "Y")
                            $(".immediate_inventory_48h_portion").show();
                        else
                            $(".immediate_inventory_48h_portion").hide();
                        
                        if(data.immediate_inventory_48h == "Y"){
                            $(".immediate_inventory_order_quantity_limit_portion").show();
                            $(".immediate_inventory_quantity_check_time_portion").show();
                        }
                        else{
                            $(".immediate_inventory_order_quantity_limit_portion").hide();
                            $(".immediate_inventory_quantity_check_time_portion").hide();
                        }

                        $("#location_public_private").prop('checked', data.location_public_private === 'PUBLIC');
                        $("#snacks_and_drinks").prop('checked', data.snacks_and_drinks === 'Y');
                    }
                    else{
                        $("#address").val('');
                        $("#maps_directions").val('');
                        $("#longitude").val('');
                        $("#latitude").val('');
                        $("#note").val('');
                        $("#checkout_note").val('');
                        $("#location_order").val('');
                        $("#immediate_inventory_order_quantity_limit").val('2');
                        $("#rows").html('');
                        $("#location_toggle").prop('checked', false);
                        $("#accept_only_preorders").prop('checked', false);
                        $("#no_station").prop('checked', false);
                        $("#additional_inventory").prop('checked', false);
                        $("#immediate_inventory").prop('checked', false);
                        $("#immediate_inventory_48h").prop('checked', false);
                        $(".immediate_inventory_48h_portion").hide();
                        $("#immediate_inventory_quantity_check_time").val('--:--');
                        $("#location_public_private").prop('checked', false);
                        $("#snacks_and_drinks").prop('checked', false);
                    }
                },
                error: function (request, status, error) {
                    console.log('error fetching Locations Text Data');
                }
            });
        }
    </script>
@endsection
