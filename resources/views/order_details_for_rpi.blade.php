{{--
|--------------------------------------------------------------------------
| RPI Order Details View
|--------------------------------------------------------------------------
|
| Displays existing order-level fulfillment records saved by device ingestion.
| Follows the exact same conditional layout pattern as drivers.blade.php
| and kitchen.blade.php:
|   - ?menu=1 → renders inside Shopify admin shell (AppBridge layout)
|   - no menu → renders standalone with dark navbar (for direct/kiosk access)
|
| Uses jQuery DataTable for pagination, sorting, and search.
| Only the current table page refreshes every 60 seconds, preserving search and page.
|
--}}

@extends(! request()->attributes->get('master_frame') && (int) request('menu') === 1 ? 'shopify-app::layouts.default' : 'layouts.app')


@section('styles')
    @parent
    @if(! request()->attributes->get('master_frame') && (int) request('menu') !== 1)
        {{-- The other two layouts already include the DataTables stylesheet. --}}
        <link rel="stylesheet" href="{{ asset('js/plugins/datatables/dataTables.bootstrap4.css') }}">
    @endif
    <style>
        /* Only the wide table scrolls horizontally. Controls stay at the page
           width, including on narrow phones and inside the Master Admin frame. */
        .rpi-order-details { min-width: 0; max-width: 100%; }
        .rpi-order-details .dataTables_wrapper { width: 100%; min-width: 0; }
        .rpi-order-details .rpi-table-controls { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin: .75rem 0; }
        .rpi-order-details .rpi-table-controls > div { max-width: 100%; }
        .rpi-order-details .dataTables_length label,
        .rpi-order-details .dataTables_filter label { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin: 0; white-space: normal; }
        .rpi-order-details .dataTables_filter input { width: 12rem; max-width: 100%; margin-left: 0; }
        .rpi-order-details .dataTables_wrapper .dataTables_info { white-space: normal; overflow-wrap: anywhere; }
        .rpi-order-details .dataTables_paginate .pagination { flex-wrap: wrap; justify-content: flex-start; }
        .rpi-order-details .page-link { min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
        .rpi-order-details .dataTables_processing { position: static; width: auto; height: auto; margin: .75rem 0; padding: .75rem; text-align: left; }
        .rpi-order-details .rpi-table-scroll { max-width: 100%; }
        .rpi-order-details table { width: 100% !important; min-width: 110rem; table-layout: fixed; }
        .rpi-order-details th,
        .rpi-order-details td { overflow-wrap: anywhere; white-space: normal; vertical-align: top; }
        .rpi-order-details .rpi-value-list { padding-left: 1.1rem; margin-bottom: 0; }
        .rpi-order-details .rpi-value-list li + li { margin-top: .35rem; }
        .rpi-order-details .admin-help-row { flex-wrap: wrap; }
        .rpi-order-details .admin-help-tooltip:focus-visible { outline: 2px solid #0d6efd; outline-offset: 3px; }
    </style>
@endsection

@section('content')

{{-- === SHOPIFY ADMIN MODE (menu=1) === --}}
{{-- Show AppBridge navigation and page heading with action buttons --}}
@if(! request()->attributes->get('master_frame') && (int) request('menu') === 1)
@include('partials.app_nav')

<s-page heading="RPI Order Details">
    @include('partials.app_page_actions', ['primaryAction' => ['label' => 'Location Products', 'path' => '/location_products']])
</s-page>
@endif

{{-- === STANDALONE MODE (no menu param) === --}}
{{-- Show a simple dark navbar with logo and title, for kiosk/direct access --}}
@if(! request()->attributes->get('master_frame') && (int) request('menu') !== 1)
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


{{-- Fetch one page of fulfillments. Each device field stays independent:
     matching positions in separate lists do not identify the same event. --}}
<div class="container-fluid p-2 rpi-order-details">
    <div class="admin-help-row">
        <span class="fw-semibold">Order fulfillment details</span>
        @include('partials.admin_help_tooltip', ['text' => 'Each row shows an order-level fulfillment record updated by the device, not a complete pickup event history. Each reported list is independent; matching list positions do not establish the same event. Missing details are left empty.'])
    </div>
    <div class="admin-help-row">
        <span class="fw-semibold">RPI details table</span>
        @include('partials.admin_help_tooltip', ['text' => 'Search and pagination read existing fulfillment records for the permitted locations. Scroll the table horizontally to see all columns. The table starts with the most recently updated records and refreshes every 60 seconds while preserving your search and page. Image references are reported filenames or URLs; their availability is not verified.'])
    </div>
    <p id="rpi-data-notes" class="small text-muted mb-2">Reported pickup timestamps are shown unchanged; their source timezone is unverified. Door-open units are unverified. Missing details are left empty.</p>
    <div id="rpi-load-error" class="alert alert-warning" role="alert" hidden>
        <span>Unable to load fulfillment records. Please retry.</span>
        <button id="rpi-retry" type="button" class="btn btn-outline-dark btn-sm ms-2">Retry</button>
    </div>
    <div id="rpi-table-region" aria-busy="false">
        <table id="rpi-fulfillments" aria-label="Order fulfillment details" aria-describedby="rpi-data-notes" class="table table-bordered table-striped table-hover table-vcenter table-condensed js-dataTable-full">
            <thead>
                <tr>
                    <th scope="col">Location</th>
                    <th scope="col">Order #</th>
                    <th scope="col">Scheduled pickup date</th>
                    <th scope="col">Fulfillment status</th>
                    <th scope="col">Ordered units — stored order
                        @include('partials.admin_help_tooltip', ['text' => 'Sum of the explicit line-item quantities in the stored Shopify order. These quantities can include removed or refunded units and are not collected quantities. Reported items are not used to calculate this value.'])
                    </th>
                    <th scope="col">Reported pickup timestamps</th>
                    <th scope="col">Reported door-open values</th>
                    <th scope="col">Before image references</th>
                    <th scope="col">After image references</th>
                    <th scope="col">Reported items</th>
                    <th scope="col">Recorded (UTC)</th>
                    <th scope="col">Last updated (UTC)</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
    @parent
    @if(request()->attributes->get('master_frame') || (int) request('menu') !== 1)
        {{-- The standalone layout has no DataTables bundle. Both direct access
             and Master Admin reuse the same assets as the Shopify layout. --}}
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="{{ asset('js/plugins/datatables/jquery.dataTables.min.js') }}"></script>
        <script src="{{ asset('js/plugins/datatables/dataTables.bootstrap4.min.js') }}"></script>
    @endif
    @include('partials.app_navigation')

    <script type="text/javascript">
        $(function () {
            const errorNotice = document.getElementById('rpi-load-error');
            const tableRegion = document.getElementById('rpi-table-region');
            let activeRequest = null;
            let requestSequence = 0;

            // Insert device values as text, never as markup or image URLs.
            // Explicit empty checks preserve valid values such as numeric zero.
            function renderScalar(value, type) {
                const text = value === null || value === undefined || value === '' ? '' : String(value);
                if (type !== 'display') {
                    return text;
                }
                const element = document.createElement('span');
                element.textContent = text;
                return element.outerHTML;
            }

            function renderList(values, type) {
                if (!Array.isArray(values) || values.length === 0) {
                    return renderScalar(null, type);
                }
                if (type !== 'display') {
                    return values.join(' ');
                }
                const list = document.createElement('ul');
                list.className = 'rpi-value-list';
                values.forEach(function (value) {
                    const item = document.createElement('li');
                    item.textContent = String(value);
                    list.appendChild(item);
                });
                return list.outerHTML;
            }

            const table = jQuery('#rpi-fulfillments').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: false,
                searchDelay: 350,
                pageLength: 10,
                lengthMenu: [[5, 10, 20, 50], [5, 10, 20, 50]],
                order: [[11, 'desc']],
                orderMulti: false,
                dom: '<"rpi-table-controls"lf>r<"table-responsive rpi-table-scroll"t><"rpi-table-controls"ip>',
                language: {
                    processing: '<span role="status" aria-live="polite">Loading fulfillment records…</span>',
                    emptyTable: 'No fulfillment records to display.',
                    zeroRecords: 'No matching fulfillment records.'
                },
                columns: [
                    { data: 'location', render: renderScalar },
                    { data: 'order_number', render: renderScalar },
                    { data: 'scheduled_pickup_date', render: renderScalar, orderable: false },
                    { data: 'status', render: renderList, orderable: false },
                    { data: 'ordered_units', render: renderScalar, orderable: false },
                    { data: 'pickup_times', render: renderList, orderable: false },
                    { data: 'door_open_values', render: renderList, orderable: false },
                    { data: 'before_images', render: renderList, orderable: false },
                    { data: 'after_images', render: renderList, orderable: false },
                    { data: 'reported_items', render: renderList, orderable: false },
                    { data: 'recorded_at', render: renderScalar },
                    { data: 'updated_at', render: renderScalar }
                ],
                ajax: function (parameters, callback) {
                    const sequence = ++requestSequence;
                    // A newer search or page supersedes a slower previous request.
                    // Ignore its result so stale errors cannot replace current data.
                    if (activeRequest) {
                        activeRequest.abort();
                    }
                    errorNotice.hidden = true;
                    tableRegion.setAttribute('aria-busy', 'true');
                    activeRequest = jQuery.ajax({
                        // Keep UUID and all page flags, especially master_frame,
                        // so refreshes receive the same access checks as the page.
                        url: window.location.pathname + window.location.search,
                        type: 'GET',
                        dataType: 'json',
                        data: parameters
                    }).done(function (response) {
                        if (sequence === requestSequence) {
                            callback(response);
                        }
                    }).fail(function () {
                        if (sequence !== requestSequence) {
                            return;
                        }
                        // Finish DataTables' loading state without an alert popup.
                        // Never display server response text, which may be sensitive.
                        callback({ draw: parameters.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                        errorNotice.hidden = false;
                    }).always(function () {
                        if (sequence === requestSequence) {
                            activeRequest = null;
                            tableRegion.setAttribute('aria-busy', 'false');
                        }
                    });
                }
            });

            document.getElementById('rpi-retry').addEventListener('click', function () {
                table.ajax.reload(null, false);
            });

            // Refresh rows only: false preserves the current page and search.
            const refreshTimer = window.setInterval(function () {
                if (!activeRequest) {
                    table.ajax.reload(null, false);
                }
            }, 60000);
            window.addEventListener('pagehide', function () {
                window.clearInterval(refreshTimer);
            });
        });
    </script>
@endsection
