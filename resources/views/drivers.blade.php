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
    .bg-success-subtle {
        background-color: #32cd32 !important; /* Changed to lime green */
    }
    button::after {
        position: absolute;
        z-index: 100;
        right: 16px;
    }
    .map_canvas {
        display: none;
    }
    /* Camera styles */
    #camera-container {
        position: relative;
        overflow: hidden;
        max-height: 60vh;
        margin-bottom: 10px;
    }
    .camera-overlay {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 3rem;
        color: rgba(255,255,255,0.3);
        pointer-events: none;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100px;
        height: 100px;
        border-radius: 50%;
        border: 3px solid rgba(255,255,255,0.3);
        opacity: 0;
        transition: opacity 0.3s;
    }
    #camera-container:hover .camera-overlay {
        opacity: 1;
    }
    #camera-preview {
        width: 100%;
        max-width: 100%;
        height: auto;
        margin: 0 auto;
        display: block;
        object-fit: contain;
    }
    #captured-image {
        width: 100%;
        max-width: 100%;
        height: auto;
        margin: 0 auto;
        display: none;
        object-fit: contain;
    }
    .camera-controls {
        margin-top: 15px;
        text-align: center;
        position: relative;
        z-index: 100;
    }
            /* Larger capture buttons for mobile */
        @media (max-width: 768px) {
            #camera-container {
                max-height: 50vh; /* Reduced height to ensure buttons are visible */
            }
            #capture-btn, #retake-btn, #upload-btn {
                font-size: 1.5rem;
                padding: 12px 20px;
                border-radius: 50%;
                width: 70px;
                height: 70px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 10px auto;
                box-shadow: 0 3px 8px rgba(0,0,0,0.3);
                position: relative;
                bottom: 0;
            }
        #capture-btn {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        #capture-btn:active {
            transform: scale(0.95);
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
                    #capture-btn i, #retake-btn i, #upload-btn i {
                font-size: 2rem;
            }
        .camera-controls {
            position: relative;
            margin-top: 10px;
            bottom: auto;
            left: auto;
            right: auto;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
        }
        .modal-dialog-camera {
            display: flex;
            align-items: center;
            min-height: calc(100% - 1rem);
            margin: 0.5rem auto;
        }
        .modal-content {
            display: flex;
            flex-direction: column;
            max-height: 95vh;
        }
        .modal-body {
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
        }
        .modal-footer {
            padding: 0.75rem;
            border-top: 1px solid #dee2e6;
        }
    }
    .camera-resolution {
        display: none; /* Hide camera resolution captions */
    }
    .fulfilled-btn-disabled {
        opacity: 0.5;
        pointer-events: none;
    }
    /* Camera modal adjustments */
    .modal-dialog-camera {
        max-width: 800px;
    }
    /* Fulfillment image thumbnail size for desktop */
    @media (min-width: 768px) {
        .fulfillment-thumbnail {
            max-width: 300px;
            height: auto;
            margin: 0;
            display: block;
        }
    }
    @media (max-width: 850px) {
        .modal-dialog-camera {
            max-width: 95%;
            margin: 1.75rem auto;
        }
        #camera-container {
            max-height: 50vh;
        }
        .modal-body {
            padding: 10px;
        }
        .camera-controls {
            margin-top: 5px;
            position: relative;
            bottom: auto;
            left: auto;
            right: auto;
            z-index: 1050;
        }
        .modal-footer {
            padding: 0.5rem;
        }
    }

    /* Specific adjustments for tall/narrow screens (9:16 aspect ratio) */
    @media (max-width: 768px) and (min-aspect-ratio: 9/16) {
        #camera-container {
            max-height: 55vh;
        }
    }

    @media (max-width: 768px) and (max-aspect-ratio: 9/16) {
        #camera-container {
            max-height: 55vh; /* Increased from 40vh */
        }
        .camera-controls {
            margin-top: 10px;
        }
        #capture-btn, #retake-btn, #upload-btn {
            width: 60px;
            height: 60px;
            margin: 0 5px 5px 5px;
        }
        .camera-overlay {
            font-size: 2rem;
            width: 70px;
            height: 70px;
        }
        .modal-body {
            display: flex;
            flex-direction: column;
            padding-bottom: 5px;
        }
        .modal-footer .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .camera-resolution {
            margin-bottom: 5px;
        }
        /* Add some space below camera container */
        #camera-container {
            margin-bottom: 15px;
        }
    }
</style>
@endsection

@section('content')
<nav class="navbar row navbar-dark bg-dark" style="margin-top: -25px;">
    <div class="container-fluid">
        <!-- Logo on the left -->
        <a class="navbar-brand" href="#">
            <img src="{{ asset('logo.png') }}" alt="Logo" height="40" class="d-inline-block align-text-top">
        </a>
        
        <!-- Title in the center -->
        <div class="mx-auto">
            <h5 class="navbar-text text-white mb-0">{{ $title }}</h5>
        </div>
        
        <!-- Empty div for balance (optional) -->
        <div></div>
    </div>
</nav>
<br>
<div class="container-full p-2">
    <div class="row">
        <div class="col-12">
            <h5>PreOrders & Immediate Inventory</h5>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div id="accordion" class="accordion">
                @foreach($arrData as $location => $arrProducts)
                    <div class="accordion-item">
                        <div class="accordion-header" id="heading{{ $loop->index }}">
                            <h5 class="mb-0 ">
                                <button class="accordion-button {{ $arrProducts['is_fulfilled'] ? 'bg-success-subtle' : 'bg-light' }} d-block text-center fw-bold" data-bs-toggle="collapse" data-bs-target="#collapse{{ $loop->index }}" aria-expanded="false" aria-controls="collapse{{ $loop->index }}">
                                    {{ ($loop->index + 1) . ". " . $location }} <span class="badge bg-primary">{{ $arrTotalOrders[$location]['total_orders_count'] }}</span> <span class="text-white">{{ $arrProducts['location_data']['driver_fulfillment_time'] }}</span>
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
                                        <button
                                            type="button"
                                            class="btn btn-success mark-fulfilled {{ $arrProducts['is_fulfilled'] ? 'fulfilled-btn-disabled' : '' }}"
                                            data-location="{{ $location }}">
                                            <i class="fa-solid fa-truck-fast"></i> {{ $arrProducts['is_fulfilled'] ? 'Fulfilled' : 'Fulfill' }}
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

                                @if(isset($arrProducts['is_fulfilled']) && $arrProducts['is_fulfilled'] && isset($arrProducts['fulfillment_image']))
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6>FULFILLMENT ATTACHED PHOTO</h6>
                                        <img src="{{ $arrProducts['fulfillment_image'] }}" class="img-fluid img-thumbnail fulfillment-thumbnail" alt="FULFILLMENT ATTACHED PHOTO">
                                    </div>
                                </div>
                                @endif

                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<!-- Camera Capture Modal -->
<div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-camera">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cameraModalLabel">Mark as Fulfilled</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="camera-container">
                    <video id="camera-preview" autoplay playsinline></video>
                    <img id="captured-image" alt="Captured Image">
                    <div class="camera-overlay">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                    <input type="hidden" id="location-input">
                    <input type="file" id="file-input" accept="image/*" style="display: none;">
                </div>
                <div class="camera-controls">
                    <button id="capture-btn" class="btn btn-primary">
                        <i class="fa-solid fa-camera"></i> <span class="d-none d-md-inline">Capture</span>
                    </button>
                    <button id="upload-btn" class="btn btn-info">
                        <i class="fa-solid fa-upload"></i> <span class="d-none d-md-inline">Upload</span>
                    </button>
                    <button id="retake-btn" class="btn btn-secondary" style="display:none;">
                        <i class="fa-solid fa-rotate"></i> <span class="d-none d-md-inline">Retake</span>
                    </button>
                </div>
                <div id="submission-message"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" id="submit-image" class="btn btn-success" disabled>
                    <i class="fa-solid fa-paper-plane"></i> <span>Submit</span>
                </button>
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
        var reloadTimer;
        var reloadInterval = 60000; // 1 minute in milliseconds

        function startReloadTimer() {
            if (reloadTimer) clearInterval(reloadTimer);
            reloadTimer = setInterval(function() {
                storeAccordionState();
                window.location.reload();
            }, reloadInterval);
        }

        function pauseReloadTimer() {
            if (reloadTimer) {
                clearInterval(reloadTimer);
                reloadTimer = null;
            }
        }

        // Start the timer initially
        startReloadTimer();

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

                let googleMapsUrl = '';

                // Construct the appropriate URL
                if (latitude && longitude) {
                    // Use latitude & longitude for better accuracy
                    googleMapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${latitude},${longitude}`;
                } else {
                    // Fallback to address
                    const encodedAddress = encodeURIComponent(address.trim());
                    googleMapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${encodedAddress}`;
                }

                // Check if we're on a mobile device
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                
                if (isMobile) {
                    // For mobile devices, try to open in the native app first
                    const androidMapsUrl = latitude && longitude 
                        ? `geo:${latitude},${longitude}?q=${latitude},${longitude}`
                        : `geo:0,0?q=${encodeURIComponent(address.trim())}`;
                    
                    // Create a temporary link to trigger the app
                    const tempLink = document.createElement('a');
                    tempLink.href = androidMapsUrl;
                    tempLink.style.display = 'none';
                    document.body.appendChild(tempLink);
                    tempLink.click();
                    document.body.removeChild(tempLink);
                    
                    // Fallback to web version after a short delay
                    // setTimeout(function() {
                    //     window.open(googleMapsUrl, '_blank');
                    // }, 1000);
                } else {
                    // For desktop, just open the web version
                    window.open(googleMapsUrl, '_blank');
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

        // Camera Handling Code
        let stream = null;
        let cameraStream = null;
        let capturedImageData = null;

        // Initialize camera functionality
        function initCamera() {
            // Mark fulfilled button click handler
            $(document).on('click', '.mark-fulfilled', function() {
                const locationName = $(this).data('location');
                $('#location-input').val(locationName);
                $('#cameraModal').modal('show');
                startCamera();
            });

            // Capture button click handler
            $('#capture-btn').on('click', function() {
                captureImage();
            });

            // Retake button click handler
            $('#retake-btn').on('click', function() {
                retakeImage();
            });

            // Upload button click handler
            $('#upload-btn').on('click', function() {
                $('#file-input').click();
            });

            // File input change handler
            $('#file-input').on('change', function(e) {
                const file = e.target.files[0];
                if (file && file.type.startsWith('image/')) {
                    handleFileUpload(file);
                } else if (file) {
                    $('#submission-message').html('<div class="alert alert-danger">Please select a valid image file.</div>');
                }
            });

            // Submit button click handler
            $('#submit-image').on('click', function() {
                submitImage();
            });

            // Modal show event - pause reload timer
            $('#cameraModal').on('show.bs.modal', function() {
                pauseReloadTimer();
            });

            // Modal close event - cleanup camera resources and resume reload timer
            $('#cameraModal').on('hidden.bs.modal', function() {
                stopCamera();
                resetCameraUI();
                startReloadTimer();
            });
        }

        // Start camera stream
        function startCamera() {
            resetCameraUI(); // Reset UI elements to their initial state

            // Set higher constraints for full HD
            const highResConstraints = {
                video: {
                    facingMode: 'environment',
                    width: { min: 1280, ideal: 1920, max: 3840 },
                    height: { min: 720, ideal: 1080, max: 2160 }
                },
                audio: false
            };

            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                // Try to get the supported constraints
                const supportedConstraints = navigator.mediaDevices.getSupportedConstraints();
                console.log('Supported constraints:', supportedConstraints);

                // Get a list of available video devices
                navigator.mediaDevices.enumerateDevices()
                    .then(devices => {
                        const videoDevices = devices.filter(device => device.kind === 'videoinput');
                        console.log('Available video devices:', videoDevices);

                        // Proceed with camera access
                        return navigator.mediaDevices.getUserMedia(highResConstraints);
                    })
                    .then(function(mediaStream) {
                        cameraStream = mediaStream;
                        const video = document.getElementById('camera-preview');
                        video.srcObject = mediaStream;

                        // Get the actual resolution from the track settings
                        const videoTrack = mediaStream.getVideoTracks()[0];
                        const capabilities = videoTrack.getCapabilities();
                        const settings = videoTrack.getSettings();

                        console.log('Camera capabilities:', capabilities);
                        console.log('Camera settings:', settings);
                        console.log('Camera resolution:', settings.width, 'x', settings.height);

                        // If we got a resolution below 720p, try to apply constraints again
                        if (settings.width < 1280 || settings.height < 720) {
                            console.log('Got lower resolution than expected, trying to apply constraints');

                            // Try to apply constraints again to get higher resolution
                            videoTrack.applyConstraints({
                                width: { min: 1280, ideal: 1920 },
                                height: { min: 720, ideal: 1080 }
                            }).then(() => {
                                // Check new resolution after applying constraints
                                const updatedSettings = videoTrack.getSettings();
                                console.log('Updated camera resolution:', updatedSettings.width, 'x', updatedSettings.height);
                            }).catch(e => {
                                console.log('Could not apply additional constraints:', e);
                            });
                        }

                        video.onloadedmetadata = function(e) {
                            video.play();
                            // Enable capture button only when camera is active
                            $('#capture-btn').prop('disabled', false);
                        };
                    })
                    .catch(function(err) {
                        console.error('Error accessing high-res camera:', err);
                        // Try with lower resolution if high resolution fails

                        const hdConstraints = {
                            video: {
                                facingMode: 'environment',
                                width: { min: 1280, ideal: 1280 },
                                height: { min: 720, ideal: 720 }
                            },
                            audio: false
                        };

                        navigator.mediaDevices.getUserMedia(hdConstraints)
                            .then(function(mediaStream) {
                                cameraStream = mediaStream;
                                const video = document.getElementById('camera-preview');
                                video.srcObject = mediaStream;

                                // Get and display the actual resolution for the fallback camera
                                const videoTrack = mediaStream.getVideoTracks()[0];
                                const settings = videoTrack.getSettings();
                                console.log('Fallback camera resolution:', settings.width, 'x', settings.height);

                                video.onloadedmetadata = function(e) {
                                    video.play();
                                    // Enable capture button only when camera is active
                                    $('#capture-btn').prop('disabled', false);
                                };
                            })
                            .catch(function(fallbackErr) {
                                console.error('Error accessing HD camera:', fallbackErr);
                                // Last resort - try with any resolution

                                const basicConstraints = {
                                    video: { facingMode: 'environment' },
                                    audio: false
                                };

                                navigator.mediaDevices.getUserMedia(basicConstraints)
                                    .then(function(mediaStream) {
                                        cameraStream = mediaStream;
                                        const video = document.getElementById('camera-preview');
                                        video.srcObject = mediaStream;

                                        const videoTrack = mediaStream.getVideoTracks()[0];
                                        const settings = videoTrack.getSettings();
                                        console.log('Basic camera resolution:', settings.width, 'x', settings.height);

                                        video.onloadedmetadata = function(e) {
                                            video.play();
                                            // Enable capture button only when camera is active
                                            $('#capture-btn').prop('disabled', false);
                                        };
                                    })
                                    .catch(function(basicErr) {
                                        $('#submission-message').html('<div class="alert alert-danger">Camera access error. Please allow camera access and try again. Error: ' + basicErr.message + '</div>');
                                        // Disable both capture and submit buttons if camera access fails
                                        $('#capture-btn').prop('disabled', true);
                                        $('#submit-image').prop('disabled', true);
                                    });
                            });
                    });
            } else {
                console.error('getUserMedia not supported');
                $('#submission-message').html('<div class="alert alert-danger">Camera access is not supported by your browser or the page is not loaded in a secure context (HTTPS).</div>');
                // Disable both capture and submit buttons if camera is not supported
                $('#capture-btn').prop('disabled', true);
                $('#submit-image').prop('disabled', true);
            }
        }

        // Stop camera stream
        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => {
                    track.stop();
                });
                cameraStream = null;
            }
        }

        // Capture image from camera
        function captureImage() {
            const video = document.getElementById('camera-preview');

            // Check if camera stream exists and is active
            if (!cameraStream || !cameraStream.active || !video.srcObject) {
                $('#submission-message').html('<div class="alert alert-danger">Camera not available. Please allow camera access and try again.</div>');
                return;
            }

            const canvas = document.createElement('canvas');
            const capturedImage = document.getElementById('captured-image');

            // Get video dimensions
            const videoWidth = video.videoWidth;
            const videoHeight = video.videoHeight;

            console.log('Video dimensions for capture:', videoWidth, 'x', videoHeight);

            // If video dimensions are zero, camera is not ready
            if (videoWidth === 0 || videoHeight === 0) {
                $('#submission-message').html('<div class="alert alert-danger">Camera not ready. Please try again.</div>');
                return;
            }

            // Set canvas to the native resolution of the video
            canvas.width = videoWidth;
            canvas.height = videoHeight;

            // If we have a very low resolution, try to use a larger canvas
            if (videoWidth < 1280 || videoHeight < 720) {
                // Scale up by 1.5x for better quality if device resolution is low
                const scaleFactor = Math.max(1.5, 1280 / videoWidth);
                canvas.width = videoWidth * scaleFactor;
                canvas.height = videoHeight * scaleFactor;

                console.log('Scaling up canvas to:', canvas.width, 'x', canvas.height);
            }

            console.log('Capturing at resolution:', canvas.width, 'x', canvas.height);

            // Draw video frame to canvas - handle scaling if needed
            const ctx = canvas.getContext('2d');
            // Use high quality image rendering
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';

            if (canvas.width > videoWidth) {
                // Scale up the image with interpolation
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            } else {
                // Direct copy at native resolution
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            }

            // Convert canvas to data URL with maximum quality
            capturedImageData = canvas.toDataURL('image/jpeg', 1.0);

            // Verify that we have valid image data
            if (!capturedImageData || capturedImageData === 'data:,') {
                $('#submission-message').html('<div class="alert alert-danger">Failed to capture image. Please try again.</div>');
                return;
            }

            // Display captured image
            capturedImage.src = capturedImageData;
            capturedImage.style.display = 'block';
            video.style.display = 'none';

            // Show retake button and enable submit
            $('#capture-btn').hide();
            $('#upload-btn').hide();
            $('#retake-btn').show();
            $('#submit-image').prop('disabled', false);
        }

        // Retake image
        function retakeImage() {
            resetCameraUI();
            startCamera();
        }

        // Handle file upload
        function handleFileUpload(file) {
            const reader = new FileReader();

            reader.onload = function(e) {
                const capturedImage = document.getElementById('captured-image');
                const video = document.getElementById('camera-preview');

                // Validate the result
                if (!e.target.result || e.target.result === 'data:,') {
                    $('#submission-message').html('<div class="alert alert-danger">Failed to read image file. Please try again.</div>');
                    return;
                }

                // Set the captured image data
                capturedImageData = e.target.result;

                // Display uploaded image
                capturedImage.src = capturedImageData;
                capturedImage.style.display = 'block';
                video.style.display = 'none';

                // Update UI buttons
                $('#capture-btn').hide();
                $('#upload-btn').hide();
                $('#retake-btn').show();
                $('#submit-image').prop('disabled', false);

                // Clear any previous messages
                $('#submission-message').html('');

                // Stop camera stream as we have an image
                stopCamera();
            };

            reader.onerror = function() {
                $('#submission-message').html('<div class="alert alert-danger">Error reading image file. Please try again.</div>');
            };

            reader.readAsDataURL(file);
        }

        // Reset camera UI to initial state
        function resetCameraUI() {
            $('#camera-preview').show();
            $('#captured-image').hide();
            $('#capture-btn').show().prop('disabled', true); // Start with capture button disabled until camera is ready
            $('#upload-btn').show();
            $('#retake-btn').hide();
            $('#submit-image').prop('disabled', true).html('<i class="fa-solid fa-paper-plane"></i> <span>Submit</span>');
            $('#submission-message').html('');
            $('#file-input').val(''); // Clear file input
            capturedImageData = null;
        }

        // Submit the captured image
        function submitImage() {
            if (!capturedImageData || capturedImageData === 'data:,') {
                $('#submission-message').html('<div class="alert alert-danger">No valid image captured. Please capture an image first.</div>');
                return;
            }

            const locationName = $('#location-input').val();
            const store_uuid = "{{ request()->route('uuid') }}";

            // Hide all buttons and show loading state IMMEDIATELY
            $('#capture-btn').hide();
            $('#upload-btn').hide();
            $('#retake-btn').hide();
            $('#submit-image').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Wait...');
            // Clear any previous submission messages
            $('#submission-message').html('');

            // Use a short timeout to allow the UI to update before the synchronous AJAX call
            setTimeout(function() {
                // Send AJAX request
                $.ajax({
                    url: '/drivers',
                    type: 'POST',
                    data: {
                        location: locationName,
                        image: capturedImageData,
                        store_id: store_id,
                        _token: $('meta[name="csrf-token"]').attr('content')
                    },
                    async: false,
                    success: function(response) {
                        // Disable the fulfilled button for this location
                        $('button.mark-fulfilled[data-location="' + locationName + '"]')
                            .addClass('fulfilled-btn-disabled')
                            .html('<i class="fa-solid fa-truck-fast"></i> Fulfilled');

                        // Change the accordion button color
                        $('button.accordion-button')
                            .filter(function() {
                                return $(this).text().indexOf(locationName) !== -1;
                            })
                            .removeClass('bg-light')
                            .addClass('bg-success-subtle');

                        // Add the confirmation image to the location section
                        const accordionId = $('button.accordion-button')
                            .filter(function() {
                                return $(this).text().indexOf(locationName) !== -1;
                            })
                            .attr('data-bs-target');

                        const accordionBody = $(accordionId).find('.accordion-body');

                        // Check if we already have a FULFILLMENT ATTACHED PHOTO section
                        if (accordionBody.find('h6:contains("FULFILLMENT ATTACHED PHOTO")').length === 0 && response.data && response.data.image_url) {
                            accordionBody.append(`
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6>FULFILLMENT ATTACHED PHOTO</h6>
                                        <img src="${response.data.image_url}" class="img-fluid img-thumbnail fulfillment-thumbnail" alt="FULFILLMENT ATTACHED PHOTO">
                                    </div>
                                </div>
                            `);
                        }

                        $('#submission-message').html('<div class="alert alert-success">Location marked as fulfilled successfully!</div>');
                            // Close modal after delay
                        setTimeout(function() {
                            $('#cameraModal').modal('hide');
                        }, 2000);
                    },
                    error: function(xhr) {
                        let errorMessage = 'An error occurred. Please try again.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        // $('#submission-message').html('<div class="alert alert-danger">' + errorMessage + '</div>');
                        $('#submission-message').html('<div class="alert alert-danger">Error</div>');
                        // Re-enable buttons on error
                        $('#retake-btn').show();
                        $('#submit-image').prop('disabled', false).html('<i class="fa-solid fa-paper-plane"></i> Submit');
                    }
                });
            }, 50); // A small delay like 50ms should be enough
        }

        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            initCamera();
            // Restore accordion state after DOM is fully loaded
            restoreAccordionState();
        });
    </script>
@endsection
