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
        .rpi-order-details .rpi-image-reference { min-height: 44px; max-width: 100%; padding: .25rem 0; text-align: left; white-space: normal; overflow-wrap: anywhere; }
        .rpi-order-details .rpi-image-dialog { width: min(56rem, calc(100vw - 2rem)); max-width: calc(100vw - 2rem); max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); padding: 1rem; border: 1px solid #6c757d; border-radius: .5rem; overflow: auto; }
        .rpi-order-details .rpi-image-dialog::backdrop { background: rgba(0, 0, 0, .65); }
        .rpi-order-details .rpi-image-dialog-header { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: 1rem; }
        .rpi-order-details .rpi-image-dialog-header h2 { font-size: 1.25rem; margin: 0; }
        .rpi-order-details .rpi-image-dialog-close { min-width: 44px; min-height: 44px; }
        .rpi-order-details .rpi-image-dialog img { max-width: 100%; max-height: 65vh; object-fit: contain; }
        .rpi-order-details .rpi-image-caption { overflow-wrap: anywhere; }
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
        @include('partials.admin_help_tooltip', ['text' => 'Search and pagination read existing fulfillment records for the permitted locations. Scroll the table horizontally to see all columns. The table starts with the latest scheduled pickup date, then the highest order number, and refreshes every 60 seconds while preserving your search and page. Linked order numbers open Shopify in a new tab. Click an image reference to preview it here. Supported public Laravel storage paths and HTTP or HTTPS URLs load only when clicked; the original reference stays visible. Unsupported references cannot be previewed, and image availability is not verified.'])
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
    {{-- Native dialog supplies modal focus handling and Escape-to-close. There
         is deliberately no image src until the admin clicks a reference. --}}
    <dialog id="rpi-image-dialog" class="rpi-image-dialog" aria-labelledby="rpi-image-title" aria-describedby="rpi-image-caption">
        <div class="rpi-image-dialog-header">
            <h2 id="rpi-image-title">Image preview</h2>
            <button id="rpi-image-close" type="button" class="btn btn-outline-secondary rpi-image-dialog-close" autofocus>Close</button>
        </div>
        <p id="rpi-image-status" role="status" aria-live="polite"></p>
        <div class="text-center">
            <img id="rpi-image-preview" alt="Reported image preview" referrerpolicy="no-referrer" hidden>
        </div>
        <p id="rpi-image-caption" class="rpi-image-caption small mt-3 mb-0"></p>
    </dialog>
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
            const imageDialog = document.getElementById('rpi-image-dialog');
            const imageTitle = document.getElementById('rpi-image-title');
            const imageCaption = document.getElementById('rpi-image-caption');
            const imageStatus = document.getElementById('rpi-image-status');
            const imagePreview = document.getElementById('rpi-image-preview');
            const imageClose = document.getElementById('rpi-image-close');
            let activeRequest = null;
            let requestSequence = 0;
            let imageSequence = 0;
            let imageTrigger = null;

            // Insert device values as text, never as markup.
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

            // Accept an explicit web URL only. A filename is not enough to know
            // its storage location; never guess a URL or accept executable schemes.
            function webUrl(value) {
                if (typeof value !== 'string' || !/^https?:\/\//i.test(value) || /[\\\u0000-\u0020]/.test(value)) {
                    return null;
                }
                try {
                    const url = new URL(value);
                    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
                } catch (error) {
                    return null;
                }
            }

            // The server resolves storage references using Laravel's public disk.
            // Only resolve its single-slash paths against this origin; do not
            // invent a storage directory or turn raw filenames into requests.
            function previewUrl(value) {
                if (typeof value !== 'string' || /[\\\u0000-\u0020]/.test(value)) {
                    return null;
                }
                if (value.startsWith('/') && !value.startsWith('//')) {
                    try {
                        const url = new URL(value, window.location.origin);
                        return url.origin === window.location.origin ? url.href : null;
                    } catch (error) {
                        return null;
                    }
                }
                return webUrl(value);
            }

            function renderOrder(value, type, row) {
                const url = webUrl(row.shopify_order_url);
                if (type !== 'display' || !url || value === null || value === undefined || value === '') {
                    return renderScalar(value, type);
                }
                const link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = String(value);
                link.setAttribute('aria-label', 'Order ' + String(value) + ' (opens Shopify in a new tab)');
                return link.outerHTML;
            }

            function renderList(values, type, imageLabel, imageUrls) {
                if (!Array.isArray(values) || values.length === 0) {
                    return renderScalar(null, type);
                }
                if (type !== 'display') {
                    return values.join(' ');
                }
                const list = document.createElement('ul');
                list.className = 'rpi-value-list';
                values.forEach(function (value, index) {
                    const item = document.createElement('li');
                    if (typeof imageLabel === 'string') {
                        // Keep references as button text and data, not image src.
                        // This preserves each independent list without prefetching.
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'btn btn-link rpi-image-reference';
                        button.textContent = String(value);
                        button.dataset.imageReference = String(value);
                        // URL positions map to references within this one field.
                        // Before/after lists remain independent, not paired events.
                        button.dataset.imageUrl = Array.isArray(imageUrls) ? (previewUrl(imageUrls[index]) || '') : '';
                        button.dataset.imageLabel = imageLabel;
                        button.setAttribute('aria-haspopup', 'dialog');
                        item.appendChild(button);
                    } else {
                        item.textContent = String(value);
                    }
                    list.appendChild(item);
                });
                return list.outerHTML;
            }

            function resetImagePreview() {
                // Invalidate an earlier load before removing its source. A slow
                // response must not update a closed dialog or a newer preview.
                imageSequence++;
                imagePreview.onload = null;
                imagePreview.onerror = null;
                imagePreview.removeAttribute('src');
                imagePreview.hidden = true;
                imageStatus.hidden = false;
                imageStatus.textContent = '';
                imageDialog.setAttribute('aria-busy', 'false');
            }

            tableRegion.addEventListener('click', function (event) {
                const button = event.target.closest('.rpi-image-reference');
                if (!button) {
                    return;
                }
                resetImagePreview();
                imageTrigger = button;
                const reference = button.dataset.imageReference;
                imageTitle.textContent = button.dataset.imageLabel + ' image preview';
                imageCaption.textContent = reference;
                imageDialog.showModal();
                imageClose.focus();

                const url = previewUrl(button.dataset.imageUrl);
                if (!url) {
                    imageStatus.textContent = 'Preview unavailable: this reference is not a supported public image path or URL.';
                    return;
                }

                const sequence = imageSequence;
                imageStatus.textContent = 'Loading image…';
                imageDialog.setAttribute('aria-busy', 'true');
                imagePreview.onload = function () {
                    if (sequence === imageSequence && imageDialog.open) {
                        imagePreview.hidden = false;
                        imageStatus.hidden = true;
                        imageDialog.setAttribute('aria-busy', 'false');
                    }
                };
                imagePreview.onerror = function () {
                    if (sequence === imageSequence && imageDialog.open) {
                        imageStatus.textContent = 'Unable to load this image. The reference may be unavailable.';
                        imageDialog.setAttribute('aria-busy', 'false');
                    }
                };
                // Loading starts only after the click and validation. Do not
                // disclose the admin page URL to the image host as a referrer.
                imagePreview.referrerPolicy = 'no-referrer';
                imagePreview.src = url;
            });

            imageClose.addEventListener('click', function () {
                imageDialog.close();
            });
            imageDialog.addEventListener('click', function (event) {
                const bounds = imageDialog.getBoundingClientRect();
                if (event.target === imageDialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) {
                    imageDialog.close();
                }
            });
            imageDialog.addEventListener('close', function () {
                resetImagePreview();
                // A refresh already in flight may have replaced the original row.
                // In that case, return focus to the table search instead.
                const focusTarget = imageTrigger && imageTrigger.isConnected ? imageTrigger : document.querySelector('#rpi-fulfillments_filter input');
                if (focusTarget) {
                    focusTarget.focus();
                }
                imageTrigger = null;
            });

            const table = jQuery('#rpi-fulfillments').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: false,
                searchDelay: 350,
                pageLength: 10,
                lengthMenu: [[5, 10, 20, 50], [5, 10, 20, 50]],
                order: [[2, 'desc'], [1, 'desc']],
                orderMulti: false,
                dom: '<"rpi-table-controls"lf>r<"table-responsive rpi-table-scroll"t><"rpi-table-controls"ip>',
                language: {
                    processing: '<span role="status" aria-live="polite">Loading fulfillment records…</span>',
                    emptyTable: 'No fulfillment records to display.',
                    zeroRecords: 'No matching fulfillment records.'
                },
                columns: [
                    { data: 'location', render: renderScalar },
                    { data: 'order_number', render: renderOrder },
                    { data: 'scheduled_pickup_date', render: renderScalar },
                    { data: 'status', render: renderList, orderable: false },
                    { data: 'ordered_units', render: renderScalar, orderable: false },
                    { data: 'pickup_times', render: renderList, orderable: false },
                    { data: 'door_open_values', render: renderList, orderable: false },
                    { data: 'before_images', render: function (values, type, row) { return renderList(values, type, 'Before', row.before_image_urls); }, orderable: false },
                    { data: 'after_images', render: function (values, type, row) { return renderList(values, type, 'After', row.after_image_urls); }, orderable: false },
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
                if (!activeRequest && !imageDialog.open) {
                    table.ajax.reload(null, false);
                }
            }, 60000);
            window.addEventListener('pagehide', function () {
                window.clearInterval(refreshTimer);
            });
        });
    </script>
@endsection
