@extends('layouts.app')

@section('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/fontawesome.min.css" integrity="sha512-v8QQ0YQ3H4K6Ic3PJkym91KoeNT5S3PnDKvqnwqFD1oiqIl653crGZplPdU5KKtHjO0QKcQ2aUlQZYjHczkmGw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/solid.min.css" integrity="sha512-DzC7h7+bDlpXPDQsX/0fShhf1dLxXlHuhPBkBo/5wJWRoTU6YL7moeiNoej6q3wh5ti78C57Tu1JwTNlcgHSjg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    /* Background colors for locations */
    .location-1 {
        background-color: #add8e6 !important; /* Light Blue */
    }
    .location-2 {
        background-color: #f5deb3 !important; /* Wheat */
    }
    .location-3 {
        background-color: #90ee90 !important; /* Light Green */
    }
    .location-4 {
        background-color: #dda0dd !important; /* Plum */
    }
    .location-5 { background-color: #ffe4b5 !important; /* Moccasin */ }
    .location-6 { background-color: #e6e6fa !important; /* Lavender */ }
    .location-7 { background-color: #d3d3d3 !important; /* Light Gray */ }
    .location-8 { background-color: #98fb98 !important; /* Pale Green */ }
    .location-9 { background-color: #afeeee !important; /* Pale Turquoise */ }
    .location-10 { background-color: #deb887 !important; /* Burlywood */ }
    .location-11 { background-color: #ffdead !important; /* Navajo White */ }
    .location-12 { background-color: #b0e0e6 !important; /* Powder Blue */ }
    button::after {
        position: absolute;
        z-index: 100;
        right: 16px;
    }
    .map_canvas {
        display: none;
    }
</style>
@endsection

@section('content')
<div class="container-full p-2">
    <div class="row">
        <div class="col-12">
            <h5>Sushi Catering PreOrders & Immediate Inventory</h5>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div id="accordion" class="accordion">
                @foreach($arrData as $location => $arrProducts)
                    <div class="accordion-item">
                        <div class="accordion-header" id="heading{{ $loop->index }}">
                            <h5 class="mb-0 ">
                                <button class="accordion-button bg-light d-block text-center fw-bold" data-bs-toggle="collapse" data-bs-target="#collapse{{ $loop->index }}" aria-expanded="false" aria-controls="collapse{{ $loop->index }}">
                                    {{ ($loop->index + 1) . ". " . $location }} <span class="badge bg-primary">{{ $arrTotalOrders[$location]['total_orders_count'] }}</span>
                                </button>
                            </h5>
                        </div>

                        <div id="collapse{{ $loop->index }}" class="accordion-collapse collapse" aria-labelledby="heading{{ $loop->index }}" data-bs-parent="#accordion">
                            <div class="accordion-body">
                                <p>{!! $arrProducts['location_data']['address'] !!}</p>

                                <div class="d-grid gap-2 col-12 mx-auto">
                                    <div class="col-auto">
                                        <button
                                            type="button"
                                            class="btn btn-primary open-google-maps"
                                            data-address="{{ ($arrProducts['location_data']['maps_directions']) ? $arrProducts['location_data']['maps_directions'] : '' }}"
                                            data-latitude="{{ ($arrProducts['location_data']['latitude']) ? $arrProducts['location_data']['latitude'] : '' }}"
                                            data-longitude="{{ ($arrProducts['location_data']['longitude']) ? $arrProducts['location_data']['longitude'] : '' }}">
                                            <i class="fa-solid fa-map-location-dot"></i> Open in Google Maps
                                        </button>
                                    </div>

                                    <div class="map_canvas mb-3" style="height: 400px; width: 100%;">
                                        <div id="map{{ $loop->index }}" style="height: 100%; width: 100%; text-align:center;">
                                            <div class="spinner-border spinner-border-sm text-danger loading-spinner" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <br>

                                <h6>IMMEDIATE INVENTORY</h6>
                                <div class="row">
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($arrProducts))
                                        @foreach($arrProducts['immediate_inventory_slot']['products'] as $productName => $quantity)
                                            @if($productCount < 4)
                                                <div class="col-12 col-sm-6">
                                                    <div class="row">
                                                        <div class="col-4 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-qty">
                                                                {{ $quantity }}
                                                        </div>
                                                        <div class="col-8 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-product">
                                                                {{ $productName }}
                                                        </div>
                                                    </div>
                                                </div>
                                                @php
                                                    $productCount++;
                                                @endphp
                                            @else
                                                @break
                                            @endif
                                        @endforeach
                                    @endif

                                    @for($i = $productCount; $i < 4; $i++)
                                    <div class="col-12 col-sm-6">
                                        <div class="row">
                                            <div class="col-4">
                                                &nbsp;
                                            </div>
                                            <div class="col-8">
                                                &nbsp;
                                            </div>
                                        </div>
                                    </div>
                                    @endfor
                                </div>


                                <h6>PREORDER</h6>
                                <div class="row">
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($arrProducts))
                                        @foreach($arrProducts['preorder_slot']['products'] as $productName => $quantity)
                                            <div class="col-12 col-sm-6">
                                                <div class="row">
                                                    <div class="col-4 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-qty">
                                                            {{ $quantity }}
                                                    </div>
                                                    <div class="col-8 border border-secondary p-2 location-{{ $productCount+1 }} text-center column-product">
                                                            {{ $productName }}
                                                    </div>
                                                </div>
                                            </div>
                                            @php
                                                $productCount++;
                                            @endphp
                                        @endforeach
                                    @endif

                                    @for($i = $productCount; $i < 4; $i++)
                                    <div class="col-12 col-sm-6">
                                        <div class="row">
                                            <div class="col-4">
                                                &nbsp;
                                            </div>
                                            <div class="col-8">
                                                &nbsp;
                                            </div>
                                        </div>
                                    </div>
                                    @endfor
                                </div>



                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @parent
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDFGRlqpzt4EZKMT9f65pHWsI_hza6QNQ0&libraries=places&loading=async"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        // Save current page scroll position before reload
        $(window).on('beforeunload', function() {
            localStorage.setItem('scrollPosition', $(window).scrollTop());
        });
        
        // Function to store open accordion items
        function storeAccordionState() {
            var openItems = [];
            $('.accordion-collapse.show').each(function() {
                openItems.push($(this).attr('id'));
            });
            localStorage.setItem('openAccordionItems', JSON.stringify(openItems));
        }
        
        // Function to restore open accordion items
        function restoreAccordionState() {
            var openItems = localStorage.getItem('openAccordionItems');
            if (openItems) {
                try {
                    openItems = JSON.parse(openItems);
                    // Open saved accordion items
                    openItems.forEach(function(itemId) {
                        $('#' + itemId).addClass('show');
                        $('[data-bs-target="#' + itemId + '"]').attr('aria-expanded', 'true').removeClass('collapsed');
                    });
                } catch (e) {
                    console.error("Error parsing stored accordion state:", e);
                }
            } else {
                // If no stored state, open first item by default (initial page load only)
                $('#collapse0').addClass('show');
                $('[data-bs-target="#collapse0"]').attr('aria-expanded', 'true');
            }
            
            // Restore scroll position
            var scrollPosition = localStorage.getItem('scrollPosition');
            if (scrollPosition) {
                $(window).scrollTop(scrollPosition);
            }
        }

        // Set up reload interval with preserved state
        var reloadTimer = setInterval(function() {
            storeAccordionState();
            window.location.reload();
        }, 60000); // 1 minute in milliseconds

        // Listen for accordion changes to store state
        $(document).on('shown.bs.collapse hidden.bs.collapse', '.accordion-collapse', function() {
            storeAccordionState();
        });

        function initMap() {
            $(document).on('click', '.map_button', function() {
                var button = $(this);
                var mapCanvas = button.parent().parent().parent().find('.map_canvas');
                var mapDiv = mapCanvas.find('[id^="map"]');

                if (mapDiv.length === 0) {
                    console.error("Map div not found for this button.");
                    return;
                }

                if (mapCanvas.is(':hidden')) {
                    mapCanvas.show();
                    var latitude = parseFloat(button.data('latitude'));
                    var longitude = parseFloat(button.data('longitude'));
                    var address = button.data('address');

                    if (!isNaN(latitude) && !isNaN(longitude)) {
                        // Use latitude and longitude if available
                        initializeMap(mapDiv[0], { lat: latitude, lng: longitude });
                    } else if (address) {
                        // Use address if latitude and longitude are not available
                        geocodeAddress(address, function(location) {
                            initializeMap(mapDiv[0], location);
                        });
                    } else {
                        console.error("No valid location data provided.");
                    }
                } else {
                    mapCanvas.hide();
                }
            });

            $(document).on('click', '.open-google-maps', function() {
                const latitude = $(this).data('latitude');
                const longitude = $(this).data('longitude');
                const address = $(this).data('address') || '';

                let googleMapsAppUrl = '';
                let googleMapsWebUrl = '';

                // 1) Construct the appropriate URLs for app and web
                if (latitude && longitude) {
                    // Use latitude & longitude
                    googleMapsAppUrl = `comgooglemaps://?q=${latitude},${longitude}`;
                    googleMapsWebUrl = `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`;
                } else {
                    // Fallback to address
                    const encodedAddress = encodeURIComponent(address.trim());
                    googleMapsAppUrl = `comgooglemaps://?q=${encodedAddress}`;
                    googleMapsWebUrl = `https://www.google.com/maps/search/?api=1&query=${encodedAddress}`;
                }

                // 2) Check device width to determine if we're on (roughly) mobile or not
                const isMobile = window.matchMedia('(max-width: 1024px)').matches;
                const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

                if (isMobile && isTouchDevice) {
                    // If it's a mobile device (screen width <= 768px) and has touch capabilities:
                    // Attempt to open the Google Maps app first
                    window.open(googleMapsAppUrl, '_blank');

                    // Fallback to web if the app is not installed or fails to open
                    setTimeout(function() {
                        window.open(googleMapsWebUrl, '_blank');
                    }, 500);
                } else {
                    // If it's not a mobile device (screen width > 768px) or doesn't have touch capabilities,
                    // just open the browser link
                    window.open(googleMapsWebUrl, '_blank');
                }
            });
        }

        function initializeMap(mapDiv, location) {
            if (!mapDiv || !(mapDiv instanceof HTMLElement)) {
                console.error("Invalid mapDiv element:", mapDiv);
                return;
            }

            var map = new google.maps.Map(mapDiv, {
                center: location,
                zoom: 15
            });
            new google.maps.Marker({
                position: location,
                map: map
            });

            google.maps.event.addListenerOnce(map, 'idle', function() {
                $(".loading-spinner").hide();
            });
        }

        function geocodeAddress(address, callback) {
            var geocoder = new google.maps.Geocoder();
            geocoder.geocode({ 'address': address }, function(results, status) {
                if (status === 'OK') {
                    var location = results[0].geometry.location;
                    callback({ lat: location.lat(), lng: location.lng() });
                } else {
                    console.error('Geocode was not successful: ' + status);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            // Restore accordion state after DOM is fully loaded
            restoreAccordionState();
        });
    </script>
@endsection