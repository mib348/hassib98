{{--
|--------------------------------------------------------------------------
| RPI Order Details View
|--------------------------------------------------------------------------
|
| Displays order-detail records submitted by Raspberry Pi devices.
| Follows the exact same conditional layout pattern as drivers.blade.php
| and kitchen.blade.php:
|   - ?menu=1 → renders inside Shopify admin shell (AppBridge layout)
|   - no menu → renders standalone with dark navbar (for direct/kiosk access)
|
| Uses jQuery DataTable for pagination, sorting, and search.
| Page auto-reloads every 60 seconds to show latest RPI submissions.
|
--}}

@extends((int) request('menu') === 1 ? 'shopify-app::layouts.default' : 'layouts.app')

@section('content')

{{-- === SHOPIFY ADMIN MODE (menu=1) === --}}
{{-- Show AppBridge navigation and page heading with action buttons --}}
@if((int) request('menu') === 1)
@include('partials.app_nav')

<s-page heading="RPI Order Details">
    {{-- Primary action: quick link to Location Products --}}
    <s-button slot="primary-action" onclick="navigateToPage('/location_products')">Location Products</s-button>

    {{-- Secondary actions: navigation to other admin pages --}}
    <s-button slot="secondary-actions" onclick="navigateToPage('/stores')">Stores</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/kitchen/ADMIN?menu=1')">Kitchen</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/drivers/ADMIN?menu=1')">Drivers</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_revenue')">Locations Revenue</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/locations_text')">Location Settings</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/orders')">Location Order Overview</s-button>
    <s-button slot="secondary-actions" onclick="navigateToPage('/home_delivery')">Home Delivery Overview</s-button>
</s-page>
@endif

{{-- === STANDALONE MODE (no menu param) === --}}
{{-- Show a simple dark navbar with logo and title, for kiosk/direct access --}}
@if((int) request('menu') !== 1)
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

        <!-- Empty div for balance -->
        <div></div>
    </div>
</nav>
<br>
@endif

{{-- === DATA TABLE === --}}
{{-- Uses js-dataTable-full class so jQuery DataTable picks it up --}}
{{-- Same pattern as orders.blade.php for pagination, sorting, and search --}}
<div class="container-fluid p-2">
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
            <thead>
                <tr>
                    <th>Id</th>
                    <th>Location</th>
                    <th>Order #</th>
                    <th>Order date</th>
                    <th>Order quantity</th>
                    <th>Pickup datetime</th>
                    <th>Door open time</th>
                    <th>Pre Image</th>
                    <th>Post Image</th>
                </tr>
            </thead>
            <tbody>
                {{-- Loop through each RPI pickup record and render a table row --}}
                @foreach($rpiPickups as $pickup)
                <tr>
                    <td>{{ $pickup->id }}</td>
                    <td>{{ $pickup->order_location }}</td>
                    <td>{{ $pickup->order_number }}</td>
                    {{-- Format current_date (when RPI recorded the event) --}}
                    <td>{{ $pickup->current_date ? $pickup->current_date->format('Y-m-d H:i:s') : '' }}</td>
                    <td>{{ $pickup->order_quantity }}</td>
                    {{-- Format pickup_date (scheduled pickup time) --}}
                    <td>{{ $pickup->pickup_date ? $pickup->pickup_date->format('Y-m-d H:i:s') : '' }}</td>
                    {{-- Door open time in seconds --}}
                    <td>{{ $pickup->open_time }}</td>
                    {{-- Camera image filenames (pre and post pickup) --}}
                    <td>{{ $pickup->pre_img_name }}</td>
                    <td>{{ $pickup->post_img_name }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
    @parent
    {{-- Navigation helper function for Shopify embed context --}}
    @include('partials.app_navigation')

    {{-- Initialize DataTable with pagination, sorting, and search --}}
    {{-- Same pattern as orders.blade.php --}}
    <script type="text/javascript">
        $(function(){
            jQuery('.js-dataTable-full').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 20, 50], [5, 10, 20, 50]],
                order: [[0, 'desc']]  // Sort by Id column descending (newest first)
            });
        });
    </script>

    {{-- Auto-reload every 60 seconds to show latest RPI submissions --}}
    {{-- Same pattern used by kitchen.blade.php and drivers.blade.php --}}
    <script>
        setInterval(function() {
            window.location.reload();
        }, 60000);
    </script>
@endsection
