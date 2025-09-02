@extends('shopify-app::layouts.default')

@section('styles')
<style>
    .order_counter, .items_counter{cursor: pointer;}
    /* Background colors for products */
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
    .location-5 {
        background-color: #ffe4e1 !important; /* Misty Rose */
    }
    .location-6 {
        background-color: #e6e6fa !important; /* Lavender */
    }
    .location-7 {
        background-color: #fafad2 !important; /* Light Goldenrod Yellow */
    }
    .location-8 {
        background-color: #d3d3d3 !important; /* Light Gray */
    }
    .location-9 {
        background-color: #f0e68c !important; /* Khaki */
    }
    .location-10 {
        background-color: #e0ffff !important; /* Light Cyan */
    }

    .column-day {
        width: 15%;
    }

    .column-qty {
        width: 4%; /* (100% - 15% for Day) / 8 columns */
    }

    .column-product {
        width: 15%;
    }
</style>
@endsection

@section('content')
<div class="container-fluid p-2">
    <div class="row">
        <div class="col-12">
            <h5>Sushi Catering PreOrders</h5>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            @foreach($arrData as $location => $days)
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-vcenter table-condensed">
                        <thead>
                            <tr>
                                <th colspan="100" class="text-center">{{ $location }}</th>
                            </tr>

                            @if(!empty($days['location_data']['address']))
                            <tr>
                                <td colspan="100" class="text-left">
                                    <p class="mb-0">{!! $days['location_data']['address'] !!}</p>
                                </td>
                            </tr>
                            @endif
                        </thead>
                        <tbody>
                            @foreach($dates as $date => $day_name)
                                @php
                                    $dayData = $days[$date] ?? [
                                        'day_name' => $day_name,
                                        'products' => []
                                    ];
                                    $productCount = count($dayData['products']);
                                    $rowsNeeded = ceil($productCount / 4) ?: 1;
                                    $productsArray = array_chunk($dayData['products'], 4, true);
                                    $firstRow = true;

                                    // Map product names to fixed color classes
                                    $productColors = [];
                                    $colorClasses = [
                                        'location-1',
                                        'location-2',
                                        'location-3',
                                        'location-4',
                                        'location-5',
                                        'location-6',
                                        'location-7',
                                        'location-8',
                                        'location-9',
                                        'location-10'
                                    ];
                                    $colorIndex = 0;
                                @endphp

                                @if(empty($dayData['products']))
                                    <tr>
                                        <td class="column-day">{{ $dayData['day_name'] }} ({{ $date }})</td>
                                        @for($i = 0; $i < 4; $i++)
                                            <td class="column-qty"></td>
                                            <td class="column-product"></td>
                                        @endfor
                                    </tr>
                                @else
                                    @foreach($productsArray as $rowProducts)
                                        <tr>
                                            @if($firstRow)
                                                <td class="column-day" rowspan="{{ $rowsNeeded }}">{{ $dayData['day_name'] }} ({{ $date }})</td>
                                                @php $firstRow = false; @endphp
                                            @endif
                                            @foreach($rowProducts as $productName => $quantity)
                                                @php
                                                    // Assign a fixed color class to each product
                                                    if (!isset($productColors[$productName])) {
                                                        $productColors[$productName] = $colorClasses[$colorIndex % count($colorClasses)];
                                                        $colorIndex++;
                                                    }
                                                    $colorClass = $productColors[$productName];
                                                @endphp
                                                <td class="{{ $colorClass }} text-center column-qty">
                                                    {{ $quantity }}
                                                </td>
                                                <td class="{{ $colorClass }} column-product">
                                                    {{ $productName }}
                                                </td>
                                            @endforeach
                                            @for($i = count($rowProducts); $i < 4; $i++)
                                                <td class="column-qty"></td>
                                                <td class="column-product"></td>
                                            @endfor
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <br>
            @endforeach
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-vcenter table-condensed">
                    <thead>
                        <tr>
                            <th colspan="100" class="text-center">Catering-Station Total Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dates as $date => $day_name)
                            @php
                                $productCount = count($arrTotalOrders[$date]['total_orders']['preorder_inventory']);
                                $rowsNeeded = ceil($productCount / 4) ?: 1;
                                $productsArray = array_chunk($arrTotalOrders[$date]['total_orders']['preorder_inventory'], 4, true);
                                $firstRow = true;

                                $immediate_productCount = count($arrTotalOrders[$date]['total_orders']['immediate_inventory']);
                                $immediate_rowsNeeded = ceil($immediate_productCount / 4) ?: 1;
                                $immediate_productsArray = array_chunk($arrTotalOrders[$date]['total_orders']['immediate_inventory'], 4, true);
                                $immediate_firstRow = true;

                                // Map product names to fixed color classes
                                $productColors = [];
                                $colorClasses = [
                                    'location-1',
                                    'location-2',
                                    'location-3',
                                    'location-4',
                                    'location-5',
                                    'location-6',
                                    'location-7',
                                    'location-8',
                                    'location-9',
                                    'location-10'
                                ];
                                $colorIndex = 0;
                            @endphp

                            @if(empty($arrTotalOrders[$date]['total_orders']['immediate_inventory']))
                                <tr>
                                    <td class="column-day">{{ $day_name }} Immediate Orders<br>({{ $date }})</td>
                                    @for($i = 0; $i < 4; $i++)
                                        <td class="column-qty"></td>
                                        <td class="column-product"></td>
                                    @endfor
                                </tr>
                            @else
                                @foreach($immediate_productsArray as $rowProducts)
                                    <tr>
                                        @if($immediate_firstRow)
                                            <td class="column-day" rowspan="{{ $immediate_rowsNeeded }}"><b>{{ $day_name }} Immediate Orders<br>({{ $date }})</b> <span class="badge text-bg-primary text-white">{{ $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] }}</span></td>
                                            @php $immediate_firstRow = false; @endphp
                                        @endif
                                        @foreach($rowProducts as $productName => $quantity)
                                            @php
                                                // Assign a fixed color class to each product
                                                if (!isset($productColors[$productName])) {
                                                    $productColors[$productName] = $colorClasses[$colorIndex % count($colorClasses)];
                                                    $colorIndex++;
                                                }
                                                $colorClass = $productColors[$productName];
                                            @endphp
                                            <td class="{{ $colorClass }} text-center column-qty">
                                                {{ $quantity }}
                                            </td>
                                            <td class="{{ $colorClass }} column-product">
                                                {{ $productName }}
                                            </td>
                                        @endforeach
                                        @for($i = count($rowProducts); $i < 4; $i++)
                                            <td class="column-qty"></td>
                                            <td class="column-product"></td>
                                        @endfor
                                    </tr>
                                @endforeach
                            @endif

                            @if(empty($arrTotalOrders[$date]['total_orders']['preorder_inventory']))
                                <tr>
                                    <td class="column-day">{{ $day_name }} PreOrders<br>({{ $date }})</td>
                                    @for($i = 0; $i < 4; $i++)
                                        <td class="column-qty"></td>
                                        <td class="column-product"></td>
                                    @endfor
                                </tr>
                            @else
                                @foreach($productsArray as $rowProducts)
                                    <tr>
                                        @if($firstRow)
                                            <td class="column-day" rowspan="{{ $rowsNeeded }}"><b>{{ $day_name }} PreOrders<br>({{ $date }})</b> <span class="badge text-bg-primary text-white">{{ $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] }}</span></td>
                                            @php $firstRow = false; @endphp
                                        @endif
                                        @foreach($rowProducts as $productName => $quantity)
                                            @php
                                                // Assign a fixed color class to each product
                                                if (!isset($productColors[$productName])) {
                                                    $productColors[$productName] = $colorClasses[$colorIndex % count($colorClasses)];
                                                    $colorIndex++;
                                                }
                                                $colorClass = $productColors[$productName];
                                            @endphp
                                            <td class="{{ $colorClass }} text-center column-qty">
                                                {{ $quantity }}
                                            </td>
                                            <td class="{{ $colorClass }} column-product">
                                                {{ $productName }}
                                            </td>
                                        @endforeach
                                        @for($i = count($rowProducts); $i < 4; $i++)
                                            <td class="column-qty"></td>
                                            <td class="column-product"></td>
                                        @endfor
                                    </tr>
                                @endforeach
                            @endif

                            <tr>
                                <td colspan="9"></td>
                            </tr>


                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-vcenter table-condensed">
                    <thead>
                        <tr>
                            <th colspan="100" class="text-center">Home Delivery Total Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dates as $date => $day_name)
                            @php
                                $productCount = count($arrTotalDeliveryOrders[$date]['total_orders']);
                                $rowsNeeded = ceil($productCount / 4) ?: 1;
                                $productsArray = array_chunk($arrTotalDeliveryOrders[$date]['total_orders'], 4, true);
                                $firstRow = true;

                                // Map product names to fixed color classes
                                $productColors = [];
                                $colorClasses = [
                                    'location-1',
                                    'location-2',
                                    'location-3',
                                    'location-4',
                                    'location-5',
                                    'location-6',
                                    'location-7',
                                    'location-8',
                                    'location-9',
                                    'location-10'
                                ];
                                $colorIndex = 0;
                            @endphp

                            @if(empty($arrTotalDeliveryOrders[$date]['total_orders']))
                                <tr>
                                    <td class="column-day">{{ $day_name }} ({{ $date }})</td>
                                    @for($i = 0; $i < 4; $i++)
                                        <td class="column-qty"></td>
                                        <td class="column-product"></td>
                                    @endfor
                                </tr>
                            @else
                                @foreach($productsArray as $rowProducts)
                                    <tr>
                                        @if($firstRow)
                                            <td class="column-day" rowspan="{{ $rowsNeeded }}"><b>{{ $day_name }} ({{ $date }})</b> <span class="badge text-bg-primary text-white">{{ $arrTotalDeliveryOrders[$date]['total_orders_count']}}</span></td>
                                            @php $firstRow = false; @endphp
                                        @endif
                                        @foreach($rowProducts as $productName => $quantity)
                                            @php
                                                // Assign a fixed color class to each product
                                                if (!isset($productColors[$productName])) {
                                                    $productColors[$productName] = $colorClasses[$colorIndex % count($colorClasses)];
                                                    $colorIndex++;
                                                }
                                                $colorClass = $productColors[$productName];
                                            @endphp
                                            <td class="{{ $colorClass }} text-center column-qty">
                                                {{ $quantity }}
                                            </td>
                                            <td class="{{ $colorClass }} column-product">
                                                {{ $productName }}
                                            </td>
                                        @endforeach
                                        @for($i = count($rowProducts); $i < 4; $i++)
                                            <td class="column-qty"></td>
                                            <td class="column-product"></td>
                                        @endfor
                                    </tr>
                                @endforeach
                            @endif

                        @endforeach
                    </tbody>
                </table>
            </div>
            <br>
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
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Kitchen'
        var kitchen = Button.create(app, { label: 'Kitchen' });
        kitchen.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/kitchen_admin');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });

        // Create a button for 'Home Delivery Overview'
        var homedeliveryButton = Button.create(app, { label: 'Home Delivery Overview' });
        homedeliveryButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/home_delivery');
            // Add your logic for when the 'Orders' button is clicked
        });

        // Create a button for 'Orders'
        var ordersButton = Button.create(app, { label: 'Location Order Overview' });
        ordersButton.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/orders');
            // Add your logic for when the 'Orders' button is clicked
        });

        // Create a button for 'Store'
        var stores = Button.create(app, { label: 'Stores' });
        stores.subscribe(Button.Action.CLICK, function() {
            var redirect = Redirect.create(app);
            redirect.dispatch(Redirect.Action.APP, '/stores');
            // Add your logic for when the 'Locations Revenue' button is clicked
        });


        // Update the title bar with the new buttons
        var titleBar = TitleBar.create(app, {
            title: 'Kitchen',
            buttons: {
                primary: location_products,
                secondary: [stores, locations_revenue, locations_text, ordersButton, homedeliveryButton]
            },
        });
    </script>

    <script>
        setInterval(function() {
            window.location.reload();
        }, 60000); // 1 minute in milliseconds
    </script>
@endsection
