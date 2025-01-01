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
    .customer_table th{
        font-weight: 500;
    }
    .map_canvas {
        display: none;
    }
    div.accordion-item.delivered{
        color: var(--bs-secondary-color) !important;
        opacity:0.4 !important;
    }
</style>
@endsection

@section('content')
<div class="container-full p-2">
    <div class="row">
        <div class="col-12">
            <h5>Sushi Catering Delivery Orders</h5>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div id="accordion" class="accordion">
                @foreach($arrData['Delivery'] as $nOrderId => $arrOrder)
                    @php
                        if(!empty($arrOrder['delivered_at'])){
                            $strDelivered = "delivered";
                        }
                        else
                            $strDelivered = "";
                    @endphp
                    <div class="accordion-item {{ $strDelivered }}" data-order_id="{{ $nOrderId }}">
                        <div class="accordion-header" id="heading{{ $loop->index }}">
                            <h5 class="mb-0 ">
                                <button class="accordion-button bg-light d-block text-center fw-bold" data-bs-toggle="collapse" data-bs-target="#collapse{{ $loop->index }}" aria-expanded="@if($loop->first) true @else false @endif" aria-controls="collapse{{ $loop->index }}">
                                    {{ ($loop->index + 1) . ". ORDER" }}
                                </button>
                            </h5>
                        </div>

                        <div id="collapse{{ $loop->index }}" class="accordion-collapse collapse @if($loop->first) show @endif" aria-labelledby="heading{{ $loop->index }}" data-bs-parent="#accordion">
                            <div class="accordion-body">

                                <h6>CLIENT AND ADDRESS</h6>
                                <table class="table table-condensed customer_table" border="0">
                                    <tr>
                                        <td>Name</td>
                                        <th>{{ ucwords($arrOrder['shipping']['first_name']) }} {{ ucwords($arrOrder['shipping']['last_name']) }}</th>
                                    </tr>
                                    <tr>
                                        <td>Phone</td>
                                        <th>{{ $arrOrder['shipping']['phone'] }}</th>
                                    </tr>
                                    <tr>
                                        <td>Address</td>
                                        <th>{{ $arrOrder['shipping']['address1'] }}<br>{{ $arrOrder['shipping']['address2'] }}</th>
                                    </tr>
                                    <tr>
                                        <td>City</td>
                                        <th>{{ $arrOrder['shipping']['city'] }}</th>
                                    </tr>
                                    <tr>
                                        <td>Zip</td>
                                        <th>{{ $arrOrder['shipping']['zip'] }}</th>
                                    </tr>
                                </table>
                                <br>
                                <h6>ITEMS</h6>
                                <div class="row m-0">
                                    @php
                                        $productCount = 0;
                                    @endphp
                                    @if(!empty($arrOrder))
                                        @foreach($arrOrder['products'] as $productName => $quantity)
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
                                <br>
                                <div class="d-grid gap-2 col-12 mx-auto">
                                    <button type="button" class="btn btn-info col-12 col-sm-8 col-md-6 mx-auto mb-3 map_button" data-address="{{ $arrOrder['shipping']['address1'] }} {{ $arrOrder['shipping']['address2'] }} {{ $arrOrder['shipping']['zip'] }} {{ $arrOrder['shipping']['city'] }}" data-latitude="{{ $arrOrder['shipping']['latitude'] }}" data-longitude="{{ $arrOrder['shipping']['longitude'] }}"><i class="fa-solid fa-location-dot"></i> Show Map</button>
                                    <div class="map_canvas mb-3" style="height: 400px; width: 100%;">
                                        <div id="map{{ $loop->index }}" style="height: 100%; width: 100%;">
                                            <div class="spinner-border spinner-border-sm text-danger loading-spinner" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                    </div>

                                    @if(empty($arrOrder['delivered_at']))
                                    <button type="button" class="btn btn-success col-12 col-sm-8 col-md-6 mx-auto mb-3 fulfillment_button" data-order_id="{{ $nOrderId }}"><i class="fa-solid fa-truck"></i> Mark Order as Delivered</button>
                                    @endif
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
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCF-aYjFnRiPnQaR4bl63rgYtnVvyLyb10&libraries=places"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        function initMap() {
            $(document).on('click', '.fulfillment_button', function() {
                var button = $(this);
                var parent = button.closest('.accordion-item');
                var orderId = parent.data('order_id');
                var data = {
                    orderId: orderId
                };

                $.ajax({
                    url: '/delivery/fulfilled/' + orderId,
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        if (response.success) {
                            parent.addClass('delivered');
                            alert("Order marked as delivered successfully.");
                            button.remove();
                        } else {
                            parent.removeClass('delivered');
                            alert("Failed to mark order as delivered.");
                        }
                    },
                    error: function() {
                        parent.removeClass('delivered');
                        alert("Failed to mark order as delivered.");
                    }
                });
            });

            $(document).on('click', '.map_button', function() {
                var button = $(this);
                var mapCanvas = button.parent().find('.map_canvas');
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
        });

        setInterval(function() {
            window.location.reload();
        }, 60000); // 1 minute in milliseconds
    </script>

@endsection
